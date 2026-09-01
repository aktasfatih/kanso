// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { createHash } from 'node:crypto'
import { test, expect, exportArchive } from './helpers.js'

const BASE = 'http://localhost:8891'
const KAN = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from('admin:admin').toString('base64')

async function call(method, path, body) {
	const r = await fetch(KAN + path, {
		method,
		headers: { ...HEADERS, Authorization: AUTH },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`${method} ${path} → ${r.status}: ${await r.text()}`)
	const text = await r.text()
	return text ? JSON.parse(text) : null
}
const kanso = (m, p, b) => call(m, p, b)

// Upload a small in-memory file onto a card via multipart.
async function uploadFile(cardId, filename, content, contentType = 'text/plain') {
	const form = new FormData()
	form.append('file', new Blob([content], { type: contentType }), filename)
	const r = await fetch(KAN + `/cards/${cardId}/attachments`, {
		method: 'POST',
		headers: { 'OCS-APIRequest': 'true', Authorization: AUTH },
		body: form,
	})
	if (!r.ok) throw new Error(`upload ${filename} → ${r.status}: ${await r.text()}`)
	return r.json()
}

// POST an export archive back to /boards/import the way the app does: the FILE
// itself, as multipart, not a JSON string.
async function importArchive(buffer, filename = 'kanso-export.zip') {
	const form = new FormData()
	form.append('file', new Blob([buffer], { type: 'application/zip' }), filename)
	const r = await fetch(KAN + '/boards/import', {
		method: 'POST',
		headers: { 'OCS-APIRequest': 'true', Authorization: AUTH },
		body: form,
	})
	return { status: r.status, body: r.ok ? await r.json() : await r.text() }
}

// Download one attachment's live bytes through the API (READ-gated, streamed
// straight out of app-data) — the real proof the object landed.
async function downloadAttachment(cardId, attachmentId) {
	const r = await fetch(KAN + `/cards/${cardId}/attachments/${attachmentId}`, {
		headers: { 'OCS-APIRequest': 'true', Authorization: AUTH },
	})
	if (!r.ok) throw new Error(`download ${attachmentId} → ${r.status}: ${await r.text()}`)
	return Buffer.from(await r.arrayBuffer())
}

const sha256 = (buffer) => createHash('sha256').update(buffer).digest('hex')

// Full-board portability (#3437, #10060): seed a populated board through the
// Kanso API, export it to Kanso's own archive (a .zip holding board.json plus
// the card attachments), import that document into a fresh board, and assert
// the re-export deep-equals the original modulo the volatile bits (ids, owner,
// timestamps).
test.describe('Board export / import', () => {
	let srcBoardId = 0
	let importedBoardId = 0
	let restoredBoardId = 0
	let alphaCardId = 0
	let betaCardId = 0
	const ATTACHMENT_BODY = 'the attached bytes'
	// A real binary (a 1x1 PNG) alongside the text one: bytes that a
	// string round-trip would silently mangle.
	const PNG_BYTES = Buffer.from(
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
		'base64',
	)
	const BETA_BODY = 'bytes that belong to the second card only'
	const title = 'E2E Portability ' + Math.floor(Date.now() / 1000)

	// Strip the volatile fields so two exports of the "same" board compare equal.
	function normalize(doc) {
		const b = doc.board
		const stripCard = (c) => ({
			title: c.title,
			description: c.description,
			sortKey: c.sortKey,
			archived: c.archived,
			priority: c.priority,
			stackTitleRef: c.stackTitleRef, // filled in below
			labelTitles: c.labelTitles,
			checklist: (c.checklist || []).map((i) => ({ title: i.title, done: i.done })),
			comments: (c.comments || []).map((cm) => ({ body: cm.body })),
			reviews: (c.reviews || []).map((rv) => ({ reviewer: rv.reviewer, state: rv.state })),
		})

		// Resolve id-based references to STABLE names so remapped ids compare equal.
		const stackTitleById = Object.fromEntries(b.stacks.map((s) => [s.id, s.title]))
		const labelTitleById = Object.fromEntries(b.labels.map((l) => [l.id, l.title]))
		const cards = b.cards.map((c) => stripCard({
			...c,
			stackTitleRef: stackTitleById[c.stackId],
			labelTitles: (c.labelIds || []).map((id) => labelTitleById[id]).sort(),
		}))
		// Order-independent comparison of the collections.
		const bySortThenTitle = (a, z) => (a.sortKey || '').localeCompare(z.sortKey || '') || a.title.localeCompare(z.title)
		return {
			title: b.title,
			color: b.color,
			estimateScale: b.estimateScale,
			stacks: b.stacks.map((s) => ({ title: s.title, role: s.role, wipLimit: s.wipLimit })).sort((a, z) => a.title.localeCompare(z.title)),
			labels: b.labels.map((l) => ({ title: l.title, color: l.color })).sort((a, z) => a.title.localeCompare(z.title)),
			cards: cards.sort(bySortThenTitle),
		}
	}

	test.beforeAll(async () => {
		const created = await kanso('POST', '/boards', { title, color: '0082c9' })
		srcBoardId = created.id

		const todo = await kanso('POST', '/stacks', { boardId: srcBoardId, title: 'To do' })
		const done = await kanso('POST', '/stacks', { boardId: srcBoardId, title: 'Done' })

		const label = await kanso('POST', '/labels', { boardId: srcBoardId, title: 'Priority', color: 'e11d48' })

		const alpha = await kanso('POST', '/cards', { stackId: todo.id, title: 'Alpha' })
		await kanso('PATCH', `/cards/${alpha.id}`, { description: 'the alpha description', priority: 4 })
		await kanso('PUT', `/cards/${alpha.id}/labels/${label.id}`)
		await kanso('POST', `/cards/${alpha.id}/checklist`, { title: 'first step' })
		await kanso('POST', `/cards/${alpha.id}/checklist`, { title: 'second step' })
		await kanso('POST', `/cards/${alpha.id}/comments`, { body: 'a top-level comment' })

		const beta = await kanso('POST', '/cards', { stackId: done.id, title: 'Beta' })

		// Real attachments — the thing every export used to drop, spread over
		// two cards so the restore has to put each one back where it came from.
		alphaCardId = alpha.id
		betaCardId = beta.id
		await uploadFile(alpha.id, 'notes.txt', ATTACHMENT_BODY, 'text/plain')
		await uploadFile(alpha.id, 'pixel.png', PNG_BYTES, 'image/png')
		await uploadFile(beta.id, 'beta-notes.txt', BETA_BODY, 'text/plain')
	})

	test.afterAll(async () => {
		if (restoredBoardId) await kanso('DELETE', `/boards/${restoredBoardId}`).catch(() => {})
		if (importedBoardId) await kanso('DELETE', `/boards/${importedBoardId}`).catch(() => {})
		if (srcBoardId) await kanso('DELETE', `/boards/${srcBoardId}`).catch(() => {})
	})

	test('exports a populated board, imports it, and the re-export matches', async () => {
		const { res: download, buffer, entries: archive } = await exportArchive(srcBoardId, AUTH)
		// The export is now a downloadable .zip, not a JSON body.
		expect(download.headers.get('content-type')).toContain('application/zip')
		expect(download.headers.get('content-disposition')).toContain('.zip"')
		const original = JSON.parse(archive['board.json'].toString('utf8'))
		// Envelope format version (3 since the archive carries attachments).
		expect(original.kanso).toBeGreaterThanOrEqual(3)
		expect(original.board.title).toBe(title)
		expect(original.board.cards.length).toBe(2)
		// The description + checklist + comment made it into the export.
		const alpha = original.board.cards.find((c) => c.title === 'Alpha')
		expect(alpha.description).toBe('the alpha description')
		expect(alpha.checklist.length).toBe(2)
		expect(alpha.comments.length).toBe(1)
		expect(alpha.labelIds.length).toBe(1)

		// #10060: the attachments ride along — manifested on the card AND present
		// in the archive at exactly the path the manifest advertises.
		expect(alpha.attachments.length).toBe(2)
		const manifested = alpha.attachments.find((a) => a.filename === 'notes.txt')
		expect(manifested.size).toBe(ATTACHMENT_BODY.length)
		expect(manifested.path).toBe(`attachments/${manifested.id}/notes.txt`)
		expect(archive[manifested.path]).toBeDefined()
		expect(archive[manifested.path].toString('utf8')).toBe(ATTACHMENT_BODY)

		// The withheld-key invariant: `storage_key` is the server-generated
		// object name and must not escape through the archive — not in the
		// document, not in an entry name, not anywhere in the raw bytes.
		expect(manifested.storageKey).toBeUndefined()
		expect(manifested.storage_key).toBeUndefined()
		const rawArchive = buffer.toString('latin1')
		expect(rawArchive).not.toContain('storageKey')
		expect(rawArchive).not.toContain('storage_key')
		// And the real key of the real object, read straight from the DB-backed
		// API surface, is likewise absent (the API withholds it, so its absence
		// there is checked first).
		const listed = await kanso('GET', `/cards/${alphaCardId}/attachments`)
		expect(listed[0].storageKey).toBeUndefined()

		// Import the exact document back into a fresh board.
		const res = await kanso('POST', '/boards/import', { document: JSON.stringify(original) })
		importedBoardId = res.boardId
		expect(res.boardId).not.toBe(srcBoardId)
		expect(res.stacks).toBe(2)
		expect(res.cards).toBe(2)
		expect(res.labels).toBe(1)

		// Re-export the imported board and compare the normalized shapes.
		const reexport = (await exportArchive(importedBoardId, AUTH)).doc
		expect(normalize(reexport)).toEqual(normalize(original))

		// Fresh ids everywhere (the imported board owns brand-new stacks/cards).
		const srcCardIds = new Set(original.board.cards.map((c) => c.id))
		for (const c of reexport.board.cards) {
			expect(srcCardIds.has(c.id)).toBe(false)
		}

		// Rejects a bogus / future-version document.
		const bad = await fetch(KAN + '/boards/import', {
			method: 'POST',
			headers: { ...HEADERS, Authorization: AUTH },
			body: JSON.stringify({ document: JSON.stringify({ kanso: 9999, board: { title: 'x' } }) }),
		})
		expect(bad.status).toBe(400)
	})

	// #10071, THE headline: export a board with attachments, post that exact
	// archive back, and every file has to come out the other side — same name,
	// same bytes (compared by hash), on the same card.
	test('re-imports its own export archive with every attachment intact', async () => {
		const { buffer, entries: archive, doc: original } = await exportArchive(srcBoardId, AUTH)

		// What went in, keyed by "<card title>/<filename>".
		const sourceByKey = {}
		for (const card of original.board.cards) {
			for (const a of card.attachments || []) {
				sourceByKey[`${card.title}/${a.filename}`] = archive[a.path]
			}
		}
		expect(Object.keys(sourceByKey).sort()).toEqual([
			'Alpha/notes.txt',
			'Alpha/pixel.png',
			'Beta/beta-notes.txt',
		])

		// Post the archive FILE itself (multipart), the way the app does.
		const imported = await importArchive(buffer)
		expect(imported.status, JSON.stringify(imported.body)).toBe(200)
		restoredBoardId = imported.body.boardId
		expect(restoredBoardId).not.toBe(srcBoardId)
		expect(imported.body.cards).toBe(2)

		// Re-export the restored board and line the attachments up by card.
		const restored = (await exportArchive(restoredBoardId, AUTH)).doc
		const restoredCards = Object.fromEntries(restored.board.cards.map((c) => [c.title, c]))
		expect(Object.keys(restoredCards).sort()).toEqual(['Alpha', 'Beta'])
		expect((restoredCards.Alpha.attachments || []).length).toBe(2)
		expect((restoredCards.Beta.attachments || []).length).toBe(1)

		// Every file, on the right card, byte-identical to what was uploaded.
		for (const [cardTitle, card] of Object.entries(restoredCards)) {
			for (const a of card.attachments) {
				const key = `${cardTitle}/${a.filename}`
				expect(sourceByKey[key], `${key} was not on the source board`).toBeDefined()
				// Read the LIVE bytes back out of storage, not just the archive:
				// this proves the object really landed in app-data under the new
				// card, reachable through the normal READ-gated download.
				const live = await downloadAttachment(card.id, a.id)
				expect(sha256(live), `${key} bytes changed`).toBe(sha256(sourceByKey[key]))
				expect(a.size).toBe(sourceByKey[key].length)
			}
		}

		// The binary survived as binary — a text round trip would have mangled it.
		const restoredPng = restoredCards.Alpha.attachments.find((a) => a.filename === 'pixel.png')
		expect(sha256(await downloadAttachment(restoredCards.Alpha.id, restoredPng.id))).toBe(sha256(PNG_BYTES))

		// Fresh ids and fresh objects: the restored rows point at the NEW cards,
		// never back at the source board's.
		expect(restoredCards.Alpha.id).not.toBe(alphaCardId)
		expect(restoredCards.Beta.id).not.toBe(betaCardId)
		// And the source board is untouched — an import never mutates its source.
		const sourceAgain = (await exportArchive(srcBoardId, AUTH)).doc
		expect(sourceAgain.board.cards.find((c) => c.title === 'Alpha').attachments.length).toBe(2)
	})

	// A v3 archive whose board simply has no files must import as cleanly as one
	// that does — the attachment path is additive, never required.
	test('imports a v3 archive that carries no attachments', async () => {
		const bare = await kanso('POST', '/boards', { title: 'E2E Bare ' + Date.now() })
		await kanso('POST', '/stacks', { boardId: bare.id, title: 'Only column' })
		const { buffer, entries } = await exportArchive(bare.id, AUTH)
		// Exactly one entry: the document, nothing else.
		expect(Object.keys(entries)).toEqual(['board.json'])

		const imported = await importArchive(buffer)
		expect(imported.status, JSON.stringify(imported.body)).toBe(200)
		expect(imported.body.stacks).toBe(1)

		await kanso('DELETE', `/boards/${imported.body.boardId}`).catch(() => {})
		await kanso('DELETE', `/boards/${bare.id}`).catch(() => {})
	})

	// Something that is not an archive at all, posted as a file, is a 400 —
	// never a 500 and never a partial board.
	test('refuses a file that is not a Kanso export', async () => {
		const bad = await importArchive(Buffer.from('PK not really a zip'), 'evil.zip')
		expect(bad.status).toBe(400)
	})

	// #10060 raised the format to v3. Every .json export anyone already
	// downloaded is a v1/v2 document and must keep importing untouched.
	test('still imports a pre-archive v2 JSON document', async () => {
		const v2 = {
			kanso: 2,
			exportedAt: 1234,
			board: {
				title: 'E2E Legacy v2 ' + Math.floor(Date.now() / 1000),
				color: '0082c9',
				stacks: [{ id: 1, title: 'Todo', sortKey: 'a' }],
				labels: [{ id: 5, title: 'Old', color: 'e11' }],
				cards: [{ id: 100, stackId: 1, title: 'Legacy card', sortKey: 'h', labelIds: [5] }],
			},
		}

		const res = await kanso('POST', '/boards/import', { document: JSON.stringify(v2) })
		expect(res.stacks).toBe(1)
		expect(res.cards).toBe(1)
		expect(res.labels).toBe(1)

		const doc = (await exportArchive(res.boardId, AUTH)).doc
		expect(doc.board.cards[0].title).toBe('Legacy card')
		// A v2 document carries no attachments, so the card imports with none.
		expect(doc.board.cards[0].attachments).toEqual([])

		await kanso('DELETE', `/boards/${res.boardId}`).catch(() => {})
	})

	// #10071 moved the UI onto a file upload. An old .json export picked in that
	// same file dialog must go through the new path unchanged — the server reads
	// the shape off the bytes, not off the name or the declared type.
	test('still imports a pre-archive v2 JSON document posted as a file', async () => {
		const v2 = {
			kanso: 2,
			exportedAt: 1234,
			board: {
				title: 'E2E Legacy Upload ' + Math.floor(Date.now() / 1000),
				color: '0082c9',
				stacks: [{ id: 1, title: 'Todo', sortKey: 'a' }],
				cards: [{ id: 100, stackId: 1, title: 'Legacy card', sortKey: 'h' }],
			},
		}

		const res = await importArchive(Buffer.from(JSON.stringify(v2)), 'kanso-legacy.json')
		expect(res.status, JSON.stringify(res.body)).toBe(200)
		expect(res.body.stacks).toBe(1)
		expect(res.body.cards).toBe(1)

		await kanso('DELETE', `/boards/${res.body.boardId}`).catch(() => {})
	})
})
