// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const KAN = BASE + '/index.php/apps/kanso/api'
const DAV = BASE + '/remote.php/dav/calendars/admin'
const HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from('admin:admin').toString('base64')

async function kanso(method, path, body) {
	const r = await fetch(KAN + path, {
		method,
		headers: { ...HEADERS, Authorization: AUTH },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`${method} ${path} → ${r.status}: ${await r.text()}`)
	const text = await r.text()
	return text ? JSON.parse(text) : null
}

async function dav(method, path, extraHeaders = {}) {
	const r = await fetch(DAV + path, {
		method,
		headers: { Authorization: AUTH, ...extraHeaders },
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
	const title = 'E2E CalDAV ' + Math.floor(Date.now() / 1000)
	const cardTitle = 'Sync me'
	// A stable calendar uri (board-<id>) prefixed by the DAV app's app-generated tag.
	let calUri = ''
	let objUri = ''

	test.beforeAll(async () => {
		const board = await kanso('POST', '/boards', { title, color: '0082c9' })
		boardId = board.id
		const stack = await kanso('POST', '/stacks', { boardId, title: 'To do' })
		const card = await kanso('POST', '/cards', { stackId: stack.id, title: cardTitle })
		cardId = card.id
		await kanso('PATCH', `/cards/${cardId}`, { duedate: '2026-08-15T09:30:00+00:00' })

		calUri = `app-generated--kanso--board-${boardId}`
		objUri = `/${calUri}/kanso-card-${cardId}.ics`
	})

	test.afterAll(async () => {
		if (boardId) await kanso('DELETE', `/boards/${boardId}`).catch(() => {})
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
		const r = await fetch(DAV + `/${calUri}/injected.ics`, {
			method: 'PUT',
			headers: { Authorization: AUTH, 'Content-Type': 'text/calendar' },
			body: 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:x\r\nEND:VTODO\r\nEND:VCALENDAR\r\n',
		})
		// Sabre maps the Forbidden the calendar throws to 403 (never 201/204).
		expect(r.status).toBe(403)
	})
})
