// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

const projectUrl = (id) => `${BASE}/index.php/apps/kanso#/projects/${id}`

async function navigateProject(page, project) {
	await page.evaluate((id) => { location.hash = `#/projects/${id}` }, project.id)
	await expect(page.locator('.project-view__title')).toHaveText(project.title)
}

async function openEdit(page) {
	await page.locator('.project-view__header-actions button[aria-haspopup]').click()
	await page.getByRole('menuitem', { name: 'Edit project', exact: true }).click()
	return page.getByRole('dialog', { name: 'Edit project', exact: true })
}

async function holdWrite(page, path, method, outcome) {
	let release
	let markStarted
	const held = new Promise((resolve) => { release = resolve })
	const started = new Promise((resolve) => { markStarted = resolve })
	const matches = (request) => request.method() === method && new URL(request.url()).pathname.endsWith(path)
	await page.route((url) => url.pathname.endsWith(path), async (route) => {
		if (!matches(route.request())) return route.continue()
		const response = outcome === 'success' ? await route.fetch() : null
		markStarted(route.request())
		await held
		if (response) await route.fulfill({ response })
		else await route.fulfill({ status: 503, json: { error: 'Source write failed' } })
	})
	return {
		started,
		release,
		async finish() {
			const response = page.waitForResponse((result) => matches(result.request()))
			release()
			const result = await response
			await result.finished()
			expect(result.status()).toBe(outcome === 'success' ? 200 : 503)
		},
	}
}

test.describe('Project navigation during writes', () => {
	test.use({ viewport: { width: 1600, height: 900 } })
	const created = []

	async function createProject(title) {
		const project = await api.post('/projects', { title: `${title} ${Date.now()}`, description: `${title} description` })
		created.push(project.id)
		return project
	}

	test.afterEach(async () => {
		for (const id of created.splice(0)) await api.delete(`/projects/${id}`).catch(() => {})
	})

	for (const { outcome, returnToSource = false } of [
		{ outcome: 'success' },
		{ outcome: 'failure' },
		{ outcome: 'success', returnToSource: true },
	]) {
		test(`a delayed reply ${outcome} preserves the ${returnToSource ? 'returned source' : 'next project'} draft and error`, async ({ page, user }) => {
			const source = await createProject('Reply source')
			const target = await createProject('Reply target')
			await api.post(`/projects/${source.id}/comments`, { body: 'Source note' })
			await api.post(`/projects/${target.id}/comments`, { body: 'Target note' })
			await ncLogin(page, user)
			await page.goto(projectUrl(source.id))
			const pending = await holdWrite(page, `/projects/${source.id}/comments`, 'POST', outcome)
			try {
				await page.getByRole('button', { name: 'Reply', exact: true }).click()
				const reply = page.getByPlaceholder('Write a reply…', { exact: true })
				await reply.fill('Source reply')
				await page.getByRole('button', { name: 'Post reply', exact: true }).click()
				const request = await pending.started
				expect(request.postDataJSON()).toMatchObject({ body: 'Source reply' })
				await navigateProject(page, target)
				if (returnToSource) await navigateProject(page, source)
				const current = returnToSource ? source : target

				await page.route('**/project-comments/*', (route) => route.request().method() === 'DELETE'
					? route.fulfill({ status: 503, json: { error: 'Current project error' } })
					: route.continue())
				const topComment = page.locator('.project-view__comment-group > .project-view__comment')
				await topComment.getByRole('button', { name: 'Delete comment', exact: true }).click()
				const error = page.locator('[data-test="project-comment-error"]')
				await expect(error).toHaveText('Current project error')
				await topComment.getByRole('button', { name: 'Reply', exact: true }).click()
				await reply.fill('Current project draft')
				const post = page.getByRole('button', { name: 'Post reply', exact: true })
				await expect(post).toBeDisabled()
				await expect(reply).toHaveValue('Current project draft')
				await expect(error).toHaveText('Current project error')
				await expect(page).toHaveURL(projectUrl(current.id))

				await pending.finish()
				await expect(post).toBeEnabled()
				await expect(reply).toBeVisible()
				await expect(reply).toHaveValue('Current project draft')
				await expect(error).toHaveText('Current project error')
				await expect(page.locator('.project-view__title')).toHaveText(current.title)
				await expect(page).toHaveURL(projectUrl(current.id))
				expect((await api.get(`/projects/${source.id}/comments`)).filter((comment) => comment.body === 'Source reply')).toHaveLength(outcome === 'success' ? 1 : 0)
				expect((await api.get(`/projects/${target.id}/comments`)).map((comment) => comment.body)).toEqual(['Target note'])
			} finally {
				pending.release()
			}
		})
	}

	for (const outcome of ['success', 'failure']) {
		test(`a delayed metadata ${outcome} preserves the next project's edit dialog`, async ({ page, user }) => {
			const source = await createProject('Edit source')
			const target = await createProject('Edit target')
			await ncLogin(page, user)
			await page.goto(projectUrl(source.id))
			const dialog = await openEdit(page)
			await dialog.locator('#edit-project-lead').click()
			await page.getByRole('option', { name: user.user, exact: true }).click()
			await dialog.locator('#edit-project-lifecycle').selectOption('done')
			const pending = await holdWrite(page, `/projects/${source.id}`, 'PATCH', outcome)
			try {
				await dialog.getByRole('button', { name: 'Save', exact: true }).click()
				const request = await pending.started
				expect(request.postDataJSON()).toEqual({ lead: user.user, lifecycleStatus: 'done' })
				await navigateProject(page, target)
				await openEdit(page)
				const color = dialog.getByRole('button', { name: '#e53935', exact: true })
				await color.click()
				await expect(color).toHaveAttribute('aria-pressed', 'true')
				await expect(dialog.locator('#edit-project-title')).toHaveValue(target.title)
				await expect(dialog.locator('#edit-project-lifecycle')).toHaveValue('planned')
				await expect(dialog.getByRole('button', { name: 'Save', exact: true })).toBeDisabled()
				await expect(page).toHaveURL(projectUrl(target.id))

				await pending.finish()
				await expect(dialog.getByRole('button', { name: 'Save', exact: true })).toBeEnabled()
				await expect(dialog).toBeVisible()
				await expect(color).toHaveAttribute('aria-pressed', 'true')
				await expect(dialog.locator('#edit-project-title')).toHaveValue(target.title)
				await expect(dialog.locator('#edit-project-lifecycle')).toHaveValue('planned')
				await expect(dialog.locator('#edit-project-lead')).toHaveValue('')
				await expect(dialog.locator('.project-view__form-error')).toHaveCount(0)
				await expect(page).toHaveURL(projectUrl(target.id))
				const projects = await api.get('/projects')
				expect(projects.find((project) => project.id === source.id)).toMatchObject({ lead: outcome === 'success' ? user.user : null, lifecycleStatus: outcome === 'success' ? 'done' : 'planned' })
				expect(projects.find((project) => project.id === target.id)).toMatchObject({ title: target.title, color: target.color, lead: null, lifecycleStatus: 'planned' })
			} finally {
				pending.release()
			}
		})
	}

	test('a delayed description save preserves the next project\'s open description editor', async ({ page, user }) => {
		const source = await createProject('Description source')
		const target = await createProject('Description target')
		await ncLogin(page, user)
		await page.goto(projectUrl(source.id))
		await page.locator('[data-test="project-description"]').click()
		const editor = page.locator('.project-view__desc-textarea')
		await editor.fill('Saved source description')
		const pending = await holdWrite(page, `/projects/${source.id}`, 'PATCH', 'success')
		try {
			await editor.press('Control+Enter')
			await pending.started
			await navigateProject(page, target)
			await page.locator('[data-test="project-description"]').click()
			await expect(editor).toHaveValue(target.description)
			await expect(editor).toBeDisabled()

			await pending.finish()
			await expect(editor).toBeEnabled()
			await expect(editor).toBeVisible()
			await expect(editor).toHaveValue(target.description)
			await expect(page.locator('.project-view__desc-error')).toHaveCount(0)
			await expect(page).toHaveURL(projectUrl(target.id))
			const projects = await api.get('/projects')
			expect(projects.find((project) => project.id === source.id).description).toBe('Saved source description')
			expect(projects.find((project) => project.id === target.id).description).toBe(target.description)
		} finally {
			pending.release()
		}
	})
})
