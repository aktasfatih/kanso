// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, me } from './helpers.js'

const ROLE_REVIEW = 4
const ROLE_IN_PROGRESS = 3
const ROLE_DONE = 5

test.describe('Automation rules (#3400)', () => {
	const state = { boardId: 0, todoStackId: 0, reviewStackId: 0, progStackId: 0, doneStackId: 0, labelId: 0 }

	test.beforeAll(async () => {
		const board = await api.send('POST', '/boards', { title: 'Automation ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.todoStackId = (await api.send('POST', '/stacks', { boardId: board.id, title: 'To do' })).id
		state.reviewStackId = (await api.send('POST', '/stacks', { boardId: board.id, title: 'Review' })).id
		state.progStackId = (await api.send('POST', '/stacks', { boardId: board.id, title: 'Doing' })).id
		state.doneStackId = (await api.send('POST', '/stacks', { boardId: board.id, title: 'Done' })).id
		await api.send('PATCH', `/stacks/${state.reviewStackId}`, { role: ROLE_REVIEW })
		await api.send('PATCH', `/stacks/${state.progStackId}`, { role: ROLE_IN_PROGRESS })
		await api.send('PATCH', `/stacks/${state.doneStackId}`, { role: ROLE_DONE })
		state.labelId = (await api.send('POST', '/labels', { boardId: board.id, title: 'Auto-tagged', color: 'e74c3c' })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.send('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('entering a review-role column fires the request_review rule', async () => {
		await api.send('POST', `/boards/${state.boardId}/automation-rules`, {
			trigger: 'card_entered_role',
			action: 'request_review',
			params: { role: ROLE_REVIEW, reviewer: me },
		})
		const cardId = (await api.send('POST', '/cards', { stackId: state.todoStackId, title: 'Needs review' })).id

		let card = await api.send('GET', `/cards/${cardId}`)
		expect(card.reviews ?? []).toHaveLength(0)

		await api.send('POST', `/cards/${cardId}/move`, { targetStackId: state.reviewStackId, afterCardId: null })

		card = await api.send('GET', `/cards/${cardId}`)
		expect(card.reviews.some((rv) => rv.reviewer === me)).toBe(true)
	})

	test('entering an in-progress-role column fires the add_label rule', async () => {
		await api.send('POST', `/boards/${state.boardId}/automation-rules`, {
			trigger: 'card_entered_role',
			action: 'add_label',
			params: { role: ROLE_IN_PROGRESS, label: state.labelId },
		})
		const cardId = (await api.send('POST', '/cards', { stackId: state.todoStackId, title: 'Gets a label' })).id

		let card = await api.send('GET', `/cards/${cardId}`)
		expect(card.labelIds ?? []).not.toContain(state.labelId)

		await api.send('POST', `/cards/${cardId}/move`, { targetStackId: state.progStackId, afterCardId: null })

		card = await api.send('GET', `/cards/${cardId}`)
		expect(card.labelIds).toContain(state.labelId)
	})

	test('start_timer on entry, then stop_timer, writes an elapsed time entry (#73)', async () => {
		await api.send('POST', `/boards/${state.boardId}/automation-rules`, {
			trigger: 'card_entered_role', action: 'start_timer', params: { role: ROLE_IN_PROGRESS },
		})
		await api.send('POST', `/boards/${state.boardId}/automation-rules`, {
			trigger: 'card_entered_role', action: 'stop_timer', params: { role: ROLE_DONE },
		})
		const cardId = (await api.send('POST', '/cards', { stackId: state.todoStackId, title: 'Timed work' })).id

		// No time entries before any timer runs.
		expect(await api.send('GET', `/cards/${cardId}/time-entries`)).toHaveLength(0)

		// Entering the in-progress column starts a RUNNING timer — no finished
		// entry is written yet (the clock is still ticking).
		await api.send('POST', `/cards/${cardId}/move`, { targetStackId: state.progStackId, afterCardId: null })
		expect(await api.send('GET', `/cards/${cardId}/time-entries`)).toHaveLength(0)

		// Let at least a whole second elapse so the stop records a non-zero span.
		await new Promise((resolve) => setTimeout(resolve, 1500))

		// Entering the done column stops the timer → exactly one finished entry
		// carrying the elapsed seconds, tagged as automatic.
		await api.send('POST', `/cards/${cardId}/move`, { targetStackId: state.doneStackId, afterCardId: null })
		const entries = await api.send('GET', `/cards/${cardId}/time-entries`)
		expect(entries).toHaveLength(1)
		expect(entries[0].seconds).toBeGreaterThanOrEqual(1)
		expect(entries[0].note).toBe('Tracked automatically')
	})

	test('re-entering the start column does not start a second timer (idempotent) (#73)', async () => {
		// Reuses the start/stop rules created by the previous test.
		const cardId = (await api.send('POST', '/cards', { stackId: state.todoStackId, title: 'Idempotent timer' })).id

		// Start, bounce out to a role-less column and back in — the second entry
		// into the start column must be a no-op, not a fresh timer.
		await api.send('POST', `/cards/${cardId}/move`, { targetStackId: state.progStackId, afterCardId: null })
		await new Promise((resolve) => setTimeout(resolve, 1200))
		await api.send('POST', `/cards/${cardId}/move`, { targetStackId: state.todoStackId, afterCardId: null })
		await api.send('POST', `/cards/${cardId}/move`, { targetStackId: state.progStackId, afterCardId: null })

		// Stop → exactly ONE entry, spanning from the ORIGINAL start (idempotent).
		await api.send('POST', `/cards/${cardId}/move`, { targetStackId: state.doneStackId, afterCardId: null })
		const entries = await api.send('GET', `/cards/${cardId}/time-entries`)
		expect(entries).toHaveLength(1)
		expect(entries[0].seconds).toBeGreaterThanOrEqual(1)
	})

	test('a label from another board is rejected at rule creation', async () => {
		const other = await api.send('POST', '/boards', { title: 'Other ' + Math.floor(Date.now() / 1000) })
		const foreignLabel = (await api.send('POST', '/labels', { boardId: other.id, title: 'Foreign', color: null })).id
		const r = await api.raw('POST', `/boards/${state.boardId}/automation-rules`, { trigger: 'card_entered_role', action: 'add_label', params: { role: ROLE_REVIEW, label: foreignLabel } })
		expect(r.ok).toBe(false)
		await api.send('DELETE', `/boards/${other.id}`).catch(() => {})
	})

	test('a rule created in the settings panel persists and lists', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)

		// Open board settings → Automation tab (settings now in the ⋯ More menu).
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /automation/i }).click()

		// The card-rules "Add rule" form: pick In-progress role + add-label, then submit.
		const roleSelect = page.locator(`#auto-role-${state.boardId}`)
		await expect(roleSelect).toBeVisible({ timeout: 8_000 })
		await roleSelect.selectOption(String(ROLE_IN_PROGRESS))
		await page.locator(`#auto-action-${state.boardId}`).selectOption('add_label')
		await page.locator(`#auto-label-${state.boardId}`).selectOption(String(state.labelId))
		await page.getByRole('button', { name: /^Add rule$/ }).last().click()

		// It shows up in the rules list with the readable description.
		await expect(page.locator('.automation__rule-desc', { hasText: /add label "Auto-tagged"/ }).first())
			.toBeVisible({ timeout: 8_000 })

		// And it survives a reload (server round-trip via GET automation-rules).
		const rules = await api.send('GET', `/boards/${state.boardId}/automation-rules`)
		expect(rules.some((rl) => rl.action === 'add_label' && rl.params.label === state.labelId)).toBe(true)
	})
})
