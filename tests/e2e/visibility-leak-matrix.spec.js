// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, makeApi, currentAuth, API, BASE, exportArchive, provisionUser, deleteUser } from './helpers.js'

// Sentinels for the three viewers. They are NOT auth strings themselves — the
// client dispatch below resolves them at CALL time: ADMIN → the current user
// (board owner, `currentAuth`), TESTER → the worker-scoped `peer` (captured in
// beforeAll), OUTSIDER → an account with NO membership on the board at all.
// This keeps every `api(ADMIN|TESTER|OUTSIDER, …)` call site byte-for-byte
// identical while staying parallel-safe under E2E_ISOLATE.
const ADMIN = Symbol('owner')
const TESTER = Symbol('peer')
const OUTSIDER = Symbol('non-member')

// The worker-scoped peer, captured once in beforeAll so the module-level client
// dispatch and the `peer.user` participant/assignee literals can reach it.
let peerRef = null

// A logged-in account that is NOT on the board's ACL — the third viewer the
// matrix needs, because "member who may not see this card" (TESTER) and
// "not a member" (OUTSIDER) fail through different guards and so leak
// differently. Provisioned per run in beforeAll, removed in afterAll.
let outsiderRef = null

// #3743 — the endpoint-level leak matrix: three viewers (admin = internal board
// owner, tester = EXTERNAL member, outsider = NON-member) plus the anonymous
// token surfaces, asserted against every HTTP read path AND the write gates.
// The unit-level truth table lives in tests/unit/Service/LeakMatrixTest.php;
// this spec proves the same rule holds through real SQL on a real server.
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
//
// The outsider holds no ACL row, so every board-scoped route 403s for them
// before visibility is ever consulted. That makes them the only viewer who can
// SEE which of the two guards answered: a member 404s at the visibility guard
// whatever the order is, so the member sweep alone cannot tell a
// membership-first route from a visibility-first one. The outsider therefore
// sweeps the WHOLE card-addressed route list (#10307), not just the review
// routes that first exposed the ordering (#10296).

// Per-viewer API clients (owner + external peer + non-member), cached so the
// (auth, method, path, body) call sites below stay byte-for-byte identical. The
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
	if (auth === OUTSIDER) return outsiderRef.api
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
		// Named off the worker's peer so two parallel workers never share it.
		outsiderRef = await provisionUser(`${peer.user}_out`, peer.pass, { displayName: `${peer.user}_out` })
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
		if (outsiderRef) await deleteUser(outsiderRef.user)
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

	test('reviews: a non-member cannot tell an existing review from a ghost id (#10296)', async () => {
		// This one needs the OUTSIDER, not the tester: the card-id probes below
		// pin "hidden card ⇒ 404", but a board MEMBER 404s at the visibility guard
		// for every review id, so they can never observe the review lookup. The
		// oracle lives one step out — a NON-member on a card whose `public`
		// visibility passes that guard for everyone. Only the ORDER of the
		// board-READ assert then decides whether the row lookup runs at all and
		// answers 404 (no such review) vs 403 (exists, not yours).
		const card = state.cards.PUB.id
		const detail = await api(ADMIN, 'GET', `/cards/${card}`)
		expect(detail.visibility, 'the probe needs a card the guard lets anyone past').toBe('public')
		const real = detail.reviews.find((r) => r.reviewer === peerRef.user)
		expect(real, 'PUB carries the review requested in the previous test').toBeTruthy()

		const onReal = await call(OUTSIDER, 'PATCH', `/cards/${card}/reviews/${real.id}`, { state: 'approved' })
		const onGhost = await call(OUTSIDER, 'PATCH', `/cards/${card}/reviews/99999999`, { state: 'approved' })
		expect(onReal.status, 'verdict on a review that DOES exist').toBe(403)
		expect(onGhost.status, 'verdict on a review id that does not exist').toBe(403)
		// The property, stated directly: the two answers must be identical, or the
		// difference itself enumerates the reviews on the card.
		expect(onGhost.status, 'the two responses must be indistinguishable').toBe(onReal.status)

		// …and nothing was written on the way to that 403.
		const after = await api(ADMIN, 'GET', `/cards/${card}`)
		expect(after.reviews.find((r) => r.id === real.id).state).toBe('pending')

		// The siblings gate on board permission before the row lookup by
		// construction; assert it, so a reordering there is caught too. Withdraw
		// is the sharper one: past the gate it would answer 200 for a ghost id
		// (and DELETE the real review).
		expect((await call(OUTSIDER, 'PUT', `/cards/${card}/reviews/${peerRef.user}`)).status).toBe(403)
		expect((await call(OUTSIDER, 'DELETE', `/cards/${card}/reviews/${real.id}`)).status).toBe(403)
		expect((await call(OUTSIDER, 'DELETE', `/cards/${card}/reviews/99999999`)).status).toBe(403)
		const survived = await api(ADMIN, 'GET', `/cards/${card}`)
		expect(survived.reviews.some((r) => r.id === real.id)).toBe(true)
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

	/**
	 * Every route addressed by a BARE card id — the leak class being "guessing an
	 * id reveals whether it exists". ONE list, swept by two viewers below:
	 *
	 *   - the member who may not see the card  → 404 (a hidden card is missing)
	 *   - the non-member                       → 403 (membership answers first)
	 *
	 * so a route added here is automatically probed from both sides.
	 *
	 * ⚠️ The VERB is load-bearing. A verb that hits no route answers 405, and a
	 * path that hits none answers 404 — which is precisely what the member sweep
	 * expects, so a typo there would pass vacuously. Every entry below was read
	 * off appinfo/routes.php and confirmed against a live server (each one
	 * answers non-404 for a viewer who passes the guards).
	 *
	 * `manage: true` marks the MANAGE-gated routes: the external member holds
	 * READ|EDIT, so those answer 403 on the permission ladder for EVERY card of
	 * the board (see the member sweep).
	 */
	const cardRoutes = (id) => [
		{ method: 'GET', path: `/cards/${id}` },
		{ method: 'PATCH', path: `/cards/${id}`, body: { title: 'pwned' } },
		{ method: 'DELETE', path: `/cards/${id}` },
		{ method: 'POST', path: `/cards/${id}/comments`, body: { body: 'hi' } },
		{ method: 'GET', path: `/cards/${id}/comments` },
		{ method: 'GET', path: `/cards/${id}/activity` },
		{ method: 'GET', path: `/cards/${id}/relations` },
		{ method: 'GET', path: `/cards/${id}/attachments` },
		{ method: 'GET', path: `/cards/${id}/time-entries` },
		{ method: 'GET', path: `/cards/${id}/checklist` },
		{ method: 'POST', path: `/cards/${id}/move`, body: { targetStackId: state.stackId } },
		{ method: 'PUT', path: `/cards/${id}/labels/1` },
		{ method: 'PUT', path: `/cards/${id}/assignees/${peerRef.user}` },
		// The review routes belong in this list like any other card-addressed
		// route: hidden card ⇒ 404, whatever review id is named. (What they do
		// NOT cover is the check ORDER inside a verdict — a member 404s here
		// either way; that oracle is asserted against the OUTSIDER above.)
		{ method: 'PATCH', path: `/cards/${id}/reviews/1`, body: { state: 'approved' } },
		{ method: 'DELETE', path: `/cards/${id}/reviews/1` },
		{ method: 'PUT', path: `/cards/${id}/reviews/${peerRef.user}` },
		// The destructive pair first (#10307): both take a bare card id and both
		// are irreversible past the guards. purge is DELETE, not POST.
		{ method: 'DELETE', path: `/cards/${id}/purge`, manage: true },
		// restore on a LIVE card: the trash test covers the trashed case, but the
		// live one is where the trash-state check used to answer 400 ahead of both
		// guards — an existence oracle for anyone holding a card id (#10307).
		{ method: 'POST', path: `/cards/${id}/restore` },
		{ method: 'POST', path: `/cards/${id}/move-to-board`, body: { targetStackId: state.stackId } },
		{ method: 'POST', path: `/cards/${id}/copy`, body: { targetStackId: state.stackId } },
		{ method: 'PUT', path: `/cards/${id}/parent`, body: { parentCardId: null } },
		{ method: 'POST', path: `/cards/${id}/create-from-template`, body: { targetStackId: state.stackId } },
		{ method: 'PUT', path: `/cards/${id}/template`, body: { isTemplate: true } },
		{ method: 'POST', path: `/cards/${id}/contacts`, body: { contactUri: 'ghost', displayName: 'ghost' } },
		{ method: 'DELETE', path: `/cards/${id}/contacts`, body: { contactUri: 'ghost' } },
	]

	test('card-id probes: reads AND writes on a hidden card 404 (no existence oracle)', async () => {
		const onHidden = cardRoutes(state.cards.PROV.id)
		const onVisible = cardRoutes(state.cards.PUB.id)
		for (const [i, { method, path, body, manage }] of onHidden.entries()) {
			const r = await call(TESTER, method, path, body)
			if (manage) {
				// A MANAGE-gated route answers on the PERMISSION ladder before any
				// card fact — this member holds READ|EDIT, so it 403s for EVERY card
				// on the board. What must not happen is that the answer varies with
				// the card's visibility; the outsider sweep below pins the rest.
				const twin = onVisible[i]
				const visible = await call(TESTER, twin.method, twin.path, twin.body)
				expect(r.status, `${method} ${path} (MANAGE-gated)`).toBe(403)
				expect(visible.status, `${method} ${path}: hidden and visible must answer alike`).toBe(r.status)
				continue
			}
			expect(r.status, `${method} ${path}`).toBe(404)
		}
		// Same probes with an id that does not exist at all must be
		// indistinguishable (also 404) — the no-oracle property.
		const ghost = await call(TESTER, 'GET', '/cards/99999999')
		expect(ghost.status).toBe(404)
	})

	test('card-id probes: a NON-member is refused before visibility is ever read (#10307)', async () => {
		// The same probe list, one viewer further out. A board MEMBER 404s at the
		// visibility guard on every one of these, so the member sweep above can
		// never observe WHICH guard answered — swap the two asserts on any route
		// and it stays green. The non-member can: they fail the membership check
		// (403) but would pass a visibility-first check on a public card and fail
		// it on an internal one (404). So a uniform 403 across the whole list is
		// exactly the statement "membership is checked BEFORE visibility, on every
		// card-addressed route" — the generalisation of #10296.
		const hidden = state.cards.PROV.id
		for (const { method, path, body } of cardRoutes(hidden)) {
			const r = await call(OUTSIDER, method, path, body)
			expect(r.status, `${method} ${path} (non-member)`).toBe(403)
		}
		// …and the same routes on the PUBLIC card, whose visibility class lets
		// role-less viewers past (CardVisibilityScope): still 403, so visibility
		// never grants what membership denies.
		for (const { method, path, body } of cardRoutes(state.cards.PUB.id)) {
			const r = await call(OUTSIDER, method, path, body)
			expect(r.status, `${method} ${path} (non-member, public card)`).toBe(403)
		}
		// A card id that does not exist stays 404 for them too — the 403s above
		// are the board's answer, not a per-id one.
		expect((await call(OUTSIDER, 'GET', '/cards/99999999')).status).toBe(404)
		expect((await call(OUTSIDER, 'DELETE', '/cards/99999999/purge')).status).toBe(404)

		// Nothing was written on the way to any of those refusals: both cards are
		// still there, still named the same, still live (the sweep includes
		// DELETE, purge, move-to-board and the template flag).
		for (const name of ['PUB', 'PROV']) {
			const after = await api(ADMIN, 'GET', `/cards/${state.cards[name].id}`)
			expect(after.title, `${name} survived the non-member sweep`).toBe(title(name))
			expect(after.isTemplate ?? false).toBe(false)
		}
		const board = await api(ADMIN, 'GET', `/boards/${state.boardId}`)
		expectTitles(board.cards, ['PUB', 'PROV', 'PRIV'])
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
