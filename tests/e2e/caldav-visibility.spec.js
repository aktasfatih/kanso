// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, makeApi, currentAuth, me, BASE } from './helpers.js'

// Per-auth clients built lazily and cached, so currentAuth / peer.auth (read at
// call time) each resolve to the right worker identity under isolation.
const clients = new Map()
function clientFor(auth) {
	if (!clients.has(auth)) clients.set(auth, makeApi(auth))
	return clients.get(auth)
}

// Route (auth, method, path, body) → the matching client's throwing send().
function api(auth, method, path, body) {
	return clientFor(auth).send(method, path, body)
}

// GET a card's VTODO from a principal's own board calendar.
async function davGet(auth, principal, calUri, cardId) {
	const r = await fetch(`${BASE}/remote.php/dav/calendars/${principal}/${calUri}/kanso-card-${cardId}.ics`, {
		method: 'GET',
		headers: { Authorization: auth },
	})
	return { status: r.status, body: await r.text() }
}

// Security / multi-tenancy for the CalDAV VTODO calendars (#3534 / issue #49).
// The feed is per-principal AND per-card-visibility: a board member only receives
// the due cards they'd see on the board, and a card hidden from them (private,
// owner-only) must never reach their CalDAV feed even though they CAN see the
// board. Plus: the per-user calendar-sync toggle is membership-gated. Two real
// users over the real DAV/HTTP stack.
test.describe.serial('CalDAV visibility + endpoint permissions', () => {
	// Multi-user: BasicAuth per request, do not inherit the shared admin session.
	test.use({ storageState: { cookies: [], origins: [] } })

	const token = 'cv' + Math.floor(Date.now() / 1000)
	const state = { boardId: 0, otherBoardId: 0, calUri: '', pubId: 0, privId: 0 }

	test.beforeAll(async ({ peer }) => {
		const board = await api(currentAuth, 'POST', '/boards', { title: 'CalDAV visibility ' + token })
		state.boardId = board.id
		state.calUri = `app-generated--kanso--board-${board.id}`
		const stack = await api(currentAuth, 'POST', '/stacks', { boardId: board.id, title: 'Lane' })

		// peer is an INTERNAL member — sees public + internal cards, never private.
		await api(currentAuth, 'POST', `/boards/${board.id}/acl`, {
			participant: peer.user, participantType: 'user', permission: 1, role: 'internal',
		})

		// A public card both can see, and a private (owner-only) card only the owner
		// can see. Both have due dates, so both are candidates for the feed.
		const pub = await api(currentAuth, 'POST', '/cards', { stackId: stack.id, title: 'pub ' + token })
		state.pubId = pub.id
		await api(currentAuth, 'PATCH', `/cards/${pub.id}`, { duedate: '2026-08-15T09:30:00+00:00' })

		const priv = await api(currentAuth, 'POST', '/cards', { stackId: stack.id, title: 'secret ' + token })
		state.privId = priv.id
		await api(currentAuth, 'PATCH', `/cards/${priv.id}`, { duedate: '2026-08-16T09:30:00+00:00', visibility: 'private' })

		// A second board the owner does NOT share with the peer (for the endpoint test).
		const other = await api(currentAuth, 'POST', '/boards', { title: 'CalDAV unshared ' + token })
		state.otherBoardId = other.id
	})

	test.afterAll(async () => {
		for (const id of [state.boardId, state.otherBoardId]) {
			if (id) await api(currentAuth, 'DELETE', `/boards/${id}`).catch(() => {})
		}
	})

	test('a member never receives a card hidden from them (private stays owner-only)', async ({ peer }) => {
		// The owner sees their own private card over CalDAV…
		expect((await davGet(currentAuth, me, state.calUri, state.privId)).status).toBe(200)

		// …the member sees the public card…
		const memberPub = await davGet(peer.auth, peer.user, state.calUri, state.pubId)
		expect(memberPub.status).toBe(200)
		expect(memberPub.body).toContain(`pub ${token}`)

		// …but NOT the private one: existence-safe 404, never the card body.
		const memberPriv = await davGet(peer.auth, peer.user, state.calUri, state.privId)
		expect(memberPriv.status).toBe(404)
		expect(memberPriv.body).not.toContain(`secret ${token}`)
	})

	test('a non-member cannot toggle calendar-sync for a board they cannot see', async ({ peer }) => {
		const r = await clientFor(peer.auth).raw('PUT', `/boards/${state.otherBoardId}/calendar-sync`, { enabled: false })
		// NotAMemberException → NotPermittedException → 403 (never silently applied).
		expect(r.status).toBe(403)
	})
})
