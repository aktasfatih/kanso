// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, currentAuth, me, BASE } from './helpers.js'

// The current user's own CalDAV calendar-home. Built at CALL time (me is a live
// binding rebound per worker under isolation), so the principal path segment
// matches the acting user.
const davHome = () => `${BASE}/remote.php/dav/calendars/${me}`

// Kept local: a raw DAV client (PROPFIND/GET/PROPPATCH against the CalDAV
// endpoint) — not the Kanso API, so the shared api client does not apply.
async function dav(method, path, extraHeaders = {}) {
	const r = await fetch(davHome() + path, {
		method,
		headers: { Authorization: currentAuth, ...extraHeaders },
	})
	return { status: r.status, body: await r.text() }
}

// Read-only CalDAV VTODO calendars, one per board (#3534 / issue #49). Cards with
// a due date surface to the user's CalDAV principal as VTODOs, so Nextcloud
// Calendar and DAVx5 auto-discover them (the way Deck's board calendars work).
// This drives the REAL DAV stack over HTTP: it proves the info.xml <sabre>
// registration is picked up, the board calendar is listed in the calendar-home,
// and the per-card VTODO object is fetchable and well-formed.
test.describe('CalDAV board calendar (read-only VTODOs)', () => {
	let boardId = 0
	let cardId = 0
	let allDayCardId = 0
	let doneCardId = 0
	const title = 'E2E CalDAV ' + Math.floor(Date.now() / 1000)
	const cardTitle = 'Sync me'
	// A stable calendar uri (board-<id>) prefixed by the DAV app's app-generated tag.
	let calUri = ''
	let objUri = ''

	test.beforeAll(async () => {
		const board = await api.send('POST', '/boards', { title, color: '0082c9' })
		boardId = board.id
		const stack = await api.send('POST', '/stacks', { boardId, title: 'To do' })
		const card = await api.send('POST', '/cards', { stackId: stack.id, title: cardTitle })
		cardId = card.id
		await api.send('PATCH', `/cards/${cardId}`, { duedate: '2026-08-15T09:30:00+00:00' })

		// An all-day due card → the VTODO must carry a DATE-valued DUE (no time).
		const allDay = await api.send('POST', '/cards', { stackId: stack.id, title: 'All day' })
		allDayCardId = allDay.id
		await api.send('PATCH', `/cards/${allDayCardId}`, { duedate: '2026-08-20T00:00:00+00:00', allDay: true })

		// A completed card → the VTODO must be STATUS:COMPLETED.
		const done = await api.send('POST', '/cards', { stackId: stack.id, title: 'Finished' })
		doneCardId = done.id
		await api.send('PATCH', `/cards/${doneCardId}`, { duedate: '2026-08-10T09:00:00+00:00', done: true })

		calUri = `app-generated--kanso--board-${boardId}`
		objUri = `/${calUri}/kanso-card-${cardId}.ics`
	})

	test.afterAll(async () => {
		if (boardId) await api.send('DELETE', `/boards/${boardId}`).catch(() => {})
	})

	test('the board calendar is discoverable in the CalDAV calendar-home', async () => {
		// PROPFIND the user's calendar-home; the external board calendar must be a child.
		const { status, body } = await dav('PROPFIND', '/', { Depth: '1' })
		expect(status).toBe(207)
		expect(body).toContain(calUri)
	})

	test('the calendar exposes the due card as a fetchable VTODO', async () => {
		const { status, body } = await dav('GET', objUri)
		expect(status).toBe(200)
		expect(body).toContain('BEGIN:VTODO')
		expect(body).toContain(`SUMMARY:${cardTitle}`)
		// Timed due date normalised to a UTC instant.
		expect(body).toContain('DUE:20260815T093000Z')
		expect(body).toContain('STATUS:NEEDS-ACTION')
		// Stable UID so a client updates the same task across syncs.
		expect(body).toContain(`UID:kanso-card-${cardId}@board-${boardId}`)
	})

	test('the calendar is read-only: a PUT of a new object is rejected', async () => {
		const r = await fetch(davHome() + `/${calUri}/injected.ics`, {
			method: 'PUT',
			headers: { Authorization: currentAuth, 'Content-Type': 'text/calendar' },
			body: 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:x\r\nEND:VTODO\r\nEND:VCALENDAR\r\n',
		})
		// Sabre maps the Forbidden the calendar throws to 403 (never 201/204).
		expect(r.status).toBe(403)
	})

	test('an all-day due card is a DATE-valued VTODO (no time component)', async () => {
		const { status, body } = await dav('GET', `/${calUri}/kanso-card-${allDayCardId}.ics`)
		expect(status).toBe(200)
		expect(body).toContain('DUE;VALUE=DATE:20260820')
		// Must not have emitted a timed DUE for an all-day card.
		expect(body).not.toContain('DUE:20260820T')
	})

	test('a completed card is a STATUS:COMPLETED VTODO', async () => {
		const { status, body } = await dav('GET', `/${calUri}/kanso-card-${doneCardId}.ics`)
		expect(status).toBe(200)
		expect(body).toContain('STATUS:COMPLETED')
		expect(body).toContain('PERCENT-COMPLETE:100')
	})

	test('visibility can be toggled: PROPPATCH calendar-enabled is accepted', async () => {
		// Clicking a calendar in the Calendar app toggles its visibility via this
		// PROPPATCH. The calendar grants write-properties, so Nextcloud persists it
		// as a per-user property (it never touches card data) - it must NOT 403.
		const body = '<?xml version="1.0"?>\n'
			+ '<d:propertyupdate xmlns:d="DAV:" xmlns:x="http://owncloud.org/ns">'
			+ '<d:set><d:prop><x:calendar-enabled>0</x:calendar-enabled></d:prop></d:set>'
			+ '</d:propertyupdate>'
		const r = await fetch(davHome() + `/${calUri}/`, {
			method: 'PROPPATCH',
			headers: { Authorization: currentAuth, 'Content-Type': 'application/xml' },
			body,
		})
		expect(r.status).toBe(207)
		const text = await r.text()
		expect(text).toContain('calendar-enabled')
		expect(text).toContain('200 OK')
		expect(text).not.toContain('403')
	})
})

// Per-person access control (#3534 / issue #49). The CalDAV surface is scoped to
// board membership: a user who is NOT a member of a board must never see that
// board's calendar in their own calendar-home, nor be able to fetch its cards -
// and sharing the board with them must grant access. This drives two real
// principals (admin owns the board; `tester` is the outsider) over HTTP.
test.describe.serial('CalDAV board calendar access control', () => {
	// Multi-user: BasicAuth per request, and must NOT inherit the shared admin
	// session (see the e2e storageState guard).
	test.use({ storageState: { cookies: [], origins: [] } })

	let boardId = 0
	let cardId = 0
	let calUri = ''

	// The outsider is the worker-scoped peer; its DAV principal path segment and
	// auth are read from the fixture at call time.
	async function peerDav(peer, method, path) {
		const dav = `${BASE}/remote.php/dav/calendars/${peer.user}`
		const r = await fetch(dav + path, { method, headers: { Authorization: peer.auth, Depth: '1' } })
		return { status: r.status, body: await r.text() }
	}

	test.beforeAll(async () => {
		const board = await api.send('POST', '/boards', { title: 'E2E CalDAV ACL ' + Math.floor(Date.now() / 1000) })
		boardId = board.id
		const stack = await api.send('POST', '/stacks', { boardId, title: 'To do' })
		const card = await api.send('POST', '/cards', { stackId: stack.id, title: 'members only' })
		cardId = card.id
		await api.send('PATCH', `/cards/${cardId}`, { duedate: '2026-08-15T09:30:00+00:00' })
		calUri = `app-generated--kanso--board-${boardId}`
	})

	test.afterAll(async () => {
		if (boardId) await api.send('DELETE', `/boards/${boardId}`).catch(() => {})
	})

	test('a non-member never sees the board calendar and cannot fetch its cards', async ({ peer }) => {
		const home = await peerDav(peer, 'PROPFIND', '/')
		expect(home.status).toBe(207)
		expect(home.body).not.toContain(calUri)
		// The card object is existence-safe: not found (never 200) for a non-member.
		const obj = await peerDav(peer, 'GET', `/${calUri}/kanso-card-${cardId}.ics`)
		expect(obj.status).toBe(404)
	})

	test('sharing the board grants the member the calendar', async ({ peer }) => {
		await api.send('POST', `/boards/${boardId}/acl`, {
			participant: peer.user, participantType: 'user', permission: 1, role: 'internal',
		})
		const home = await peerDav(peer, 'PROPFIND', '/')
		expect(home.status).toBe(207)
		expect(home.body).toContain(calUri)
		const obj = await peerDav(peer, 'GET', `/${calUri}/kanso-card-${cardId}.ics`)
		expect(obj.status).toBe(200)
		expect(obj.body).toContain('SUMMARY:members only')
	})
})
