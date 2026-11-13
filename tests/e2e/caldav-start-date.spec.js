// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { randomUUID } from 'node:crypto'
import { test, expect, api, currentAuth, me, BASE, provisionUser, deleteUser } from './helpers.js'

test.describe.serial('CalDAV start-dated cards', () => {
	test.use({ storageState: { cookies: [], origins: [] } })
	const token = randomUUID().slice(0, 8)
	const state = { boards: [], users: [], cards: {} }
	const reader = () => state.users[0]
	const owner = () => ({ user: me, auth: currentAuth })
	const calendarPath = (boardId = state.boards[0]) => `app-generated--kanso--board-${boardId}/`
	const objectPath = (id, boardId) => `${calendarPath(boardId)}kanso-card-${id}.ics`
	const preferences = () => `/boards/${state.boards[0]}/calendar-sync`

	async function dav(user, method, path, body, headers = {}) {
		const response = await fetch(`${BASE}/remote.php/dav/calendars/${user.user}/${path}`, {
			method,
			headers: { Authorization: user.auth, 'Content-Type': 'application/xml', ...headers },
			body,
		})
		return { status: response.status, body: await response.text(), etag: response.headers.get('etag') }
	}

	function objectIds(body) {
		return [...new Set([...body.matchAll(/kanso-card-(\d+)\.ics/g)].map((match) => Number(match[1])))]
	}

	async function expectCalendar(user, ids, boardId = state.boards[0]) {
		const list = await dav(user, 'PROPFIND', calendarPath(boardId), undefined, { Depth: '1' })
		expect(list.status, list.body).toBe(207)
		expect(objectIds(list.body).sort((a, b) => a - b)).toEqual([...ids].sort((a, b) => a - b))
		const query = await dav(user, 'REPORT', calendarPath(boardId),
			'<c:calendar-query xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:d="DAV:"><d:prop><d:getetag/><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VTODO"/></c:comp-filter></c:filter></c:calendar-query>', { Depth: '1' })
		expect(query.status, query.body).toBe(207)
		expect(objectIds(query.body).sort((a, b) => a - b)).toEqual([...ids].sort((a, b) => a - b))
		return list.body
	}

	test.beforeAll(async () => {
		for (const suffix of ['reader', 'outsider']) state.users.push(await provisionUser(`calstart_${token}_${suffix}`, 'Kanso-calendar-test!1'))
		for (const suffix of ['main', 'second']) {
			const board = await api.post('/boards', { title: `CalDAV start ${token} ${suffix}` })
			state.boards.push(board.id)
			await api.post(`/boards/${board.id}/acl`, { participant: reader().user, participantType: 'user', permission: 1, role: 'external' })
			const stack = await api.post('/stacks', { boardId: board.id, title: 'Tasks' })
			if (suffix === 'second') {
				const card = await api.post('/cards', { stackId: stack.id, title: `Other board start ${token}` })
				state.secondCard = card.id
				await api.patch(`/cards/${card.id}`, { startDate: '2026-11-20T09:30:00+00:00' })
				continue
			}
			for (const name of ['start', 'allDay', 'completed', 'unassigned', 'someoneElse', 'private', 'internal', 'archived', 'deleted', 'template', 'dateless', 'dueEarly', 'dueTie', 'bothDates']) {
				const card = await api.post('/cards', { stackId: stack.id, title: `CalDAV start ${name} ${token}` })
				state.cards[name] = card.id
				const dueOnly = ['dueEarly', 'dueTie'].includes(name)
				await api.patch(`/cards/${card.id}`, {
					startDate: name === 'dateless' || dueOnly ? null : '2026-11-20T09:30:00+00:00',
					duedate: dueOnly ? '2026-11-21T09:30:00+00:00' : name === 'bothDates' ? '2026-11-22T09:30:00+00:00' : null,
					allDay: name === 'allDay',
					...(['private', 'internal'].includes(name) ? { visibility: name } : {}),
					...(name === 'archived' ? { archived: true } : {}),
					...(name === 'completed' ? { done: true } : {}),
				})
				if (name !== 'unassigned') await api.put(`/cards/${card.id}/assignees/${name === 'someoneElse' ? me : reader().user}`)
				if (name === 'template') await api.put(`/cards/${card.id}/template`, { isTemplate: true })
				if (name === 'deleted') await api.delete(`/cards/${card.id}`)
			}
		}
		state.feed = await api.post(`/boards/${state.boards[0]}/calendar-feed`)
	})

	test.afterAll(async () => {
		for (const boardId of state.boards) await api.delete(`/boards/${boardId}`)
		for (const user of state.users) await deleteUser(user.user)
	})

	test('includes readable start-only tasks and preserves board, visibility and lifecycle filters', async () => {
		const keys = ['start', 'allDay', 'completed', 'unassigned', 'someoneElse', 'dueEarly', 'dueTie', 'bothDates']
		const ids = keys.map((key) => state.cards[key])
		const list = await expectCalendar(reader(), ids)
		expect(objectIds(list).filter((id) => ['dueEarly', 'dueTie', 'bothDates'].some((key) => state.cards[key] === id)))
			.toEqual(['dueEarly', 'dueTie', 'bothDates'].map((key) => state.cards[key]))
		expect(objectIds(await expectCalendar(reader(), ids))).toEqual(objectIds(list))
		for (const key of ['private', 'internal', 'archived', 'deleted', 'template', 'dateless']) {
			expect((await dav(reader(), 'GET', objectPath(state.cards[key]))).status, key).toBe(404)
		}
		await expectCalendar(owner(), [...ids, state.cards.private, state.cards.internal])
		const outsider = state.users[1]
		expect((await dav(outsider, 'GET', objectPath(state.cards.start))).status).toBe(404)
		const home = await dav(outsider, 'PROPFIND', '', undefined, { Depth: '1' })
		expect(home.status).toBe(207)
		expect(home.body).not.toContain(calendarPath())
		expect((await dav(reader(), 'GET', objectPath(state.secondCard))).status).toBe(404)
		await expectCalendar(reader(), [state.secondCard], state.boards[1])
	})

	test('emits timed and all-day DTSTART without DUE and keeps completed tasks and conditional reads', async () => {
		for (const [key, start] of [['start', 'DTSTART:20261120T093000Z'], ['allDay', 'DTSTART;VALUE=DATE:20261120']]) {
			const object = await dav(reader(), 'GET', objectPath(state.cards[key]))
			expect(object.status).toBe(200)
			expect(object.body).toContain(start)
			for (const property of ['DUE', 'DTEND', 'DURATION']) expect(object.body).not.toMatch(new RegExp(`(?:^|\r?\n)${property}[:;]`))
			expect(object.etag).toBeTruthy()
			const repeated = await dav(reader(), 'GET', objectPath(state.cards[key]))
			expect(repeated.body).toBe(object.body)
			expect(repeated.etag).toBe(object.etag)
			expect((await dav(reader(), 'GET', objectPath(state.cards[key]), undefined, { 'If-None-Match': object.etag })).status).toBe(304)
		}
		const completed = await dav(reader(), 'GET', objectPath(state.cards.completed))
		expect(completed.status).toBe(200)
		expect(completed.body).toContain('STATUS:COMPLETED')
		expect(completed.body).toContain('DTSTART:20261120T093000Z')
		expect((await dav(reader(), 'GET', objectPath(state.cards.bothDates))).body).toContain('DUE:20261122T093000Z')
	})

	test('applies the personal assignment filter to start-only cards without changing other calendars or public ICS', async () => {
		const feed = await fetch(state.feed.url)
		expect(feed.status).toBe(200)
		const feedBody = await feed.text()
		expect(feedBody).toContain(`CalDAV start dueEarly ${token}`)
		for (const key of ['start', 'allDay', 'completed', 'unassigned', 'dateless']) expect(feedBody).not.toContain(`CalDAV start ${key} ${token}`)
		const before = await dav(reader(), 'GET', objectPath(state.cards.start))
		expect(await reader().api.put(preferences(), { assignedOnly: true })).toEqual({ enabled: true, assignedOnly: true })
		await expectCalendar(reader(), ['start', 'allDay', 'completed', 'dueEarly', 'dueTie', 'bothDates'].map((key) => state.cards[key]))
		for (const key of ['unassigned', 'someoneElse', 'private', 'internal', 'dateless']) expect((await dav(reader(), 'GET', objectPath(state.cards[key]))).status, key).toBe(404)
		await expectCalendar(reader(), [state.secondCard], state.boards[1])
		expect((await dav(owner(), 'GET', objectPath(state.cards.unassigned))).status).toBe(200)
		const retained = await dav(reader(), 'GET', objectPath(state.cards.start))
		expect(retained.body).toBe(before.body)
		expect(retained.etag).toBe(before.etag)
		const refreshedFeed = await fetch(state.feed.url)
		expect(refreshedFeed.status).toBe(200)
		const withoutStamps = (body) => body.replace(/^DTSTAMP:[^\r\n]*\r?\n/gm, '')
		expect(withoutStamps(await refreshedFeed.text())).toBe(withoutStamps(feedBody))
		await reader().api.put(preferences(), { assignedOnly: false })
	})

	test('keeps start-only tasks read-only and removes them when their final date is cleared', async () => {
		const content = 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:injected\r\nEND:VTODO\r\nEND:VCALENDAR\r\n'
		for (const user of [reader(), owner()]) {
			expect((await dav(user, 'PUT', `${calendarPath()}injected.ics`, content, { 'Content-Type': 'text/calendar' })).status).toBe(403)
			expect((await dav(user, 'DELETE', objectPath(state.cards.start))).status).toBe(403)
		}
		expect((await api.get(`/cards/${state.cards.start}`)).startDate).toBeTruthy()
		await api.patch(`/cards/${state.cards.start}`, { startDate: '' })
		expect((await dav(reader(), 'GET', objectPath(state.cards.start))).status).toBe(404)
		await expectCalendar(reader(), ['allDay', 'completed', 'unassigned', 'someoneElse', 'dueEarly', 'dueTie', 'bothDates'].map((key) => state.cards[key]))
	})
})
