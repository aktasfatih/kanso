// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Board purge: deleting a board must eventually delete it.
 *
 * A board delete writes a `deleted_at` tombstone and takes the board out of
 * every read path; the PurgeDeletedBoards cron reaps the rows and the
 * attachment bytes once the 30-day retention window has passed. Nothing in the
 * unit suite can prove that, because the schema declares no foreign keys and
 * the cascade is therefore a hand-written list of tables — the failure mode is
 * "one table quietly missing from the list", which only a real database shows.
 *
 * So this spec seeds a board with as much hanging off it as the API allows,
 * backdates its tombstone, runs the job, and then scans the LIVE SCHEMA for
 * leftovers. The scan enumerates oc_kanso_* tables out of information_schema
 * rather than out of the app's own registry, deliberately: a check driven by
 * the same list the purge is driven by could never catch a missing entry.
 */
import { execSync } from 'node:child_process'
import { test, expect, api, API, currentAuth, me } from './helpers.js'

const DB = process.env.KANSO_DB_CONTAINER || 'kanso-dev-db'
const APP = process.env.KANSO_APP_CONTAINER || 'kanso-dev'
const THIRTY_ONE_DAYS = 31 * 24 * 3600

/** One-shot psql query, returning rows split on psql's unaligned `|` separator. */
function sql(query) {
	return execSync(
		`docker exec ${DB} psql -U nextcloud -d nextcloud -tA -c ${JSON.stringify(query)}`,
		{ encoding: 'utf8' },
	)
		.split('\n')
		.map((line) => line.trim())
		.filter(Boolean)
		.map((line) => line.split('|'))
}

function sqlValue(query) {
	const rows = sql(query)
	return rows.length > 0 ? rows[0][0] : ''
}

/** Runs a Kanso background job to completion, by class name. */
function runJob(cls) {
	const list = execSync(
		`docker exec -u www-data ${APP} php occ background-job:list --output=json --limit=100000`,
		{ encoding: 'utf8' },
	)
	const job = JSON.parse(list).find((j) => j.class === cls)
	if (!job) throw new Error(`${cls} is not registered as a background job`)
	execSync(
		`docker exec -u www-data ${APP} php occ background-job:execute ${job.id} --force-execute`,
		{ encoding: 'utf8', stdio: 'pipe' },
	)
}

async function upload(cardId, filename, content) {
	const form = new FormData()
	form.append('file', new Blob([content], { type: 'text/plain' }), filename)
	const r = await fetch(API + `/cards/${cardId}/attachments`, {
		method: 'POST',
		headers: { 'OCS-APIREQUEST': 'true', Authorization: currentAuth },
		body: form,
	})
	if (!r.ok) throw new Error(`attachment upload → ${r.status}: ${await r.text()}`)
	return r.json()
}

/**
 * Every oc_kanso_* table in the live schema, mapped to the columns it has that
 * can point back at a purged board. A table with none of them (the per-user
 * board folders, the cross-board projects) cannot hold a board's rows and is
 * skipped.
 */
const BOARD_LINKS = [
	'board_id',
	'card_id',
	'other_card_id',
	'template_card_id',
	'comment_id',
	'change_id',
	'intake_id',
]

function schemaLinks() {
	const rows = sql(
		"SELECT table_name, column_name FROM information_schema.columns "
		+ "WHERE table_schema = 'public' AND table_name LIKE 'oc\\_kanso\\_%' "
		+ `AND column_name IN (${BOARD_LINKS.map((c) => `'${c}'`).join(', ')})`,
	)
	const byTable = new Map()
	for (const [table, column] of rows) {
		if (!byTable.has(table)) byTable.set(table, [])
		byTable.get(table).push(column)
	}
	return byTable
}

// Serial: the phases are one story (seed → delete → wait out retention → reap),
// each depending on the last, and a retry has to replay the whole story rather
// than re-run one phase against a board the earlier phases already consumed.
test.describe.configure({ mode: 'serial' })

test.describe('Board purge (deleted boards are actually deleted)', () => {
	const seeded = {
		boardId: 0,
		cardIds: [],
		commentIds: [],
		changeIds: [],
		intakeIds: [],
		attachmentCardIds: [],
	}
	// The board that must come through the purge completely untouched. Half of
	// what could go wrong on a destructive path is over-deletion, and only a
	// neighbour can show it.
	const neighbour = { boardId: 0, cardId: 0, commentId: 0 }

	test.beforeAll(async () => {
		const otherBoard = await api.post('/boards', { title: `Purge E2E neighbour ${Date.now()}` })
		neighbour.boardId = otherBoard.id
		const otherStack = await api.post('/stacks', { boardId: otherBoard.id, title: 'Keep' })
		const otherCard = await api.post('/cards', { stackId: otherStack.id, title: 'Untouched' })
		neighbour.cardId = otherCard.id
		const otherLabel = await api.post('/labels', { boardId: otherBoard.id, title: 'keep', color: '00ff00' })
		await api.put(`/cards/${otherCard.id}/labels/${otherLabel.id}`)
		const otherComment = await api.post(`/cards/${otherCard.id}/comments`, { body: 'still here' })
		neighbour.commentId = otherComment.id
		await api.put(`/comments/${otherComment.id}/reactions/${encodeURIComponent('🎉')}`)
		await upload(otherCard.id, 'keep.txt', 'bytes that must survive')

		const board = await api.post('/boards', { title: `Purge E2E ${Date.now()}` })
		seeded.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Doing' })
		const cardA = await api.post('/cards', { stackId: stack.id, title: 'Card A' })
		const cardB = await api.post('/cards', { stackId: stack.id, title: 'Card B' })
		seeded.cardIds = [cardA.id, cardB.id]

		// Hang one of everything the API can reach off the board, so the orphan
		// scan below has something to find in each table if the cascade misses it.
		const label = await api.post('/labels', { boardId: board.id, title: 'urgent', color: 'ff0000' })
		await api.put(`/cards/${cardA.id}/labels/${label.id}`)
		await api.put(`/cards/${cardA.id}/assignees/${me}`)
		await api.post(`/cards/${cardA.id}/checklist`, { title: 'a step' })
		const comment = await api.post(`/cards/${cardA.id}/comments`, { body: 'a comment' })
		seeded.commentIds = [comment.id]
		await api.put(`/comments/${comment.id}/reactions/${encodeURIComponent('👍')}`)
		await api.post(`/cards/${cardA.id}/time-entries`, { seconds: 600, note: 'work' })
		const field = await api.post('/card-fields', { boardId: board.id, name: 'Team', type: 'text' })
		await api.put(`/cards/${cardA.id}/fields/${field.id}`, { value: 'platform' })
		await api.post(`/cards/${cardA.id}/relations`, { otherCardId: cardB.id, kind: 'relates' })
		await api.put(`/cards/${cardA.id}/subscription`)
		await api.put(`/boards/${board.id}/subscription`)
		await api.put(`/boards/${board.id}/pin`)
		const reviewType = await api.post('/review-types', { boardId: board.id, title: 'QA' })
		await api.put(`/cards/${cardA.id}/reviews/${me}`, { reviewTypeId: reviewType.id })
		await api.post(`/cards/${cardA.id}/reminders`, { remindAt: Math.floor(Date.now() / 1000) + 86400 })
		await upload(cardA.id, 'notes.txt', 'bytes that must not survive')
		seeded.attachmentCardIds = [cardA.id]

		// A mail intake plus a seen-message marker. kanso_mail_seen is the one
		// grandchild with NO independent link column — no board_id, no card_id —
		// so without this it would be silently skipped by the scan below, which
		// is exactly the shape of table the purge is most likely to forget.
		// The marker has no API, so it goes in as a fixture row.
		await api.put(`/boards/${board.id}/mail-intake`, {
			stackId: stack.id,
			host: 'imap.invalid',
			port: 993,
			encryption: 'ssl',
			username: 'purge-e2e',
			password: 'not-used',
			mailbox: 'INBOX',
			enabled: false,
		})
		seeded.intakeIds = sql(
			`SELECT id FROM oc_kanso_mail_intake WHERE board_id = ${board.id}`,
		).map(([id]) => Number(id))
		for (const intakeId of seeded.intakeIds) {
			sql(
				'INSERT INTO oc_kanso_mail_seen (intake_id, dedupe_key, created_at) '
				+ `VALUES (${intakeId}, 'purge-e2e-${Date.now()}', ${Math.floor(Date.now() / 1000)})`,
			)
		}

		// Snapshot the ids the grandchild tables hang off: after the purge they
		// cannot be derived any more (their parents are gone), so a reaction or
		// change detail left behind would be invisible to a board_id scan.
		seeded.changeIds = sql(
			`SELECT id FROM oc_kanso_changes WHERE board_id = ${board.id}`,
		).map(([id]) => Number(id))
	})

	test.afterAll(async () => {
		if (neighbour.boardId) await api.raw('DELETE', `/boards/${neighbour.boardId}`)
		// The purge normally removes the board. If a failure left it, drop the row
		// outright rather than re-arming its tombstone: findPurgeableIds orders by
		// deleted_at ASC and the job takes only MAX_BOARDS_PER_RUN, so a pile of
		// leftovers backdated to the epoch would sort ahead of the next run's own
		// board and starve this spec into a permanent, misleading failure.
		if (!seeded.boardId) return
		const stillThere = sqlValue(`SELECT count(*) FROM oc_kanso_boards WHERE id = ${seeded.boardId}`)
		if (stillThere !== '0') {
			sql(`DELETE FROM oc_kanso_boards WHERE id = ${seeded.boardId}`)
		}
	})

	test('seeding actually populated the board-scoped tables', () => {
		// Guards the whole spec against vacuity: if the seed silently wrote
		// nothing, the orphan scan below would pass on an empty database.
		expect(seeded.commentIds.length).toBeGreaterThan(0)
		expect(seeded.changeIds.length).toBeGreaterThan(0)
		expect(seeded.intakeIds.length).toBeGreaterThan(0)
		const cards = seeded.cardIds.join(', ')
		for (const [table, where] of [
			['oc_kanso_stacks', `board_id = ${seeded.boardId}`],
			['oc_kanso_labels', `board_id = ${seeded.boardId}`],
			['oc_kanso_card_labels', `card_id IN (${cards})`],
			['oc_kanso_card_assignees', `card_id IN (${cards})`],
			['oc_kanso_checklist_items', `card_id IN (${cards})`],
			['oc_kanso_comments', `card_id IN (${cards})`],
			['oc_kanso_comment_reactions', `comment_id IN (${seeded.commentIds.join(', ')})`],
			['oc_kanso_card_attachments', `card_id IN (${cards})`],
			['oc_kanso_card_time_entries', `card_id IN (${cards})`],
			['oc_kanso_card_fields', `board_id = ${seeded.boardId}`],
			['oc_kanso_card_field_values', `card_id IN (${cards})`],
			['oc_kanso_card_relations', `card_id IN (${cards})`],
			['oc_kanso_subscriptions', `card_id IN (${cards})`],
			['oc_kanso_board_subscriptions', `board_id = ${seeded.boardId}`],
			['oc_kanso_board_pins', `board_id = ${seeded.boardId}`],
			['oc_kanso_review_types', `board_id = ${seeded.boardId}`],
			['oc_kanso_card_reviews', `card_id IN (${cards})`],
			['oc_kanso_reminders', `card_id IN (${cards})`],
			['oc_kanso_change_details', `change_id IN (${seeded.changeIds.join(', ')})`],
			['oc_kanso_mail_intake', `board_id = ${seeded.boardId}`],
			['oc_kanso_mail_seen', `intake_id IN (${seeded.intakeIds.join(', ')})`],
		]) {
			expect(
				Number(sqlValue(`SELECT count(*) FROM ${table} WHERE ${where}`)),
				`${table} should have been seeded`,
			).toBeGreaterThan(0)
		}
	})

	test('the attachment bytes are on disk before the purge', () => {
		const cardId = seeded.attachmentCardIds[0]
		const found = execSync(
			`docker exec ${APP} find /var/www/html/data -type d -name 'card-${cardId}' -path '*kanso*' || true`,
			{ encoding: 'utf8' },
		).trim()
		expect(found, 'the uploaded attachment should have an app-data folder').not.toBe('')
	})

	test('a board still inside the retention window is NOT purged', async () => {
		await api.delete(`/boards/${seeded.boardId}`)
		expect(
			sqlValue(`SELECT count(*) FROM oc_kanso_boards WHERE id = ${seeded.boardId} AND deleted_at > 0`),
		).toBe('1')

		runJob('OCA\\Kanso\\Cron\\PurgeDeletedBoards')

		// Freshly deleted: the 30-day window has to protect it.
		expect(
			Number(sqlValue(`SELECT count(*) FROM oc_kanso_boards WHERE id = ${seeded.boardId}`)),
			'a board deleted seconds ago must survive the reaper',
		).toBe(1)
	})

	test('past the retention window every row and every byte is gone', () => {
		const cardId = seeded.attachmentCardIds[0]
		const cutoff = Math.floor(Date.now() / 1000) - THIRTY_ONE_DAYS
		sql(`UPDATE oc_kanso_boards SET deleted_at = ${cutoff} WHERE id = ${seeded.boardId}`)

		runJob('OCA\\Kanso\\Cron\\PurgeDeletedBoards')

		expect(
			sqlValue(`SELECT count(*) FROM oc_kanso_boards WHERE id = ${seeded.boardId}`),
			'the board row itself must be gone',
		).toBe('0')
		expect(
			sqlValue(`SELECT count(*) FROM oc_kanso_cards WHERE board_id = ${seeded.boardId}`),
			'the cards must be gone',
		).toBe('0')

		// THE orphan check. Every table in the live schema that can point back at
		// this board, scanned independently of the app's cascade registry — a
		// table missing from that registry shows up here as surviving rows.
		const ids = {
			board_id: [seeded.boardId],
			card_id: seeded.cardIds,
			other_card_id: seeded.cardIds,
			template_card_id: seeded.cardIds,
			comment_id: seeded.commentIds,
			change_id: seeded.changeIds,
			intake_id: seeded.intakeIds,
		}
		const orphans = []
		for (const [table, columns] of schemaLinks()) {
			if (table === 'oc_kanso_boards') continue
			const predicates = columns
				.filter((column) => ids[column].length > 0)
				.map((column) => `${column} IN (${ids[column].join(', ')})`)
			if (predicates.length === 0) continue
			const left = Number(
				sqlValue(`SELECT count(*) FROM ${table} WHERE ${predicates.join(' OR ')}`),
			)
			if (left > 0) orphans.push(`${table}: ${left} row(s)`)
		}
		expect(
			orphans,
			'a purged board left rows behind — the table is missing from BoardCascade',
		).toEqual([])

		// And the bytes, which live outside the database entirely.
		const found = execSync(
			`docker exec ${APP} find /var/www/html/data -type d -name 'card-${cardId}' -path '*kanso*' || true`,
			{ encoding: 'utf8' },
		).trim()
		expect(found, 'the attachment app-data folder must be gone').toBe('')
	})

	test('the board next door is untouched', () => {
		// The other half of correctness on a destructive path. An app-data folder
		// is named by card id ALONE, so an over-broad byte sweep destroys a live
		// board's attachments with nothing in the database to show for it.
		const survivors = [
			['oc_kanso_boards', `id = ${neighbour.boardId}`],
			['oc_kanso_stacks', `board_id = ${neighbour.boardId}`],
			['oc_kanso_cards', `board_id = ${neighbour.boardId}`],
			['oc_kanso_labels', `board_id = ${neighbour.boardId}`],
			['oc_kanso_card_labels', `card_id = ${neighbour.cardId}`],
			['oc_kanso_comments', `card_id = ${neighbour.cardId}`],
			['oc_kanso_comment_reactions', `comment_id = ${neighbour.commentId}`],
			['oc_kanso_card_attachments', `card_id = ${neighbour.cardId}`],
		]
		for (const [table, where] of survivors) {
			expect(
				Number(sqlValue(`SELECT count(*) FROM ${table} WHERE ${where}`)),
				`purging one board deleted rows from ${table} belonging to another`,
			).toBeGreaterThan(0)
		}

		const kept = execSync(
			`docker exec ${APP} find /var/www/html/data -type d -name 'card-${neighbour.cardId}' -path '*kanso*' || true`,
			{ encoding: 'utf8' },
		).trim()
		expect(kept, "the neighbouring board's attachment bytes must survive").not.toBe('')
	})
})
