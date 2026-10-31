// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test('a rejected label is removed from its original board after navigation', async ({ page }) => {
	const boardA = await api.post('/boards', { title: 'Rollback origin' })
	const boardB = await api.post('/boards', { title: 'Rollback destination' })
	let releaseWrite = () => {}
	try {
		const stackA = await api.post('/stacks', { boardId: boardA.id, title: 'To do' })
		const stackB = await api.post('/stacks', { boardId: boardB.id, title: 'To do' })
		const cardA = await api.post('/cards', { stackId: stackA.id, title: 'Original card' })
		await api.post('/cards', { stackId: stackB.id, title: 'Destination card' })
		const label = await api.post('/labels', { boardId: boardA.id, title: 'Rejected label', color: 'ff0000' })
		let markStarted
		const held = new Promise((resolve) => { releaseWrite = resolve })
		const started = new Promise((resolve) => { markStarted = resolve })
		await page.route(new RegExp(`/cards/${cardA.id}/labels/${label.id}$`), async (route) => {
			markStarted()
			await held
			await route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ error: 'Refused' }) })
		})
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${boardA.id}`)
		await page.locator('.card-tile').filter({ hasText: 'Original card' }).click()
		await page.locator('.card-modal__attrbar button[data-pill="label"]').first().click()
		await page.locator('.card-modal__label-toggle', { hasText: 'Rejected label' }).click()
		await started
		await expect(page.locator('.card-modal__label-chip', { hasText: 'Rejected label' })).toBeVisible()
		await page.evaluate((id) => { location.hash = `#/board/${id}` }, boardB.id)
		await expect(page.locator('.card-tile').filter({ hasText: 'Destination card' })).toBeVisible()
		const refusal = page.waitForResponse((response) => response.status() === 403 && response.url().includes(`/cards/${cardA.id}/labels/${label.id}`))
		releaseWrite()
		await refusal
		await page.waitForFunction((cardId) => {
			const client = document.querySelector('#kanso')?.__vue_app__?._context.provides.VUE_QUERY_CLIENT
			return client?.getMutationCache().getAll().some((mutation) =>
				mutation.state.variables?.cardId === cardId && mutation.state.status === 'error')
		}, cardA.id)
		await expect(page.locator('.card-tile').filter({ hasText: 'Destination card' }).locator('.card-tile__label-chip')).toHaveCount(0)
		await page.evaluate((id) => { location.hash = `#/board/${id}` }, boardA.id)
		const original = page.locator('.card-tile').filter({ hasText: 'Original card' })
		await expect(original).toBeVisible()
		await expect(original.locator('.card-tile__label-chip', { hasText: 'Rejected label' })).toHaveCount(0)
		expect((await api.get(`/cards/${cardA.id}`)).labelIds).not.toContain(label.id)
	} finally {
		releaseWrite()
		await api.delete(`/boards/${boardA.id}`).catch(() => {})
		await api.delete(`/boards/${boardB.id}`).catch(() => {})
	}
})
