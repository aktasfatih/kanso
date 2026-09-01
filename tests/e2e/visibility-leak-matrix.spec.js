// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, makeApi, currentAuth, API, BASE, exportArchive } from './helpers.js'

// Sentinels for the two viewers. They are NOT auth strings themselves — the
// client dispatch below resolves them at CALL time: ADMIN → the current user
// (board owner, `currentAuth`), TESTER → the worker-scoped `peer` (captured in
// beforeAll). This keeps every `api(ADMIN|TESTER, …)` call site byte-for-byte
// identical while staying parallel-safe under E2E_ISOLATE.
const ADMIN = Symbol('owner')
const TESTER = Symbol('peer')

// The worker-scoped peer, captured once in beforeAll so the module-level client
// dispatch and the `peer.user` participant/assignee literals can reach it.
let peerRef = null

// #3743 — the endpoint-level leak matrix: two viewers (admin = internal board
// owner, tester = EXTERNAL member) plus the anonymous token surfaces, asserted
// against every HTTP read path AND the write gates. The unit-level truth table
// lives in tests/unit/Service/LeakMatrixTest.php; this spec proves the same
// rule holds through real SQL on a real server.
//
// Fixture (one board, one stack, unique title token per run):
//   PUB        public,   created by admin
//   PROV        internal, created by admin  (provider side)
//   CLI     internal, created by tester (client side)
//   PRIV       private,  created/owned by admin
//
// Expected visible sets:
//   admin  → PUB, PROV, PRIV   (never CLI — no owner/manager backdoor)
//   tester → PUB, CLI      (never PROV, never PRIV)
//   anon   → PUB              (public share + ICS feed)

// Per-viewer API clients (owner + external peer), cached so the (auth, method,
// path, body) call sites below stay byte-for-byte identical. The ADMIN/TESTER
// sentinels resolve lazily (after the worker-isolation rebind + peer capture).
const clients = new Map()
function clientFor(auth) {
	if (auth === ADMIN) {
		// Read the live `currentAuth` binding at call time (a module-level snapshot
		// would capture the pre-rebind admin auth under E2E_ISOLATE).
		if (!clients.has(currentAuth)) clients.set(currentAuth, makeApi(currentAuth))
		return clients.get(currentAuth)
	}
	if (auth === TESTER) return peerRef.api
	if (!clients.has(auth)) clients.set(auth, makeApi(auth))
	return clients.get(auth)
}

function call(auth, method, path, body) {
	return clientFor(auth).raw(method, path, body)
}

function api(auth, method, path, body) {
	return clientFor(auth).send(method, path, body)
}

test.describe.serial('Card visibility leak matrix (#3743)', () => {
	const token = 'vlm' + Math.floor(Date.now() / 1000)
	const state = {
		boardId: 0,
		stackId: 0,
		prefix: '',
		cards: {}, // name → {id, boardSeq}
		cursorBeforeCards: 0,
	}

	const title = (name) => `${name} ${token}`

	test.beforeAll(async ({ peer }) => {
		peerRef = peer
		const board = await api(ADMIN, 'POST', '/boards', { title: 'Leak Matrix ' + token })
		state.boardId = board.id
		const stack = await api(ADMIN, 'POST', '/stacks', { boardId: board.id, title: 'Lane' })
		state.stackId = stack.id

		// Share with the peer as an EXTERNAL member holding READ | EDIT.
		await api(ADMIN, 'POST', `/boards/${board.id}/acl`, {
			participant: peer.user,
			participantType: 'user',
			permission: 3,
			role: 'external',
		})

		// Delta cursor from BEFORE any card exists — the changes window will
		// then carry every card create below.
		const shown = await api(ADMIN, 'GET', `/boards/${board.id}`)
		state.cursorBeforeCards = shown.cursor
		state.prefix = shown.board.prefix

		const mk = async (auth, name, visibility) => {
			const card = await api(auth, 'POST', '/cards', { stackId: stack.id, title: title(name) })
			if (visibility !== 'public') {
				await api(auth, 'PATCH', `/cards/${card.id}`, { visibility })
			}
			state.cards[name] = { id: card.id, boardSeq: card.boardSeq }
		}
		await mk(ADMIN, 'PUB', 'public')
		await mk(ADMIN, 'PROV', 'internal')
		await mk(TESTER, 'CLI', 'internal') // creator is external → client-side internal
		await mk(ADMIN, 'PRIV', 'private')
	})

	test.afterAll(async () => {
		if (state.boardId) await api(ADMIN, 'DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	const expectTitles = (payloadCards, expectedNames) => {
		const got = payloadCards.map((c) => c.title).filter((t) => t.includes(token)).sort()
		const want = expectedNames.map(title).sort()
		expect(got).toEqual(want)
	}

	test('board payload: each viewer sees exactly their set (and the visibility field)', async () => {
		const adminBoard = await api(ADMIN, 'GET', `/boards/${state.boardId}`)
		expectTitles(adminBoard.cards, ['PUB', 'PROV', 'PRIV'])
		const pub = adminBoard.cards.find((c) => c.id === state.cards.PUB.id)
		expect(pub.visibility).toBe('public')
		const priv = adminBoard.cards.find((c) => c.id === state.cards.PRIV.id)
		expect(priv.visibility).toBe('private')

		const testerBoard = await api(TESTER, 'GET', `/boards/${state.boardId}`)
		expectTitles(testerBoard.cards, ['PUB', 'CLI'])
	})

	test('delta sync: hidden cards land in the remove list, never as upserts', async () => {
		const delta = await api(TESTER, 'GET', `/boards/${state.boardId}/changes?since=${state.cursorBeforeCards}`)
		expect(delta.resync).toBe(false)
		expectTitles(delta.cards.upsert, ['PUB', 'CLI'])
		// The hidden cards' ids may appear ONLY as bare removes (id-only).
		expect(delta.cards.remove).toEqual(
			expect.arrayContaining([state.cards.PROV.id, state.cards.PRIV.id]),
		)
		const upsertIds = delta.cards.upsert.map((c) => c.id)
		expect(upsertIds).not.toContain(state.cards.PROV.id)
		expect(upsertIds).not.toContain(state.cards.PRIV.id)
	})

	test('search: hidden titles never match — not even via a comment on a hidden card', async () => {
		// A comment carrying the token on a card hidden from tester.
		await api(ADMIN, 'POST', `/cards/${state.cards.PROV.id}/comments`, { body: 'needle ' + token })

		const admin = await api(ADMIN, 'GET', `/search?q=${token}`)
		const adminTitles = admin.results.filter((r) => r.type === 'card').map((r) => r.title).sort()
		expect(adminTitles).toEqual(['PROV', 'PRIV', 'PUB'].map(title).sort())

		const tester = await api(TESTER, 'GET', `/search?q=${token}`)
		const testerTitles = tester.results.filter((r) => r.type === 'card').map((r) => r.title).sort()
		expect(testerTitles).toEqual(['CLI', 'PUB'].map(title).sort())
		// The comment on PROV must not surface for tester in ANY result type.
		expect(tester.results.some((r) => r.type === 'comment')).toBe(false)
		expect(JSON.stringify(tester.results)).not.toContain(title('PROV'))
	})

	test('my-cards: assignment grants no visibility', async () => {
		await api(ADMIN, 'PUT', `/cards/${state.cards.PUB.id}/assignees/${peerRef.user}`)
		await api(ADMIN, 'PUT', `/cards/${state.cards.PROV.id}/assignees/${peerRef.user}`)

		const mine = await api(TESTER, 'GET', '/my-cards')
		const titles = mine.map((c) => c.title).filter((t) => t.includes(token))
		expect(titles).toEqual([title('PUB')])
	})

	test('reviews: a hidden card is not reviewable across the fence', async () => {
		// Requesting a review FROM tester on a card tester cannot see → 400.
		const blocked = await call(ADMIN, 'PUT', `/cards/${state.cards.PROV.id}/reviews/${peerRef.user}`)
		expect(blocked.status).toBe(400)

		await api(ADMIN, 'PUT', `/cards/${state.cards.PUB.id}/reviews/${peerRef.user}`)
		const mine = await api(TESTER, 'GET', '/reviews/mine')
		const titles = mine.map((r) => r.cardTitle).filter((t) => t.includes(token))
		expect(titles).toEqual([title('PUB')])
	})

	test('board stats + boards-list counts: hidden cards are not counted', async () => {
		const adminStats = await api(ADMIN, 'GET', `/boards/${state.boardId}/stats`)
		const adminByStack = adminStats.byStack.reduce((n, r) => n + r.count, 0)
		expect(adminByStack).toBe(3)

		const testerStats = await api(TESTER, 'GET', `/boards/${state.boardId}/stats`)
		const testerByStack = testerStats.byStack.reduce((n, r) => n + r.count, 0)
		expect(testerByStack).toBe(2)
		// Assignee distribution must not count the hidden assignment either.
		const testerAssignee = (testerStats.byAssignee.find((r) => r.uid === peerRef.user) || { count: 0 }).count
		expect(testerAssignee).toBe(1) // PUB only — PROV is hidden from this viewer

		const boards = await api(TESTER, 'GET', '/boards')
		const tile = boards.find((b) => b.id === state.boardId)
		expect(tile.stats.cardCount).toBe(2)
	})

	test('export + duplicate: viewer-scoped for internals, denied for externals', async () => {
		// The export is a .zip since #10060; board.json is the document inside it.
		const adminExport = (await exportArchive(state.boardId, currentAuth)).doc
		expectTitles(adminExport.board.cards, ['PUB', 'PROV', 'PRIV'])
		// The scoped export still round-trips visibility.
		const privRow = adminExport.board.cards.find((c) => c.id === state.cards.PRIV.id)
		expect(privRow.visibility).toBe('private')

		// #3744 (decided policy): whole-board egress is INTERNAL-only - the
		// external member gets a plain 403 for export AND duplicate, instead
		// of the viewer-scoped copy externals briefly had under #3743.
		expect((await call(TESTER, 'GET', `/boards/${state.boardId}/export`)).status).toBe(403)
		expect((await call(TESTER, 'POST', `/boards/${state.boardId}/duplicate`, { withCards: true })).status).toBe(403)
	})

	test('card-id probes: reads AND writes on a hidden card 404 (no existence oracle)', async () => {
		const hidden = state.cards.PROV.id
		const probes = [
			['GET', `/cards/${hidden}`],
			['PATCH', `/cards/${hidden}`, { title: 'pwned' }],
			['DELETE', `/cards/${hidden}`],
			['POST', `/cards/${hidden}/comments`, { body: 'hi' }],
			['GET', `/cards/${hidden}/comments`],
			['GET', `/cards/${hidden}/activity`],
			['GET', `/cards/${hidden}/relations`],
			['GET', `/cards/${hidden}/attachments`],
			['GET', `/cards/${hidden}/time-entries`],
			['GET', `/cards/${hidden}/checklist`],
			['POST', `/cards/${hidden}/move`, { targetStackId: state.stackId }],
			['PUT', `/cards/${hidden}/labels/1`],
			['PUT', `/cards/${hidden}/assignees/${peerRef.user}`],
		]
		for (const [method, path, body] of probes) {
			const r = await call(TESTER, method, path, body)
			expect(r.status, `${method} ${path}`).toBe(404)
		}
		// Same probes with an id that does not exist at all must be
		// indistinguishable (also 404) — the no-oracle property.
		const ghost = await call(TESTER, 'GET', '/cards/99999999')
		expect(ghost.status).toBe(404)
	})

	test('human-ref resolution: a hidden card reads as an unknown reference', async () => {
		const ref = `${state.prefix}-${state.cards.PROV.boardSeq}`
		const r = await call(TESTER, 'GET', `/boards/${state.boardId}/cards/by-ref/${ref}`)
		expect(r.status).toBe(404)
		const ok = await api(TESTER, 'GET', `/boards/${state.boardId}/cards/by-ref/${state.prefix}-${state.cards.PUB.boardSeq}`)
		expect(ok.cardId).toBe(state.cards.PUB.id)
	})

	test('relations: a hidden counterpart renders masked, never its title', async () => {
		await api(ADMIN, 'POST', `/cards/${state.cards.PUB.id}/relations`, {
			otherCardId: state.cards.PROV.id,
			kind: 'relates',
		})
		const rels = await api(TESTER, 'GET', `/cards/${state.cards.PUB.id}/relations`)
		const masked = rels.relates.find((r) => r.hidden === true)
		expect(masked).toBeTruthy()
		expect(masked.cardId).toBeNull()
		expect(masked.title).toBeNull()
		expect(JSON.stringify(rels)).not.toContain(title('PROV'))

		// Relating TO a hidden card is itself blocked (404 — unprobeable).
		const r = await call(TESTER, 'POST', `/cards/${state.cards.CLI.id}/relations`, {
			otherCardId: state.cards.PRIV.id,
			kind: 'relates',
		})
		expect(r.status).toBe(404)
	})

	test('anonymous surfaces: public share and ICS feed carry public cards only', async () => {
		await api(ADMIN, 'PATCH', `/cards/${state.cards.PUB.id}`, { duedate: '2027-01-01T12:00:00Z' })
		await api(ADMIN, 'PATCH', `/cards/${state.cards.PROV.id}`, { duedate: '2027-01-01T12:00:00Z' })

		const share = await api(ADMIN, 'POST', `/boards/${state.boardId}/public-share`)
		const pub = await fetch(`${API}/public/${share.token}`, { headers: { 'OCS-APIREQUEST': 'true' } })
		expect(pub.ok).toBe(true)
		const snapshot = await pub.json()
		expectTitles(snapshot.cards, ['PUB'])

		const feed = await api(ADMIN, 'POST', `/boards/${state.boardId}/calendar-feed`)
		const ics = await fetch(`${BASE}/index.php/apps/kanso/feed/${feed.token}.ics`)
		expect(ics.ok).toBe(true)
		const body = await ics.text()
		expect(body).toContain(title('PUB'))
		expect(body).not.toContain(title('PROV'))
		expect(body).not.toContain(title('PRIV'))
	})

	test('copying a card preserves its visibility (never silently widens to public)', async () => {
		const copy = await api(TESTER, 'POST', `/cards/${state.cards.CLI.id}/copy`, { targetStackId: state.stackId })
		expect(copy.visibility).toBe('internal')
		await api(TESTER, 'DELETE', `/cards/${copy.id}`)
	})

	test('my-steps: a step on a card narrowed past its assignee leaves the feed', async () => {
		// Assign-time is already gated (a step can only be assigned to someone
		// who SEES the card), so the SQL-level leak vector is narrowing AFTER
		// assignment: the step row keeps its assignee, but the my-steps query
		// must drop it via the card-visibility scope (#3745/#3743). A dedicated
		// card keeps the earlier set/count assertions untouched.
		const card = await api(ADMIN, 'POST', '/cards', { stackId: state.stackId, title: `STEPHOST ${token}` })
		const item = await api(ADMIN, 'POST', `/cards/${card.id}/checklist`, { title: `step ${token}` })
		await api(ADMIN, 'POST', `/checklist/${item.id}/assign`, { participant: peerRef.user })

		// Visible card → the step is in tester's feed.
		const before = await api(TESTER, 'GET', '/my-steps')
		expect(before.some((s) => s.id === item.id)).toBe(true)

		// Narrow to provider-internal (creator admin is internal) → hidden from
		// the external tester; the step must vanish from the feed at SQL level.
		await api(ADMIN, 'PATCH', `/cards/${card.id}`, { visibility: 'internal' })
		const after = await api(TESTER, 'GET', '/my-steps')
		expect(after.some((s) => s.id === item.id)).toBe(false)
		expect(JSON.stringify(after)).not.toContain(`STEPHOST ${token}`)

		// The row is a filter, not a delete: admin re-widening restores it.
		await api(ADMIN, 'PATCH', `/cards/${card.id}`, { visibility: 'public' })
		const restored = await api(TESTER, 'GET', '/my-steps')
		expect(restored.some((s) => s.id === item.id)).toBe(true)

		// Hard-remove the host card (soft-delete, then purge) so the exact
		// trash assertions below stay untouched.
		await api(ADMIN, 'DELETE', `/cards/${card.id}`)
		await api(ADMIN, 'DELETE', `/cards/${card.id}/purge`)
	})

	test('trash: a hidden card stays hidden after deletion, and is unrestorable', async () => {
		// The previous test soft-deleted tester's internal COPY ("CLI … (copy)")
		// into the trash: it must show for tester and stay hidden from admin.
		await api(ADMIN, 'DELETE', `/cards/${state.cards.PUB.id}`)
		await api(ADMIN, 'DELETE', `/cards/${state.cards.PROV.id}`)

		const trashTitles = (rows) => rows.map((c) => c.title).filter((t) => t.includes(token)).sort()

		const adminTrash = await api(ADMIN, 'GET', `/boards/${state.boardId}/trash`)
		expect(trashTitles(adminTrash)).toEqual([title('PROV'), title('PUB')].sort())

		const testerTrash = await api(TESTER, 'GET', `/boards/${state.boardId}/trash`)
		expect(trashTitles(testerTrash)).toEqual([`${title('CLI')} (copy)`, title('PUB')].sort())

		const restore = await call(TESTER, 'POST', `/cards/${state.cards.PROV.id}/restore`)
		expect(restore.status).toBe(404)
	})
})
