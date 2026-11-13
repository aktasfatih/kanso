// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { randomUUID } from 'node:crypto'
import { test, expect, api, currentAuth, me, BASE, ncLogin, provisionUser, deleteUser } from './helpers.js'

test.describe.serial('Personal CalDAV assignment filter', () => {
	test.use({ storageState: { cookies: [], origins: [] } })
	const token = randomUUID().slice(0, 8)
	const state = { boards: [], users: [], cards: {} }
	const prefs = () => `/boards/${state.boards[0]}/calendar-sync`
	const calendarPath = (boardId = state.boards[0]) => `app-generated--kanso--board-${boardId}/`
	const objectPath = (id, boardId) => `${calendarPath(boardId)}kanso-card-${id}.ics`
	const reader = () => state.users[0]
	const other = () => state.users[1]
	const owner = () => ({ user: me, auth: currentAuth })

	async function dav(user, method, path, body, headers = {}) {
		const response = await fetch(`${BASE}/remote.php/dav/calendars/${user.user}/${path}`, {
			method,
			headers: { Authorization: user.auth, 'Content-Type': 'application/xml', ...headers },
			body,
		})
		return { status: response.status, body: await response.text(), etag: response.headers.get('etag') }
	}

	function objectIds(body) {
		return [...new Set([...body.matchAll(/kanso-card-(\d+)\.ics/g)].map((m) => Number(m[1])))].sort((a, b) => a - b)
	}

	async function expectCalendar(user, ids, boardId = state.boards[0]) {
		const list = await dav(user, 'PROPFIND', calendarPath(boardId), undefined, { Depth: '1' })
		expect(list.status).toBe(207)
		expect(objectIds(list.body)).toEqual([...ids].sort((a, b) => a - b))
		const query = await dav(user, 'REPORT', calendarPath(boardId),
			'<c:calendar-query xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:d="DAV:"><d:prop><d:getetag/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VTODO"/></c:comp-filter></c:filter></c:calendar-query>', { Depth: '1' })
		expect(query.status, query.body).toBe(207)
		expect(objectIds(query.body)).toEqual([...ids].sort((a, b) => a - b))
	}

	test.beforeAll(async () => {
		for (const suffix of ['reader', 'other']) state.users.push(await provisionUser(`caldav_${token}_${suffix}`, 'Kanso-calendar-test!1'))
		for (const suffix of ['main', 'second']) {
			const board = await api.post('/boards', { title: `CalDAV assigned ${token} ${suffix}` })
			state.boards.push(board.id)
			await api.post(`/boards/${board.id}/acl`, { participant: reader().user, participantType: 'user', permission: 1, role: 'external' })
			const stack = await api.post('/stacks', { boardId: board.id, title: 'Tasks' })
			if (suffix === 'second') {
				const card = await api.post('/cards', { stackId: stack.id, title: 'Other board unassigned' })
				state.secondCard = card.id
				await api.patch(`/cards/${card.id}`, { duedate: '2026-11-20T09:00:00+00:00' })
				continue
			}
			for (const name of ['mine', 'unassigned', 'someoneElse', 'private', 'internal', 'completed', 'undated']) {
				const card = await api.post('/cards', { stackId: stack.id, title: `CalDAV ${name} ${token}` })
				state.cards[name] = card.id
				await api.patch(`/cards/${card.id}`, {
					duedate: name === 'undated' ? null : '2026-11-20T09:00:00+00:00',
					...(['private', 'internal'].includes(name) ? { visibility: name } : {}),
					...(name === 'completed' ? { done: true } : {}),
				})
				if (name !== 'unassigned') await api.put(`/cards/${card.id}/assignees/${name === 'someoneElse' ? me : reader().user}`)
			}
		}
		state.feed = await api.post(`/boards/${state.boards[0]}/calendar-feed`)
	})

	test.afterAll(async () => {
		for (const boardId of state.boards) await api.delete(`/boards/${boardId}`)
		for (const user of state.users) await deleteUser(user.user)
	})

	test('defaults to all readable due cards and denies a non-member', async () => {
		expect(await reader().api.get(prefs())).toEqual({ enabled: true, assignedOnly: false })
		await expectCalendar(reader(), ['mine', 'unassigned', 'someoneElse', 'completed'].map((key) => state.cards[key]))
		for (const key of ['private', 'internal', 'undated']) expect((await dav(reader(), 'GET', objectPath(state.cards[key]))).status).toBe(404)
		expect((await other().api.raw('GET', prefs())).status).toBe(403)
		expect((await other().api.raw('PUT', prefs(), { assignedOnly: true })).status).toBe(403)
		expect((await dav(other(), 'GET', objectPath(state.cards.mine))).status).toBe(404)
		await api.post(`/boards/${state.boards[0]}/acl`, { participant: other().user, participantType: 'user', permission: 1, role: 'internal' })
	})

	test('a read-only member enables the filter in settings and it persists', async ({ page }) => {
		const errors = []
		const automationReads = []
		page.on('request', (request) => {
			const path = new URL(request.url()).pathname.replace(/^\/index.php/, '')
			if (request.method() === 'GET' && path === `/apps/kanso/api/boards/${state.boards[0]}/automation-rules`) automationReads.push(path)
		})
		page.on('pageerror', (error) => errors.push(error.message))
		page.on('console', (message) => {
			if (message.type() !== 'error') return
			const path = new URL(message.location().url || BASE).pathname
			const text = message.text()
			const noUserStatus = path === '/ocs/v2.php/apps/user_status/api/v1/user_status'
				&& text === 'Failed to load resource: the server responded with a status of 404 (Not Found)'
			const coreStatusLog = path === '/dist/core-common.js'
				&& text.startsWith('[ERROR] core: Failed to load user status ')
				&& text.includes('Request failed with status code 404')
			if (!noUserStatus && !coreStatusLog) errors.push(text)
		})
		const before = await fetch(state.feed.url)
		const beforeBody = await before.text()
		state.oldObject = await dav(reader(), 'GET', objectPath(state.cards.unassigned))
		state.retainedObject = await dav(reader(), 'GET', objectPath(state.cards.mine))
		await ncLogin(page, reader())
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boards[0]}`)
		await page.getByRole('button', { name: 'More', exact: true }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /general/i }).click()
		const filter = page.getByRole('checkbox', { name: 'Only sync cards assigned to me' })
		await expect(filter).toBeEnabled()
		const saved = page.waitForResponse((r) => r.url().endsWith(prefs()) && r.request().method() === 'PUT')
		await page.getByText('Only sync cards assigned to me', { exact: true }).click()
		expect((await saved).status()).toBe(200)
		await expect(filter).toBeChecked()
		await expectCalendar(reader(), [state.cards.mine, state.cards.completed])
		const excluded = await dav(reader(), 'GET', objectPath(state.cards.unassigned), undefined, { 'If-None-Match': state.oldObject.etag })
		expect(excluded.status).toBe(404)
		const retained = await dav(reader(), 'GET', objectPath(state.cards.mine))
		expect(retained.etag).toBe(state.retainedObject.etag)
		expect(retained.body).toBe(state.retainedObject.body)
		await page.reload()
		await page.getByRole('button', { name: 'More', exact: true }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /general/i }).click()
		await expect(filter).toBeChecked()
		await expect(filter).toBeEnabled()
		const after = await fetch(state.feed.url)
		expect(after.status).toBe(200)
		expect(after.headers.get('etag')).toBe(before.headers.get('etag'))
		const eventIds = (body) => [...body.matchAll(/UID:([^\r\n]+)/g)].map((m) => m[1]).sort()
		expect(eventIds(await after.text())).toEqual(eventIds(beforeBody))
		expect(automationReads).toEqual([])
		expect(errors).toEqual([])
	})

	test('failed preference saves restore the checkbox and show an error', async ({ page }) => {
		await ncLogin(page, reader())
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boards[0]}`)
		await page.getByRole('button', { name: 'More', exact: true }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /general/i }).click()
		const filter = page.getByRole('checkbox', { name: 'Only sync cards assigned to me' })
		await expect(filter).toBeEnabled()
		await page.route(`**${prefs()}`, (route) => route.request().method() === 'PUT'
			? route.fulfill({ status: 403, contentType: 'application/json', body: '{"error":"denied"}' }) : route.continue())
		await page.getByText('Only sync cards assigned to me', { exact: true }).click()
		await expect(page.getByText('Could not save calendar preferences')).toBeVisible()
		await expect(filter).toBeChecked()
		expect(await reader().api.get(prefs())).toEqual({ enabled: true, assignedOnly: true })
	})

	test('the same users other boards and other users calendars are unaffected', async () => {
		await expectCalendar(reader(), [state.secondCard], state.boards[1])
		expect(await reader().api.get(`/boards/${state.boards[1]}/calendar-sync`)).toEqual({ enabled: true, assignedOnly: false })
		expect(await other().api.get(prefs())).toEqual({ enabled: true, assignedOnly: false })
		await expectCalendar(other(), ['mine', 'unassigned', 'someoneElse', 'internal', 'completed'].map((key) => state.cards[key]))
		await expectCalendar(owner(), ['mine', 'unassigned', 'someoneElse', 'private', 'internal', 'completed'].map((key) => state.cards[key]))
	})

	test('subsequent list, query, multiget and object refreshes reflect assignment changes', async () => {
		await api.put(`/cards/${state.cards.unassigned}/assignees/${reader().user}`)
		await expectCalendar(reader(), [state.cards.mine, state.cards.completed, state.cards.unassigned])
		expect((await dav(reader(), 'GET', objectPath(state.cards.unassigned))).status).toBe(200)
		await api.delete(`/cards/${state.cards.mine}/assignees/${reader().user}`)
		await expectCalendar(reader(), [state.cards.completed, state.cards.unassigned])
		const gone = await dav(reader(), 'GET', objectPath(state.cards.mine), undefined, { 'If-None-Match': state.retainedObject.etag })
		expect(gone.status).toBe(404)
		const href = (id) => `<d:href>/remote.php/dav/calendars/${reader().user}/${objectPath(id)}</d:href>`
		const multiget = (ids) => dav(reader(), 'REPORT', calendarPath(), '<c:calendar-multiget xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:d="DAV:"><d:prop><d:getetag/><c:calendar-data/></d:prop>'
			+ ids.map(href).join('') + '</c:calendar-multiget>')
		const multi = await multiget([state.cards.unassigned, state.cards.completed])
		expect(multi.status, multi.body).toBe(207)
		expect(multi.body).toContain(`SUMMARY:CalDAV unassigned ${token}`)
		for (const key of ['mine', 'private', 'internal']) {
			const excluded = await multiget([state.cards.unassigned, state.cards[key]])
			expect(excluded.status, excluded.body).toBe(207)
			expect(objectIds(excluded.body)).toEqual([state.cards.unassigned])
			expect(excluded.body).toContain(`SUMMARY:CalDAV unassigned ${token}`)
			expect(excluded.body).not.toContain(`SUMMARY:CalDAV ${key} ${token}`)
			expect((await dav(reader(), 'GET', objectPath(state.cards[key]))).status).toBe(404)
		}
	})

	test('enabled-only clients preserve the filter and mode changes preserve the hidden preference', async () => {
		expect(await reader().api.put(prefs(), { enabled: false })).toEqual({ enabled: false, assignedOnly: true })
		expect((await dav(reader(), 'PROPFIND', '', undefined, { Depth: '1' })).body).not.toContain(calendarPath().slice(0, -1))
		expect(await reader().api.put(prefs(), { assignedOnly: false })).toEqual({ enabled: false, assignedOnly: false })
		expect(await reader().api.put(prefs(), {})).toEqual({ enabled: false, assignedOnly: false })
		expect(await reader().api.put(prefs(), { enabled: true })).toEqual({ enabled: true, assignedOnly: false })
		await expectCalendar(reader(), ['mine', 'unassigned', 'someoneElse', 'completed'].map((key) => state.cards[key]))
	})

	test('the existing calendar refresh contract stays read-only without incremental sync tokens', async () => {
		const properties = await dav(reader(), 'PROPFIND', calendarPath(), '<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/"><d:prop><d:sync-token/><cs:getctag/></d:prop></d:propfind>', { Depth: '0' })
		expect(properties.status).toBe(207)
		expect(properties.body).toContain('404 Not Found')
		for (const syncToken of ['', 'http://sabre.io/ns/sync/legacy-token']) {
			const sync = await dav(reader(), 'REPORT', calendarPath(), `<d:sync-collection xmlns:d="DAV:"><d:sync-token>${syncToken}</d:sync-token><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>`)
			expect(sync.status).toBe(415)
			expect(sync.body).toContain('supported-report')
		}
		const content = 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:injected\r\nEND:VTODO\r\nEND:VCALENDAR\r\n'
		for (const user of [reader(), owner()]) {
			expect((await dav(user, 'PUT', `${calendarPath()}injected.ics`, content, { 'Content-Type': 'text/calendar' })).status).toBe(403)
			expect((await dav(user, 'DELETE', objectPath(state.cards.unassigned))).status).toBe(403)
		}
		expect((await api.get(`/cards/${state.cards.unassigned}`)).doneAt).toBe(0)
	})
})
