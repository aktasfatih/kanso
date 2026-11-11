// SPDX-FileCopyrightText: 2026 Rodrigo Rodrigues
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

const ROLE_IN_PROGRESS = 3

async function cardDue(cardId) {
	const card = await api.send('GET', `/cards/${cardId}`)
	return card.duedate ?? null
}

test.describe('Date input with a running timer', () => {
	const state = { boardId: 0, cardId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.send('POST', '/boards', { title: 'Date-timer E2E ' + Date.now() })
		state.boardId = board.id
		const todo = await api.send('POST', '/stacks', { boardId: board.id, title: 'To do' })
		const doing = await api.send('POST', '/stacks', { boardId: board.id, title: 'Doing' })
		await api.send('PATCH', `/stacks/${doing.id}`, { role: ROLE_IN_PROGRESS })
		await api.send('POST', `/boards/${board.id}/automation-rules`, {
			trigger: 'card_entered_role', action: 'start_timer', params: { role: ROLE_IN_PROGRESS },
		})
		const card = await api.send('POST', '/cards', { stackId: todo.id, title: 'Typing while the clock runs' })
		state.cardId = card.id
		await api.send('POST', `/cards/${card.id}/move`, { targetStackId: doing.id, afterCardId: null })
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.send('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('a date typed while the timer runs survives the re-render and is saved', async ({ page }) => {
		expect((await api.send('GET', `/cards/${state.cardId}`)).runningTimer).toBeTruthy()

		await ncLogin(page)
		await page.clock.install()
		await page.goto(state.cardUrl)
		await page.setViewportSize({ width: 1280, height: 800 })
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		const elapsed = page.locator('.card-modal__timer-running-elapsed')
		await expect(elapsed).toBeVisible()
		const before = await elapsed.textContent()
		await page.clock.runFor(60_000)
		await expect(elapsed).not.toHaveText(before)

		await page.locator('.card-modal__attrbar button.card-modal__pill[data-pill="due"]').click()
		const dueInput = page.locator('.card-modal__popover .card-modal__date-input[data-date="end"]')
		await expect(dueInput).toHaveAttribute('type', 'datetime-local')

		const typed = '2026-12-15T09:30'
		await dueInput.fill(typed)

		await page.clock.runFor(3_000)
		await expect(dueInput).toHaveValue(typed)

		// Compared as an instant: the input is local time, the stored value UTC.
		const expectedMs = await page.evaluate((v) => new Date(v).getTime(), typed)
		await dueInput.blur()
		await expect.poll(async () => {
			const due = await cardDue(state.cardId)
			return due ? new Date(due).getTime() : null
		}).toBe(expectedMs)
	})
})
