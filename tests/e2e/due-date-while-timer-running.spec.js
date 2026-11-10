// SPDX-FileCopyrightText: 2026 Rodrigo Rodrigues
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

const ROLE_IN_PROGRESS = 3

async function cardDue(cardId) {
	const card = await api.send('GET', `/cards/${cardId}`)
	return card.duedate ?? null
}

// #232 — the due-date / start-date fields (and the checklist step date) were
// bound with `:value`, and a card with a RUNNING TIMER re-renders the whole
// detail component every second (the live counter). Vue's value patch compares
// against the DOM value, so it reset the input to the last saved date on every
// tick: the date being typed vanished within a second, the blur handler read an
// empty string and returned early, and nothing was ever PATCHed. Cards without
// a running timer were unaffected, which is what made it look intermittent.
test.describe('Date input with a running timer (#232)', () => {
	const state = { boardId: 0, cardId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.send('POST', '/boards', { title: 'Date-timer E2E ' + Date.now() })
		state.boardId = board.id
		const todo = await api.send('POST', '/stacks', { boardId: board.id, title: 'To do' })
		const doing = await api.send('POST', '/stacks', { boardId: board.id, title: 'Doing' })
		await api.send('PATCH', `/stacks/${doing.id}`, { role: ROLE_IN_PROGRESS })
		// The per-second tick only exists while a timer runs, so the clock has to
		// be started the way the app starts it: an automation rule on column entry.
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
		// Precondition: without a running timer this spec would exercise nothing,
		// because the tick is the only thing re-rendering the modal.
		expect((await api.send('GET', `/cards/${state.cardId}`)).runningTimer).toBeTruthy()

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.setViewportSize({ width: 1280, height: 800 })
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		// The live counter row is what mounts the 1 s interval.
		await expect(page.locator('.card-modal__timer-running-row')).toBeVisible()

		await page.locator('.card-modal__attrbar button.card-modal__pill[data-pill="due"]').click()
		const dueInput = page.locator('.card-modal__popover .card-modal__date-input[data-date="end"]')
		await expect(dueInput).toHaveAttribute('type', 'datetime-local')

		const typed = '2026-12-15T09:30'
		await dueInput.fill(typed)

		// Sit through at least two ticks. Before the fix the field was already
		// empty again here, and the blur below saved nothing.
		await page.waitForTimeout(2500)
		await expect(dueInput).toHaveValue(typed)

		// Commit on blur, then prove it reached the server. Compared as an INSTANT,
		// not as a calendar day: the input is local time and the stored value is
		// UTC, so east/west of UTC the UTC date can legitimately be the day before.
		const expectedMs = await page.evaluate((v) => new Date(v).getTime(), typed)
		await dueInput.blur()
		await expect.poll(async () => {
			const due = await cardDue(state.cardId)
			return due ? new Date(due).getTime() : null
		}).toBe(expectedMs)
	})
})
