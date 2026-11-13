// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { randomUUID } from 'node:crypto'
import { test, expect, api, currentAuth, me, BASE, provisionUser, deleteUser } from './helpers.js'

test.describe.serial('CalDAV read-only adapter contracts', () => {
	test.use({ storageState: { cookies: [], origins: [] } })
	const token = randomUUID().slice(0, 8)
	const state = { boards: [], users: [], cards: {} }
	const reader = () => state.users[0]
	const owner = () => ({ user: me, auth: currentAuth })
	const calendarPath = (boardId = state.boards[0]) => `app-generated--kanso--board-${boardId}/`
	const objectPath = (id, boardId) => `${calendarPath(boardId)}kanso-card-${id}.ics`
	const href = (user, path) => `/remote.php/dav/calendars/${user.user}/${path}`
	const title = (name) => `CalDAV adapter ${name} ${token}`

	async function dav(user, method, path, body, headers = {}) {
		const response = await fetch(`${BASE}${href(user, path)}`, {
			method,
			headers: { Authorization: user.auth, 'Content-Type': 'application/xml', ...headers },
			body,
		})
		return { status: response.status, body: await response.text(), etag: response.headers.get('etag') }
	}

	async function multiget(user, paths) {
		return dav(user, 'REPORT', calendarPath(),
			'<c:calendar-multiget xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:d="DAV:"><d:prop><d:getetag/><c:calendar-data/></d:prop>'
			+ paths.map((path) => `<d:href>${href(user, path)}</d:href>`).join('') + '</c:calendar-multiget>', { Depth: '1' })
	}

	function responseIds(body) {
		return [...body.matchAll(/<d:href>[^<]*kanso-card-(\d+)\.ics<\/d:href>/g)].map((match) => Number(match[1]))
	}

	function expectTasks(result, names) {
		expect(result.status, result.body).toBe(207)
		expect(responseIds(result.body).sort((a, b) => a - b)).toEqual(names.map((name) => state.cards[name]).sort((a, b) => a - b))
		for (const name of Object.keys(state.cards)) {
			if (names.includes(name)) expect(result.body).toContain(title(name))
			else expect(result.body).not.toContain(title(name))
		}
	}

	test.beforeAll(async () => {
		for (const suffix of ['reader', 'outsider']) state.users.push(await provisionUser(`caladapter_${token}_${suffix}`, 'Kanso-calendar-test!1'))
		for (const suffix of ['main', 'foreign']) {
			const board = await api.post('/boards', { title: `CalDAV adapter ${token} ${suffix}` })
			state.boards.push(board.id)
			const stack = await api.post('/stacks', { boardId: board.id, title: 'Tasks' })
			if (suffix === 'foreign') {
				const card = await api.post('/cards', { stackId: stack.id, title: title('foreign') })
				state.cards.foreign = card.id
				await api.patch(`/cards/${card.id}`, { duedate: '2026-11-20T09:00:00+00:00' })
				continue
			}
			await api.post(`/boards/${board.id}/acl`, { participant: reader().user, participantType: 'user', permission: 1, role: 'external' })
			for (const name of ['due', 'start', 'neighbor', 'unrequested', 'private', 'internal', 'deleted']) {
				const card = await api.post('/cards', { stackId: stack.id, title: title(name) })
				state.cards[name] = card.id
				await api.patch(`/cards/${card.id}`, {
					[name === 'start' ? 'startDate' : 'duedate']: '2026-11-20T09:00:00+00:00',
					...(['private', 'internal'].includes(name) ? { visibility: name } : {}),
				})
				if (name !== 'unrequested') await api.put(`/cards/${card.id}/assignees/${reader().user}`)
				if (name === 'deleted') await api.delete(`/cards/${card.id}`)
			}
		}
		state.feed = await api.post(`/boards/${state.boards[0]}/calendar-feed`)
	})

	test.afterAll(async () => {
		for (const boardId of state.boards) await api.delete(`/boards/${boardId}`)
		for (const user of state.users) await deleteUser(user.user)
	})

	test('retains only requested readable tasks beside missing, hidden, deleted and foreign children', async () => {
		const mixed = ['due', 'start', 'private', 'internal', 'deleted', 'foreign'].map((name) => objectPath(state.cards[name]))
		expectTasks(await multiget(reader(), [...mixed, objectPath(999999999), objectPath(state.cards.due)]), ['due', 'start'])
		expectTasks(await multiget(reader(), ['private', 'internal', 'deleted', 'foreign'].map((name) => objectPath(state.cards[name])).concat(objectPath(999999999))), [])
		for (const name of ['private', 'internal', 'deleted', 'foreign']) expect((await dav(reader(), 'GET', objectPath(state.cards[name]))).status, name).toBe(404)
		expect((await dav(reader(), 'GET', objectPath(999999999))).status).toBe(404)
		const malformed = [
			`${calendarPath()}kanso-card-0${state.cards.due}.ics`,
			`${calendarPath()}kanso-card-${state.cards.due}.ICS`,
			`${calendarPath()}kanso-card-+${state.cards.due}.ics`,
			`${calendarPath()}kanso-card-999999999999999999999999999.ics`,
			`${calendarPath()}kanso-card-${state.cards.due}.ics.invalid`,
		]
		expectTasks(await multiget(reader(), [...malformed, objectPath(state.cards.start)]), ['start'])
		const outsider = state.users[1]
		const denied = await multiget(outsider, [objectPath(state.cards.due)])
		expect(denied.status).toBe(404)
		expect(denied.body).not.toContain(title('due'))
		const foreignPrincipal = await dav(reader(), 'REPORT', calendarPath(),
			'<c:calendar-multiget xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:d="DAV:"><d:prop><c:calendar-data/></d:prop>'
			+ `<d:href>${href(owner(), objectPath(state.cards.private))}</d:href></c:calendar-multiget>`)
		expect(foreignPrincipal.status).toBe(404)
		expect(foreignPrincipal.body).not.toContain(title('private'))
		const traversal = await multiget(reader(), [`${calendarPath()}../app-generated--kanso--board-${state.boards[1]}/kanso-card-${state.cards.foreign}.ics`])
		expect(traversal.status).toBe(404)
		expect(traversal.body).not.toContain(title('foreign'))
	})

	test('refreshes assignment and personal preferences without losing readable neighbors or cached object ETags', async () => {
		const neighbor = await dav(reader(), 'GET', objectPath(state.cards.neighbor))
		const retained = await dav(reader(), 'GET', objectPath(state.cards.start))
		expect(neighbor.status).toBe(200)
		expect(retained.status).toBe(200)
		const feed = await fetch(state.feed.url)
		expect(feed.status).toBe(200)
		const feedBody = await feed.text()
		await reader().api.put(`/boards/${state.boards[0]}/calendar-sync`, { assignedOnly: true })
		await api.delete(`/cards/${state.cards.neighbor}/assignees/${reader().user}`)
		expectTasks(await multiget(reader(), ['due', 'start', 'neighbor', 'unrequested', 'private'].map((name) => objectPath(state.cards[name]))), ['due', 'start'])
		expect((await dav(reader(), 'GET', objectPath(state.cards.neighbor), undefined, { 'If-None-Match': neighbor.etag })).status).toBe(404)
		expect((await dav(reader(), 'GET', objectPath(state.cards.start), undefined, { 'If-None-Match': retained.etag })).status).toBe(304)
		expect(await dav(reader(), 'GET', objectPath(state.cards.start))).toEqual(retained)
		expectTasks(await multiget(owner(), ['neighbor', 'unrequested', 'private'].map((name) => objectPath(state.cards[name]))), ['neighbor', 'unrequested', 'private'])
		await api.put(`/cards/${state.cards.neighbor}/assignees/${reader().user}`)
		expectTasks(await multiget(reader(), ['start', 'neighbor'].map((name) => objectPath(state.cards[name]))), ['start', 'neighbor'])
		expect(await reader().api.put(`/boards/${state.boards[0]}/calendar-sync`, { enabled: false })).toEqual({ enabled: false, assignedOnly: true })
		const home = await dav(reader(), 'PROPFIND', '', undefined, { Depth: '1' })
		expect(home.status).toBe(207)
		expect(home.body).not.toContain(calendarPath())
		expectTasks(await multiget(reader(), [objectPath(state.cards.due)]), ['due'])
		await reader().api.put(`/boards/${state.boards[0]}/calendar-sync`, { enabled: true, assignedOnly: false })
		expectTasks(await multiget(reader(), [objectPath(state.cards.unrequested)]), ['unrequested'])
		const refreshedFeed = await fetch(state.feed.url)
		expect(refreshedFeed.status).toBe(200)
		const withoutStamp = (body) => body.replace(/^DTSTAMP:[^\r\n]*\r?\n/gm, '')
		expect(withoutStamp(await refreshedFeed.text())).toBe(withoutStamp(feedBody))
	})

	test('exposes only read privileges and rejects owner and sharee content writes before scheduling', async () => {
		for (const user of [owner(), reader()]) {
			for (const name of ['due', 'start']) {
				const path = objectPath(state.cards[name])
				const initial = await dav(user, 'GET', path)
				const card = await api.get(`/cards/${state.cards[name]}`)
				expect(initial.status).toBe(200)
				expect((await dav(user, 'HEAD', path)).status).toBe(200)
				const props = await dav(user, 'PROPFIND', path,
					'<d:propfind xmlns:d="DAV:"><d:prop><d:owner/><d:getetag/><d:current-user-privilege-set/></d:prop></d:propfind>', { Depth: '0' })
				expect(props.status, props.body).toBe(207)
				expect(props.body).toContain(`principals/users/${user.user}/`)
				expect(props.body).toContain(initial.etag.replaceAll('"', '&quot;'))
				expect(props.body).toContain('<d:read/>')
				for (const privilege of ['write', 'write-content', 'write-properties', 'write-acl', 'bind', 'unbind', 'all']) expect(props.body).not.toContain(`<d:${privilege}/>`)
				for (const body of [initial.body.replace(title(name), 'Forbidden replacement'),
					'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Kanso//test//EN\r\nBEGIN:VTODO\r\nUID:denied-new-uid\r\nSUMMARY:Denied\r\nEND:VTODO\r\nEND:VCALENDAR\r\n']) {
					const write = await dav(user, 'PUT', path, body, { 'Content-Type': 'text/calendar', 'If-Match': initial.etag })
					expect(write.status, write.body).toBe(403)
					expect(write.body).not.toContain('isShared')
				}
				const acl = await dav(user, 'ACL', path, '<d:acl xmlns:d="DAV:"><d:ace><d:principal><d:authenticated/></d:principal><d:grant><d:privilege><d:all/></d:privilege></d:grant></d:ace></d:acl>')
				expect(acl.status, acl.body).toBe(403)
				expect((await dav(user, 'DELETE', path)).status).toBe(403)
				expect(await dav(user, 'GET', path)).toEqual(initial)
				expect(await api.get(`/cards/${state.cards[name]}`)).toEqual(card)
			}
			expect((await dav(user, 'PUT', `${calendarPath()}new.ics`, 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:denied\r\nEND:VTODO\r\nEND:VCALENDAR\r\n', { 'Content-Type': 'text/calendar' })).status).toBe(403)
		}
		const query = await dav(reader(), 'REPORT', calendarPath(), '<c:calendar-query xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:d="DAV:"><d:prop><d:getetag/><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VTODO"/></c:comp-filter></c:filter></c:calendar-query>', { Depth: '1' })
		expectTasks(query, ['due', 'start', 'neighbor', 'unrequested'])
		const patch = await dav(reader(), 'PROPPATCH', calendarPath(), '<d:propertyupdate xmlns:d="DAV:" xmlns:o="http://owncloud.org/ns"><d:set><d:prop><o:calendar-enabled>1</o:calendar-enabled></d:prop></d:set></d:propertyupdate>')
		expect(patch.status, patch.body).toBe(207)
		expect(patch.body).toContain('200 OK')
	})
})
