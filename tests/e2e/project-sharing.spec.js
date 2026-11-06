// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, provisionUser, deleteUser, API, BASE, OCS, adminAuth } from './helpers.js'

const VIEW = 1
const EDIT = 3

function projectUrl(id) {
	return `${BASE}/index.php/apps/kanso#/projects/${id}`
}

async function openProjectMenu(page) {
	await page.locator('.project-view__header-actions button[aria-haspopup]').click()
}

async function adminOcs(path, params) {
	const r = await fetch(`${OCS}${path}`, {
		method: 'POST',
		headers: {
			'OCS-APIREQUEST': 'true',
			Authorization: adminAuth,
			'Content-Type': 'application/x-www-form-urlencoded',
		},
		body: new URLSearchParams(params),
	})
	if (!r.ok) throw new Error(`${path} → ${r.status}`)
}

async function adminDeleteGroup(group) {
	const r = await fetch(`${OCS}/groups/${encodeURIComponent(group)}`, {
		method: 'DELETE',
		headers: { 'OCS-APIREQUEST': 'true', Authorization: adminAuth },
	})
	if (!r.ok) throw new Error(`delete group → ${r.status}`)
}

async function holdsEmpty(list, message, windowMs = 4_000) {
	const deadline = Date.now() + windowMs
	while (Date.now() < deadline) {
		await new Promise((resolve) => setTimeout(resolve, 200))
		expect(list, message).toEqual([])
	}
}

test.describe('Project sharing', () => {
	// A second identity logs in explicitly, so this must not inherit the shared session.
	test.use({ storageState: { cookies: [], origins: [] }, viewport: { width: 1600, height: 900 } })

	const stamp = Date.now()
	const projectTitle = 'Shared project ' + stamp
	const state = { projectId: 0, boards: [], visibleCardId: 0, hiddenCardId: 0, privateCardId: 0, spareCardId: 0 }

	async function shareWith(participant, participantType, permission) {
		return api.post(`/projects/${state.projectId}/shares`, { participant, participantType, permission })
	}

	function conditionalBoardRead(viewer, etag) {
		return fetch(`${API}/boards/${state.boards[0]}`, {
			headers: { 'OCS-APIREQUEST': 'true', Authorization: viewer.auth, 'If-None-Match': `"${etag}"` },
		})
	}

	async function clearShares() {
		const shares = await api.get(`/projects/${state.projectId}/shares`)
		for (const share of shares) {
			await api.delete(`/projects/${state.projectId}/shares/${share.id}`)
		}
	}

	test.beforeAll(async ({ peer }) => {
		const readable = await api.post('/boards', { title: 'Shared board ' + stamp })
		const hidden = await api.post('/boards', { title: 'Private board ' + stamp })
		state.boards = [readable.id, hidden.id]
		await api.post(`/boards/${readable.id}/acl`, { participant: peer.user, participantType: 'user', permission: VIEW })

		const readableStack = await api.post('/stacks', { boardId: readable.id, title: 'Inbox' })
		const hiddenStack = await api.post('/stacks', { boardId: hidden.id, title: 'Inbox' })
		const visible = await api.post('/cards', { stackId: readableStack.id, title: 'Visible card' })
		const spare = await api.post('/cards', { stackId: readableStack.id, title: 'Spare card' })
		const secret = await api.post('/cards', { stackId: hiddenStack.id, title: 'Secret card' })
		const ownerOnly = await api.post('/cards', { stackId: readableStack.id, title: 'Owner only card' })
		await api.patch(`/cards/${ownerOnly.id}`, { visibility: 'private' })
		state.visibleCardId = visible.id
		state.spareCardId = spare.id
		state.hiddenCardId = secret.id
		state.privateCardId = ownerOnly.id

		const project = await api.post('/projects', { title: projectTitle })
		state.projectId = project.id
		await api.put(`/projects/${project.id}/cards/${visible.id}`)
		await api.put(`/projects/${project.id}/cards/${secret.id}`)
		await api.put(`/projects/${project.id}/cards/${ownerOnly.id}`)
		await api.post(`/projects/${project.id}/comments`, { body: 'Earlier owner note' })
	})

	test.beforeEach(async () => {
		await clearShares()
	})

	test.afterAll(async () => {
		if (state.projectId) await api.delete(`/projects/${state.projectId}`).catch(() => {})
		for (const id of state.boards) {
			await api.delete(`/boards/${id}`).catch(() => {})
		}
	})

	test('an unshared project stays out of reach', async ({ peer }) => {
		const projects = await peer.api.get('/projects')
		expect(projects.some((p) => p.id === state.projectId)).toBe(false)
		expect((await peer.api.raw('GET', `/projects/${state.projectId}/cards`)).status).toBe(404)
		expect((await peer.api.raw('GET', `/projects/${state.projectId}/comments`)).status).toBe(404)
		expect((await peer.api.raw('GET', '/projects/2147483000/cards')).status).toBe(404)
	})

	test('the owner shares through the dialog', async ({ page, user, peer }) => {
		await ncLogin(page, { user: user.user, pass: user.pass })
		await page.goto(projectUrl(state.projectId))
		await openProjectMenu(page)
		await page.getByRole('menuitem', { name: 'Share project' }).click()

		const dialog = page.getByRole('dialog', { name: 'Share project' })
		await expect(dialog.locator('.project-share__hint')).toBeVisible()
		await expect(dialog.locator('.project-share__hint')).toContainText('earlier notes')
		await dialog.locator('[data-test="project-share-search"]').fill(peer.user)
		await dialog.locator('[data-test="project-share-result"]').first().click()

		const entry = dialog.locator('[data-test="project-share-entry"]')
		await expect(entry).toHaveCount(1)
		await expect(entry.locator('[data-test="project-share-level"]')).toHaveValue(String(VIEW))
		expect((await api.get(`/projects/${state.projectId}/shares`))[0].participant).toBe(peer.user)

		await entry.locator('[data-test="project-share-level"]').selectOption(String(EDIT))
		await expect.poll(async () => (await api.get(`/projects/${state.projectId}/shares`))[0].permission).toBe(EDIT)

		await entry.locator('[data-test="project-share-remove"]').click()
		await expect(entry).toHaveCount(0)
		expect(await api.get(`/projects/${state.projectId}/shares`)).toEqual([])
	})

	test('a viewer reads the project but only the cards their boards allow', async ({ browser, peer }) => {
		await shareWith(peer.user, 'user', VIEW)

		const listed = (await peer.api.get('/projects')).find((p) => p.id === state.projectId)
		expect(listed).toMatchObject({ isOwner: false, canEdit: false, canLeave: true, shared: true })

		const cards = await peer.api.get(`/projects/${state.projectId}/cards`)
		expect(cards.map((c) => c.id)).toEqual([state.visibleCardId])

		const ctx = await browser.newContext({ viewport: { width: 1600, height: 900 } })
		try {
			const page = await ctx.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })
			await page.goto(projectUrl(state.projectId))

			await expect(page.getByText('Visible card')).toBeVisible()
			await expect(page.getByText('Secret card')).toHaveCount(0)
			await expect(page.getByText('Earlier owner note')).toBeVisible()
			await expect(page.locator('[data-test="project-shared-label"]')).toContainText(`Shared by ${listed.ownerDisplayName}`)

			await expect(page.locator('.project-view__add-btn')).toHaveCount(0)
			await expect(page.locator('.project-view__composer')).toHaveCount(0)
			await expect(page.locator('.project-view__comment-link-btn')).toHaveCount(0)
			await expect(page.locator('.project-view__comment-icon-btn')).toHaveCount(0)
			await expect(page.locator('[data-test="project-start-date"]')).toBeDisabled()

			await openProjectMenu(page)
			await expect(page.getByRole('menuitem', { name: 'Leave project' })).toBeVisible()
			await expect(page.getByRole('menuitem', { name: 'Edit project' })).toHaveCount(0)
			await expect(page.getByRole('menuitem', { name: 'Share project' })).toHaveCount(0)
			await expect(page.getByRole('menuitem', { name: 'Delete project' })).toHaveCount(0)
			await page.keyboard.press('Escape')

			await page.getByText('Visible card').click()
			await page.locator('[data-pill="project"]').click()
			const row = page.locator('.card-modal__popover .card-modal__label-toggle', { hasText: projectTitle })
			await expect(row).toHaveAttribute('aria-disabled', 'true')
			const writes = []
			page.on('request', (request) => {
				if (request.method() !== 'GET' && request.url().includes(`/projects/${state.projectId}/cards/`)) {
					writes.push(request.url())
				}
			})
			await row.click({ force: true })
			await expect(row).toHaveAttribute('aria-pressed', 'true')
			await holdsEmpty(writes, 'a viewer\'s click must not send a membership write')
		} finally {
			await ctx.close()
		}
	})

	test('the server refuses writes from a viewer', async ({ peer }) => {
		await shareWith(peer.user, 'user', VIEW)
		const id = state.projectId

		expect((await peer.api.raw('PATCH', `/projects/${id}`, { title: 'Hijacked' })).status).toBe(403)
		expect((await peer.api.raw('PUT', `/projects/${id}/cards/${state.spareCardId}`)).status).toBe(403)
		expect((await peer.api.raw('DELETE', `/projects/${id}/cards/${state.visibleCardId}`)).status).toBe(403)
		expect((await peer.api.raw('POST', `/projects/${id}/comments`, { body: 'Nope' })).status).toBe(403)

		const [share] = await api.get(`/projects/${id}/shares`)
		expect((await peer.api.raw('PATCH', `/projects/${id}/shares/${share.id}`, { permission: EDIT })).status).toBe(403)
		expect((await api.get(`/projects/${id}/shares`))[0].permission).toBe(VIEW)
	})

	test('cards a recipient cannot see stay out of the project\'s cards, stats and plan', async ({ peer }) => {
		await shareWith(peer.user, 'user', VIEW)
		const id = state.projectId
		const createdTotal = (stats) => stats.created.reduce((sum, day) => sum + day.count, 0)

		const ownerCards = (await api.get(`/projects/${id}/cards`)).map((c) => c.id)
		expect(ownerCards).toEqual(expect.arrayContaining([state.hiddenCardId, state.privateCardId]))
		const ownerStats = await api.get(`/projects/${id}/stats`)
		const ownerPlan = await api.get(`/projects/${id}/plan`)
		expect(ownerStats.cardCount).toBe(ownerCards.length)
		expect(createdTotal(ownerStats)).toBe(ownerCards.length)
		expect(ownerPlan.total).toBe(ownerCards.length)
		expect(ownerPlan.burnup.at(-1).scope).toBe(ownerCards.length)

		const cards = await peer.api.get(`/projects/${id}/cards`)
		expect(cards.map((c) => c.id)).toEqual([state.visibleCardId])

		const stats = await peer.api.get(`/projects/${id}/stats`)
		expect(stats.cardCount).toBe(1)
		expect(createdTotal(stats)).toBe(1)

		const plan = await peer.api.get(`/projects/${id}/plan`)
		expect(plan.total).toBe(1)
		expect(plan.open).toBe(1)
		expect(plan.burnup.at(-1).scope).toBe(1)
	})

	test('an editor collaborates but cannot share, delete or reach hidden cards', async ({ peer }) => {
		await shareWith(peer.user, 'user', EDIT)
		const id = state.projectId

		await peer.api.put(`/projects/${id}/cards/${state.spareCardId}`)
		const cardIds = (await api.get(`/projects/${id}/cards`)).map((c) => c.id)
		expect(cardIds).toContain(state.spareCardId)
		await peer.api.delete(`/projects/${id}/cards/${state.spareCardId}`)

		const comment = await peer.api.post(`/projects/${id}/comments`, { body: 'Editor note' })
		await api.delete(`/project-comments/${comment.id}`)

		expect((await peer.api.raw('DELETE', `/projects/${id}`)).status).toBe(403)
		expect((await peer.api.raw('GET', `/projects/${id}/shares`)).status).toBe(403)
		expect((await peer.api.raw('POST', `/projects/${id}/shares`, {
			participant: 'admin',
			participantType: 'user',
			permission: VIEW,
		})).status).toBe(403)
		expect((await peer.api.raw('DELETE', `/projects/${id}/cards/${state.hiddenCardId}`)).status).toBe(403)
		expect((await api.get(`/projects/${id}/cards`)).map((c) => c.id)).toContain(state.hiddenCardId)
	})

	test('a group share reaches its members, who cannot leave it', async ({ peer }) => {
		const group = 'kansoshare' + stamp
		await adminOcs('/groups', { groupid: group })
		await adminOcs(`/users/${encodeURIComponent(peer.user)}/groups`, { groupid: group })
		try {
			await shareWith(group, 'group', VIEW)

			const listed = (await peer.api.get('/projects')).filter((p) => p.id === state.projectId)
			expect(listed).toHaveLength(1)
			expect(listed[0]).toMatchObject({ canEdit: false, canLeave: false })

			expect((await peer.api.raw('DELETE', `/projects/${state.projectId}/shares/me`)).status).toBe(403)
		} finally {
			await adminDeleteGroup(group)
		}
		// Deleting the group fires the cleanup listener, so no ghost share remains.
		await expect.poll(async () => api.get(`/projects/${state.projectId}/shares`)).toEqual([])
	})

	test('recipients see the shared project on boards and cards', async ({ peer }) => {
		await shareWith(peer.user, 'user', VIEW)

		const board = await peer.api.get(`/boards/${state.boards[0]}`)
		expect(board.projects.map((p) => p.id)).toContain(state.projectId)
		expect(board.cards.find((c) => c.id === state.visibleCardId).projectIds).toContain(state.projectId)
		const card = await peer.api.get(`/cards/${state.visibleCardId}`)
		expect(card.projectIds).toContain(state.projectId)

		await clearShares()
		const after = await peer.api.get(`/boards/${state.boards[0]}`)
		expect(after.projects.map((p) => p.id)).not.toContain(state.projectId)
		expect(after.etag).not.toBe(board.etag)
	})

	test('a board revalidation picks up a project change', async ({ peer }) => {
		await shareWith(peer.user, 'user', VIEW)
		const { etag } = await peer.api.get(`/boards/${state.boards[0]}`)

		const renamed = projectTitle + ' renamed'
		await api.patch(`/projects/${state.projectId}`, { color: '112233', title: renamed })
		try {
			const next = await peer.api.get(`/boards/${state.boards[0]}`)
			expect(next.etag).not.toBe(etag)
			expect(next.projects.find((p) => p.id === state.projectId)).toMatchObject({ color: '112233', title: renamed })

			const stale = await conditionalBoardRead(peer, etag)
			expect(stale.status).toBe(200)
			expect((await stale.json()).projects.find((p) => p.id === state.projectId).title).toBe(renamed)
			expect((await conditionalBoardRead(peer, next.etag)).status).toBe(304)
		} finally {
			await api.patch(`/projects/${state.projectId}`, { color: '', title: projectTitle })
		}
	})

	test('a direct share request for an unknown user is refused', async () => {
		expect((await api.raw('POST', `/projects/${state.projectId}/shares`, {
			participant: 'no-such-user-' + stamp,
			participantType: 'user',
			permission: VIEW,
		})).status).toBe(400)
	})

	test('an editor deletes their own thread only while no one else\'s reply is in it', async ({ peer }) => {
		await shareWith(peer.user, 'user', EDIT)
		const comment = await peer.api.post(`/projects/${state.projectId}/comments`, { body: 'Editor thread' })
		const reply = await api.post(`/projects/${state.projectId}/comments`, { body: 'Owner reply', parentCommentId: comment.id })

		expect((await peer.api.raw('DELETE', `/project-comments/${comment.id}`)).status).toBe(403)
		const ids = (await api.get(`/projects/${state.projectId}/comments`)).map((c) => c.id)
		expect(ids).toEqual(expect.arrayContaining([comment.id, reply.id]))

		await api.delete(`/project-comments/${reply.id}`)
		await peer.api.delete(`/project-comments/${comment.id}`)

		const own = await peer.api.post(`/projects/${state.projectId}/comments`, { body: 'Editor solo thread' })
		const ownReply = await peer.api.post(`/projects/${state.projectId}/comments`, { body: 'Editor own reply', parentCommentId: own.id })
		await peer.api.delete(`/project-comments/${own.id}`)

		const remaining = (await api.get(`/projects/${state.projectId}/comments`)).map((c) => c.id)
		for (const gone of [comment.id, reply.id, own.id, ownReply.id]) {
			expect(remaining).not.toContain(gone)
		}
	})

	test('a recipient whose share is revoked sees that they lost access', async ({ browser, peer }) => {
		await shareWith(peer.user, 'user', VIEW)

		const ctx = await browser.newContext({ viewport: { width: 1600, height: 900 } })
		try {
			const page = await ctx.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })
			await page.goto(projectUrl(state.projectId))
			await expect(page.getByText('Visible card')).toBeVisible()

			await clearShares()

			await expect(page.locator('[data-test="project-access-lost"]')).toBeVisible({ timeout: 100_000 })
			await expect(page.getByText('Visible card')).toHaveCount(0)
			await page.getByRole('button', { name: 'Back to projects' }).click()
			await expect(page.locator(`[data-test="project-tile-${state.projectId}"]`)).toHaveCount(0)
		} finally {
			await ctx.close()
		}
	})

	test('a deleted owner\'s project passes to the longest-standing editor, and viewers are not promoted', async ({ peer }) => {
		const password = 'kanso-e2e-Owner!1' + stamp
		const owner = await provisionUser('kansoowner' + stamp, password)
		const laterEditor = await provisionUser('kansoaeditor' + stamp, password)
		expect(laterEditor.user < peer.user, 'the later editor must sort first, or a name sort would pass too').toBe(true)
		const projectIds = []

		try {
			const edited = await owner.api.post('/projects', { title: 'Inherited ' + stamp })
			projectIds.push(edited.id)
			const viewed = await owner.api.post('/projects', { title: 'Viewed ' + stamp })
			projectIds.push(viewed.id)
			await owner.api.post(`/projects/${edited.id}/shares`, { participant: peer.user, participantType: 'user', permission: EDIT })
			await owner.api.post(`/projects/${edited.id}/shares`, { participant: laterEditor.user, participantType: 'user', permission: EDIT })
			await owner.api.post(`/projects/${viewed.id}/shares`, { participant: peer.user, participantType: 'user', permission: VIEW })

			await deleteUser(owner.user)

			const projects = await peer.api.get('/projects')
			expect(projects.find((p) => p.id === edited.id)).toMatchObject({ owner: peer.user, isOwner: true, canLeave: false })
			expect(projects.some((p) => p.id === viewed.id)).toBe(false)
			expect((await peer.api.get(`/projects/${edited.id}/shares`)).map((s) => s.participant)).toEqual([laterEditor.user])

			const inherited = (await laterEditor.api.get('/projects')).find((p) => p.id === edited.id)
			expect(inherited).toMatchObject({ owner: peer.user, isOwner: false, canEdit: true })
		} finally {
			await deleteUser(owner.user)
			for (const id of projectIds) {
				await peer.api.delete(`/projects/${id}`).catch(() => {})
				await laterEditor.api.delete(`/projects/${id}`).catch(() => {})
			}
			await deleteUser(laterEditor.user)
		}
	})

	test('a draft and the edit dialog do not follow the view to another project', async ({ page, user }) => {
		const source = await api.post('/projects', { title: 'Draft source ' + stamp, description: 'Source text' })
		const target = await api.post('/projects', { title: 'Draft target ' + stamp, description: 'Target text' })
		const descriptionOf = async (id) => (await api.get('/projects')).find((p) => p.id === id).description

		try {
			await ncLogin(page, { user: user.user, pass: user.pass })
			await page.goto(projectUrl(target.id))
			const title = page.locator('.project-view__title')
			await expect(title).toHaveText(target.title)
			await page.evaluate((id) => { location.hash = `#/projects/${id}` }, source.id)
			await expect(title).toHaveText(source.title)

			const writes = []
			page.on('request', (request) => {
				if (request.method() === 'PATCH' && /\/projects\/\d+$/.test(new URL(request.url()).pathname)) {
					writes.push(request.url())
				}
			})

			await page.locator('[data-test="project-description"]').click()
			const editor = page.locator('.project-view__desc-textarea')
			await editor.fill('Draft written on the source')
			await expect(editor).toBeFocused()

			await page.goBack()
			await expect(title).toHaveText(target.title)
			await title.click()
			await expect(editor).toHaveCount(0)
			await holdsEmpty(writes, 'the abandoned draft must not be saved anywhere')
			expect(await descriptionOf(target.id)).toBe('Target text')
			expect(await descriptionOf(source.id)).toBe('Source text')

			await page.goForward()
			await expect(title).toHaveText(source.title)
			await openProjectMenu(page)
			await page.getByRole('menuitem', { name: 'Edit project' }).click()
			const dialog = page.getByRole('dialog', { name: 'Edit project' })
			await expect(dialog).toBeVisible()

			await page.goBack()
			await expect(title).toHaveText(target.title)
			await expect(dialog).toHaveCount(0)
		} finally {
			await api.delete(`/projects/${source.id}`).catch(() => {})
			await api.delete(`/projects/${target.id}`).catch(() => {})
		}
	})

	test('a member leaves from the project menu', async ({ browser, peer }) => {
		await shareWith(peer.user, 'user', VIEW)

		const ctx = await browser.newContext({ viewport: { width: 1600, height: 900 } })
		try {
			const page = await ctx.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })
			await page.goto(projectUrl(state.projectId))
			await openProjectMenu(page)
			await page.getByRole('menuitem', { name: 'Leave project' }).click()

			await expect(page.locator('.projects-view__title')).toBeVisible()
			await expect(page.locator(`[data-test="project-tile-${state.projectId}"]`)).toHaveCount(0)
		} finally {
			await ctx.close()
		}
		expect(await api.get(`/projects/${state.projectId}/shares`)).toEqual([])
	})
})
