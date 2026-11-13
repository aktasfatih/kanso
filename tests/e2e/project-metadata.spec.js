// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, provisionUser, BASE } from './helpers.js'

const projectUrl = (id) => `${BASE}/index.php/apps/kanso#/projects/${id}`

async function openEdit(page) {
	await page.locator('.project-view__header-actions button[aria-haspopup]').click()
	await page.getByRole('menuitem', { name: 'Edit project', exact: true }).click()
	return page.getByRole('dialog', { name: 'Edit project', exact: true })
}

test.describe('Project lead and lifecycle', () => {
	const created = []

	test.beforeAll(async ({ peer }) => {
		await provisionUser(peer.user, peer.pass)
	})

	test.afterEach(async () => {
		for (const id of created.splice(0)) await api.delete(`/projects/${id}`).catch(() => {})
	})

	test('defaults and metadata persist without sharing or changing forecast health', async ({ peer }) => {
		const project = await api.post('/projects', { title: `Metadata defaults ${Date.now()}` })
		created.push(project.id)
		expect(project).toMatchObject({ lead: null, lifecycleStatus: 'planned' })
		const initialPlan = await api.get(`/projects/${project.id}/plan`)

		await api.patch(`/projects/${project.id}`, { lead: peer.user, lifecycleStatus: 'done' })
		expect((await api.get('/projects')).find((p) => p.id === project.id)).toMatchObject({ lead: peer.user, lifecycleStatus: 'done', shared: false })
		expect(await api.get(`/projects/${project.id}/shares`)).toEqual([])
		expect((await peer.api.get('/projects')).some((p) => p.id === project.id)).toBe(false)
		expect((await peer.api.raw('PATCH', `/projects/${project.id}`, { lifecycleStatus: 'planned' })).status).toBe(404)
		expect((await api.get(`/projects/${project.id}/plan`)).status).toBe(initialPlan.status)

		for (const lifecycleStatus of ['', 'on_track', 'active']) {
			expect((await api.raw('PATCH', `/projects/${project.id}`, { lifecycleStatus })).status).toBe(400)
		}
		expect((await api.raw('PATCH', `/projects/${project.id}`, { lead: 'missing-user-metadata' })).status).toBe(400)
		await api.patch(`/projects/${project.id}`, { title: 'Renamed metadata', lead: null, lifecycleStatus: null })
		expect((await api.get('/projects')).find((p) => p.id === project.id)).toMatchObject({ lead: peer.user, lifecycleStatus: 'done' })
		await api.patch(`/projects/${project.id}`, { lead: '' })
		expect((await api.get('/projects')).find((p) => p.id === project.id)).toMatchObject({ lead: null, lifecycleStatus: 'done' })
	})

	test('create and edit dialogs save, display and clear metadata after reload', async ({ page, peer }) => {
		const title = `Metadata UI ${Date.now()}`
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects`)
		await page.getByRole('button', { name: 'New project', exact: true }).click()
		const createDialog = page.getByRole('dialog', { name: 'New project', exact: true })
		await createDialog.locator('#project-title').fill(title)
		await createDialog.locator('#project-lead').fill(peer.user)
		await page.getByRole('option', { name: peer.user, exact: true }).click()
		await createDialog.locator('#project-lifecycle').selectOption('in_progress')
		await createDialog.getByRole('button', { name: 'Create', exact: true }).click()
		await expect(page.locator('.project-view__title')).toHaveText(title)
		const project = (await api.get('/projects')).find((p) => p.title === title)
		created.push(project.id)

		await page.reload()
		await expect(page.locator('[data-test="project-lifecycle"]')).toHaveText('Lifecycle: In progress')
		await expect(page.locator('[data-test="project-lead"]')).toContainText(peer.user)
		expect(project).toMatchObject({ lead: peer.user, lifecycleStatus: 'in_progress', shared: false })

		const editDialog = await openEdit(page)
		await editDialog.getByRole('button', { name: 'Clear selected', exact: true }).click()
		await editDialog.locator('#edit-project-lifecycle').selectOption('done')
		await editDialog.getByRole('button', { name: 'Save', exact: true }).click()
		await expect(editDialog).toHaveCount(0)
		await page.reload()
		await expect(page.locator('[data-test="project-lead"]')).toHaveText('Lead: No lead')
		await expect(page.locator('[data-test="project-lifecycle"]')).toHaveText('Lifecycle: Done')

		await page.goto(`${BASE}/index.php/apps/kanso#/projects`)
		await expect(page.locator(`[data-test="project-tile-${project.id}"] [data-test="project-tile-lifecycle"]`)).toHaveText('Lifecycle: Done')
	})

	test('editors change metadata and viewers only read it', async ({ browser, peer }) => {
		const project = await api.post('/projects', { title: `Metadata permissions ${Date.now()}` })
		created.push(project.id)
		const share = await api.post(`/projects/${project.id}/shares`, { participant: peer.user, participantType: 'user', permission: 3 })
		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
		try {
			const page = await context.newPage()
			await ncLogin(page, peer)
			await page.goto(projectUrl(project.id))
			const dialog = await openEdit(page)
			await dialog.locator('#edit-project-lead').click()
			await page.getByRole('option', { name: peer.user, exact: true }).click()
			await dialog.locator('#edit-project-lifecycle').selectOption('in_progress')
			await dialog.getByRole('button', { name: 'Save', exact: true }).click()
			await expect(dialog).toHaveCount(0)
			expect((await api.get('/projects')).find((p) => p.id === project.id)).toMatchObject({ lead: peer.user, lifecycleStatus: 'in_progress', owner: project.owner })

			await api.patch(`/projects/${project.id}/shares/${share.id}`, { permission: 1 })
			await page.reload()
			await expect(page.locator('[data-test="project-lifecycle"]')).toHaveText('Lifecycle: In progress')
			await expect(page.locator('[data-test="project-lead"]')).toContainText(peer.user)
			await page.locator('.project-view__header-actions button[aria-haspopup]').click()
			await expect(page.getByRole('menuitem', { name: 'Edit project', exact: true })).toHaveCount(0)
			expect((await peer.api.raw('PATCH', `/projects/${project.id}`, { lead: 'unknown', lifecycleStatus: 'invalid' })).status).toBe(403)
		} finally {
			await context.close()
		}
	})

	test('saving another field preserves metadata changed by a collaborator', async ({ page, peer }) => {
		const project = await api.post('/projects', { title: `Metadata collaboration ${Date.now()}` })
		created.push(project.id)
		await api.post(`/projects/${project.id}/shares`, { participant: peer.user, participantType: 'user', permission: 3 })
		await ncLogin(page)
		await page.goto(projectUrl(project.id))
		const dialog = await openEdit(page)
		await peer.api.patch(`/projects/${project.id}`, { lead: peer.user, lifecycleStatus: 'done' })
		await dialog.locator('#edit-project-title').fill('Renamed without metadata changes')
		await dialog.getByRole('button', { name: 'Save', exact: true }).click()
		await expect(dialog).toHaveCount(0)
		expect((await api.get('/projects')).find((p) => p.id === project.id)).toMatchObject({ title: 'Renamed without metadata changes', lead: peer.user, lifecycleStatus: 'done' })
	})
})
