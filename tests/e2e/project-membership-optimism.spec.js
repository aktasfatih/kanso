// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

function deferred() {
	let resolve
	const promise = new Promise((done) => { resolve = done })
	return { promise, resolve }
}

test.describe('Immediate card project membership', () => {
	const state = { board: 0, card: 0, nextCard: 0, projects: [] }

	test.beforeAll(async () => {
		const title = `Pending membership ${Date.now()}`
		state.board = (await api.post('/boards', { title })).id
		const stack = await api.post('/stacks', { boardId: state.board, title: 'To do' })
		state.card = (await api.post('/cards', { stackId: stack.id, title })).id
		state.nextCard = (await api.post('/cards', { stackId: stack.id, title: `${title} next card` })).id
		for (const suffix of ['One', 'Two']) {
			state.projects.push(await api.post('/projects', { title: `${title} ${suffix}` }))
		}
	})

	test.afterAll(async () => {
		for (const project of state.projects) await api.delete(`/projects/${project.id}`)
		if (state.board) await api.delete(`/boards/${state.board}`)
	})

	test.beforeEach(async () => {
		for (const project of state.projects) await api.delete(`/projects/${project.id}/cards/${state.card}`)
	})

	async function openPicker(page) {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.board}/card/${state.card}`)
		const pill = page.locator('.card-modal__attrbar button[data-pill="project"]')
		await pill.click()
		const rows = state.projects.map((project) => page.locator('.card-modal__popover .card-modal__label-toggle', { hasText: project.title }))
		for (const row of rows) await expect(row).toBeVisible()
		return { pill, rows }
	}

	test('queued project ticks and count update before either membership reply', async ({ page }) => {
		const { pill, rows } = await openPicker(page)
		const gates = state.projects.map(() => deferred())
		const sent = []
		await page.route(/\/api\/projects\/\d+\/cards\/\d+$/, async (route) => {
			const index = state.projects.findIndex((project) => route.request().url().includes(`/projects/${project.id}/`))
			sent.push(index)
			await gates[index].promise
			await route.continue()
		})
		try {
			await rows[0].click()
			await rows[1].click()
			for (const row of rows) {
				await expect(row).toHaveAttribute('aria-pressed', 'true')
				await expect(row).toHaveAttribute('aria-busy', 'true')
			}
			await expect(pill).toHaveText('2 projects')
			expect(sent).toEqual([0])
			expect((await api.get(`/cards/${state.card}`)).projectIds).toEqual([])
			gates[0].resolve()
			await expect(rows[0]).not.toHaveAttribute('aria-busy', 'true')
			await expect(rows[1]).toHaveAttribute('aria-pressed', 'true')
			await expect(pill).toHaveText('2 projects')
			gates[1].resolve()
			await expect(rows[1]).not.toHaveAttribute('aria-busy', 'true')
			expect(sent).toEqual([0, 1])
			expect((await api.get(`/cards/${state.card}`)).projectIds).toEqual(state.projects.map((project) => project.id))
		} finally {
			for (const gate of gates) gate.resolve()
		}
	})

	for (const assign of [true, false]) {
		test(`a refused ${assign ? 'add' : 'remove'} rolls back before reconciliation replies`, async ({ page }) => {
			if (!assign) await api.put(`/projects/${state.projects[0].id}/cards/${state.card}`)
			const { pill, rows } = await openPicker(page)
			const write = deferred()
			const read = deferred()
			let reconciliationStarted = false
			await page.route(`**/api/projects/${state.projects[0].id}/cards/${state.card}`, async (route) => {
				await write.promise
				await route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ error: 'Membership refused' }) })
			})
			await page.route(`**/api/cards/${state.card}`, async (route) => {
				if (route.request().method() !== 'GET') return route.continue()
				reconciliationStarted = true
				await read.promise
				await route.continue()
			})
			try {
				await rows[0].click()
				await expect(rows[0]).toHaveAttribute('aria-pressed', String(assign))
				await expect(pill).toHaveText(assign ? '1 project' : 'Project')
				expect((await api.get(`/cards/${state.card}`)).projectIds).toEqual(assign ? [] : [state.projects[0].id])
				write.resolve()
				await expect.poll(() => reconciliationStarted).toBe(true)
				await expect(rows[0]).toHaveAttribute('aria-pressed', String(!assign))
				await expect(pill).toHaveText(assign ? 'Project' : '1 project')
				read.resolve()
				await expect(page.locator('.card-modal__save-error', { hasText: 'Membership refused' })).toBeVisible()
				await expect(rows[0]).not.toHaveAttribute('aria-busy', 'true')
			} finally {
				write.resolve()
				read.resolve()
			}
		})
	}

	test('queued membership and its errors stay on the initiating card after navigation', async ({ page }) => {
		const { rows } = await openPicker(page)
		const write = deferred()
		const sent = []
		await page.route(/\/api\/projects\/\d+\/cards\/\d+$/, async (route) => {
			sent.push(route.request().url())
			if (route.request().url().includes(`/projects/${state.projects[0].id}/`)) {
				await write.promise
				return route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ error: 'Old card refusal' }) })
			}
			await route.continue()
		})
		try {
			await rows[0].click()
			await rows[1].click()
			await expect(rows[1]).toHaveAttribute('aria-pressed', 'true')
			await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.board}/card/${state.nextCard}`)
			const pill = page.locator('.card-modal__attrbar button[data-pill="project"]')
			await expect(pill).toHaveText('Project')
			write.resolve()
			await expect.poll(async () => (await api.get(`/cards/${state.card}`)).projectIds).toEqual([state.projects[1].id])
			await expect(pill).toHaveText('Project')
			if (await pill.getAttribute('aria-expanded') !== 'true') await pill.click()
			await expect(page.locator('.card-modal__save-error', { hasText: 'Old card refusal' })).toHaveCount(0)
			for (const project of state.projects) {
				await expect(page.locator('.card-modal__popover .card-modal__label-toggle', { hasText: project.title })).toHaveAttribute('aria-pressed', 'false')
			}
			expect(sent).toHaveLength(2)
			for (const url of sent) expect(url).toMatch(new RegExp(`/cards/${state.card}$`))
			expect((await api.get(`/cards/${state.nextCard}`)).projectIds).toEqual([])
		} finally {
			write.resolve()
		}
	})
})
