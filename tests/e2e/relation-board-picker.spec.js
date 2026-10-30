// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, BASE, api, ncLogin } from './helpers.js'

test.describe('Select a relation on the board', () => {
	const state = { boardId: 0, sourceId: 0, targetId: 0 }

	test.beforeAll(async ({ peer }) => {
		const board = await api.post('/boards', { title: `Relation picker ${Date.now()}` })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Tasks' })
		state.sourceId = (await api.post('/cards', { stackId: stack.id, title: 'Source task' })).id
		state.targetId = (await api.post('/cards', { stackId: stack.id, title: 'Target task' })).id
		await api.post(`/boards/${board.id}/acl`, { participant: peer.user, participantType: 'user', permission: 1 })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	async function openPicker(page) {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.sourceId}`)
		await expect(page.locator('.card-modal')).toBeVisible()
		await page.locator('.card-modal__actions-menu button').first().click()
		await page.getByRole('menuitem', { name: /add relation/i }).click()
		await page.locator('.card-modal__relation-kind').selectOption('duplicates')
		await page.locator('[data-test=relation-board-picker]').click()
		await expect(page.locator('[data-test=relation-picker-banner]')).toBeVisible()
	}

	test('selects a different card and saves only after Add', async ({ page }) => {
		await openPicker(page)
		const source = page.locator(`.card-tile[data-card-id="${state.sourceId}"]`)
		const target = page.locator(`.card-tile[data-card-id="${state.targetId}"]`)
		await expect(source).toBeVisible()
		await expect(target).toBeVisible()
		await expect(source).toHaveAttribute('aria-disabled', 'true')
		await source.dispatchEvent('click')
		await expect(page.locator('[data-test=relation-picker-banner]')).toBeVisible()
		await target.click()
		await expect(page.locator('.card-modal__relation-kind')).toHaveValue('duplicates')
		await expect(page.locator('.card-modal__relation-target')).toHaveValue(String(state.targetId))
		expect((await api.get(`/cards/${state.sourceId}`)).relations.duplicates).toHaveLength(0)
		await page.locator('.card-modal__relation-add-btn', { hasText: /^Add$/ }).click()
		await expect(page.locator('.card-modal__relation-row', { hasText: 'Target task' })).toBeVisible()
		expect((await api.get(`/cards/${state.sourceId}`)).relations.duplicates.map((rel) => rel.cardId)).toContain(state.targetId)
	})

	test('Escape returns to the source with its relation kind preserved', async ({ page }) => {
		await openPicker(page)
		await page.keyboard.press('Escape')
		await expect(page.locator('.card-modal__relation-kind')).toHaveValue('duplicates')
		await expect(page.locator('.card-modal__relation-target')).toHaveValue('')
		await expect(page.locator('[data-test=relation-board-picker]')).toBeFocused()
	})

	test('Escape closes the command palette before cancelling the picker', async ({ page }) => {
		await openPicker(page)
		await page.keyboard.press('Control+k')
		await expect(page.locator('.command-palette')).toBeVisible()
		await page.keyboard.press('Escape')
		await expect(page.locator('.command-palette')).not.toBeVisible()
		await expect(page.locator('[data-test=relation-picker-banner]')).toBeVisible()
		await page.keyboard.press('Escape')
		await expect(page.locator('.card-modal__relation-kind')).toHaveValue('duplicates')
	})

	test('preserves an unsaved board settings draft across picking', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await expect(page.locator(`.card-tile[data-card-id="${state.sourceId}"]`)).toBeVisible()
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.locator('#bs-rail-tab-general').click()
		await page.locator('#bs-board-name').fill('Unsaved settings draft')
		await page.evaluate(({ boardId, sourceId }) => { location.hash = `#/board/${boardId}/card/${sourceId}` }, state)
		await page.locator('.card-modal__actions-menu button').first().click()
		await page.getByRole('menuitem', { name: /add relation/i }).click()
		await page.locator('[data-test=relation-board-picker]').click()
		await expect(page.locator('.bs-modal')).toBeHidden()
		await page.locator(`.card-tile[data-card-id="${state.targetId}"]`).focus()
		await page.keyboard.press('e')
		await expect(page.locator('.card-modal')).toBeVisible()
		await expect(page.locator('.card-modal__relation-target')).toHaveValue(String(state.targetId))
		await page.locator('.card-modal-modal .modal-container__close').click()
		await expect(page.locator('#bs-board-name')).toHaveValue('Unsaved settings draft')
	})

	test('keyboard activation chooses a target without altering the saved display mode', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.evaluate((boardId) => localStorage.setItem(`kanso.viewMode.${boardId}`, 'list'), state.boardId)
		await openPicker(page)
		const target = page.locator(`.card-tile[data-card-id="${state.targetId}"]`)
		await target.focus()
		await page.keyboard.press('Enter')
		await expect(page.locator('.card-modal__relation-target')).toHaveValue(String(state.targetId))
		expect(await page.evaluate((boardId) => localStorage.getItem(`kanso.viewMode.${boardId}`), state.boardId)).toBe('list')
	})

	test('returns to a standalone card page', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/card/${state.sourceId}`)
		await expect(page.locator('.card-modal')).toBeVisible()
		await page.locator('.card-modal__actions-menu button').first().click()
		await page.getByRole('menuitem', { name: /add relation/i }).click()
		await page.locator('.card-modal__relation-kind').selectOption('relates')
		await page.locator('[data-test=relation-board-picker]').click()
		await page.locator(`.card-tile[data-card-id="${state.targetId}"]`).click()
		await expect(page).toHaveURL(new RegExp(`#/card/${state.sourceId}$`))
		await expect(page.locator('.card-modal__relation-kind')).toHaveValue('relates')
		await expect(page.locator('.card-modal__relation-target')).toHaveValue(String(state.targetId))
	})

	test('keeps an unsaved description when the user declines to leave', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.sourceId}`)
		await page.locator('.card-modal__actions-menu button').first().click()
		await page.getByRole('menuitem', { name: /add relation/i }).click()
		await page.locator('.card-modal__desc-placeholder').click()
		const editor = page.locator('.card-modal__section .kanso-md-editor .ProseMirror').first()
		await editor.fill('Unsaved picker draft')
		let prompted = false
		page.once('dialog', async (dialog) => { prompted = true; await dialog.dismiss() })
		await page.locator('[data-test=relation-board-picker]').click()
		expect(prompted).toBe(true)
		await expect(editor).toContainText('Unsaved picker draft')
		await expect(page.locator('[data-test=relation-picker-banner]')).toHaveCount(0)
	})

	test('can pick a card hidden by the current board filter without changing it', async ({ page }) => {
		const label = await api.post('/labels', { boardId: state.boardId, title: `Picker filter ${Date.now()}`, color: 'aa0000' })
		await api.put(`/cards/${state.sourceId}/labels/${label.id}`)
		try {
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.sourceId}?fl=${label.id}`)
			await expect(page.locator('.card-modal')).toBeVisible()
			await page.locator('.card-modal__actions-menu button').first().click()
			await page.getByRole('menuitem', { name: /add relation/i }).click()
			await page.locator('[data-test=relation-board-picker]').click()
			await page.locator(`.card-tile[data-card-id="${state.targetId}"]`).click()
			await expect(page.locator('.card-modal__relation-target')).toHaveValue(String(state.targetId))
			await expect(page).toHaveURL(new RegExp(`fl=${label.id}`))
		} finally {
			await api.delete(`/labels/${label.id}`).catch(() => {})
		}
	})

	test('returns to the source overlay on a custom View', async ({ page }) => {
		const created = await api.put('/views', { name: `Relation picker view ${Date.now()}`, filter: {}, groupBy: 'status', display: 'list' })
		const viewId = created.views[created.views.length - 1].id
		try {
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/views/${viewId}`)
			await page.locator('.board-list-row__title', { hasText: 'Source task' }).click()
			await page.locator('.card-modal__actions-menu button').first().click()
			await page.getByRole('menuitem', { name: /add relation/i }).click()
			await page.locator('.card-modal__relation-kind').selectOption('relates')
			await page.locator('[data-test=relation-board-picker]').click()
			await page.locator(`.card-tile[data-card-id="${state.targetId}"]`).click()
			await expect(page).toHaveURL(new RegExp(`#/views/${viewId}$`))
			await expect(page.locator('.card-modal__relation-kind')).toHaveValue('relates')
			await expect(page.locator('.card-modal__relation-target')).toHaveValue(String(state.targetId))
		} finally {
			await api.delete(`/views/${viewId}`).catch(() => {})
		}
	})

	test('viewers cannot open the relation editor', async ({ browser, peer }) => {
		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
		try {
			const page = await context.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })
			await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.sourceId}`)
			await expect(page.locator('.card-modal')).toBeVisible()
			await expect(page.locator('[data-test=relation-board-picker]')).toHaveCount(0)
		} finally {
			await context.close()
		}
	})
})
