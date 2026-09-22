// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Card #10705 — a picker row must not drop keyboard focus while its toggle is
// in flight.
//
// Both attribute pickers in the card modal (assignee and label) used to set
// `disabled` on EVERY row for as long as the toggle mutation was pending.
// Disabling the element that currently has focus is one of the few things the
// HTML spec makes the browser undo for you: it blurs it, and focus falls back
// to `<body>`. So a keyboard user picking a second assignee had to tab all the
// way back into the popover after every single pick — once per person added.
//
// The rows now carry `aria-busy` for the in-flight window instead, which says
// the same thing to assistive tech without taking the row out of the focus
// order, and the handler itself refuses a re-entrant toggle so nothing
// double-submits in the window `disabled` used to cover.
//
// These tests therefore assert FOCUS, not presence: a row that merely still
// exists proves nothing here — it existed before the fix too, it just wasn't
// focused any more.
//
// Requests are held open by a gate the test releases, so "in flight" is a
// window the test controls rather than a race against a fast local server.

import { test, expect, api, ncLogin, BASE, me } from './helpers.js'

test.describe('Picker rows keep keyboard focus across an in-flight toggle (#10705)', () => {
	const state = { boardId: 0, assignCardId: 0, labelCardId: 0, labelId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Picker focus ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		state.assignCardId = (await api.post('/cards', { stackId: stack.id, title: 'Focus assign case' })).id
		state.labelCardId = (await api.post('/cards', { stackId: stack.id, title: 'Focus label case' })).id
		state.labelId = (await api.post('/labels', { boardId: board.id, title: 'Focusable', color: '2ecc71' })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	/**
	 * Open the board and the named card's modal.
	 *
	 * @param {import('@playwright/test').Page} page The page.
	 * @param {string} title The card title to open.
	 */
	async function openCard(page, title) {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })
		await page.locator('.card-tile').filter({ hasText: title }).click()
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })
	}

	/**
	 * Let the browser finish moving focus before asserting where it ended up.
	 *
	 * Chromium does NOT blur a disabled element in the same task that disables
	 * it — measured on the pre-fix build with the request held open: the row
	 * still had focus the instant `press()` resolved (`disabled` already in the
	 * DOM), `<body>` had it 50ms later, and the modal's focus trap had pulled it
	 * onto an unrelated chip by 200ms. So an immediate `toBeFocused()` passes
	 * even on the bug, and this wait is what makes the assertion mean anything.
	 * It is an unconditional sleep on a browser-internal focus update, not on a
	 * round trip (the request stays gated throughout), so it cannot get slower
	 * on a loaded runner.
	 *
	 * @param {import('@playwright/test').Page} page The page.
	 */
	async function settleFocus(page) {
		await page.waitForTimeout(500)
	}

	/**
	 * Hold every request whose URL matches `pattern` until the returned
	 * `release()` is called, counting how many were made.
	 *
	 * @param {import('@playwright/test').Page} page The page.
	 * @param {RegExp} pattern URL pattern to gate.
	 * @return {Promise<{release: () => void, count: () => number}>} the gate.
	 */
	async function gateRequests(page, pattern) {
		let open
		const gate = new Promise((resolve) => { open = resolve })
		let seen = 0
		await page.route(pattern, async (route) => {
			seen++
			await gate
			await route.continue()
		})
		return { release: () => open(), count: () => seen }
	}

	test('the assignee row stays focused while its assign request is in flight', async ({ page }) => {
		// Start from an unassigned card so the row toggles ON.
		const before = await api.get(`/cards/${state.assignCardId}`)
		for (const uid of before.assigneeIds || []) {
			await api.delete(`/cards/${state.assignCardId}/assignees/${uid}`)
		}

		await openCard(page, 'Focus assign case')
		await page.locator('.card-modal__attrbar button[data-pill="assign"]').click()

		const popover = page.locator('.card-modal__attrbar .card-modal__popover')
		await expect(popover).toBeVisible()
		const row = popover.locator('.card-modal__assign-option', { hasText: me }).first()
		await expect(row).toBeVisible()

		const gate = await gateRequests(page, /\/api\/cards\/\d+\/assignees\//)

		// Keyboard activation: Playwright focuses the row, then sends a real
		// Enter, which is what natively activates a <button>.
		await row.press('Enter')

		// ── In flight ────────────────────────────────────────────────────────
		// The row announces itself as busy...
		await expect(row).toHaveAttribute('aria-busy', 'true')
		// ...and — the actual bug — it still has focus. Before the fix this was
		// `<body>`, because `disabled` had just been set on the focused element.
		await settleFocus(page)
		await expect(row).toBeFocused()

		// Hammering Enter in the busy window must not queue more writes. It is
		// what `disabled` was there for, so the handler has to cover it now.
		await row.press('Enter')
		await row.press('Enter')
		await row.press('Enter')

		gate.release()

		// ── Settled ──────────────────────────────────────────────────────────
		await expect(page.locator('.card-modal__attrbar .card-modal__assignee-pill')).toHaveCount(1)
		await expect(row).not.toHaveAttribute('aria-busy', 'true')
		// Focus survived the whole round trip, so the next pick is one key away.
		await expect(row).toBeFocused()

		expect(gate.count()).toBe(1)
		const served = await api.get(`/cards/${state.assignCardId}`)
		expect(served.assigneeIds).toEqual([me])
	})

	test('the label row stays focused while its assign request is in flight', async ({ page }) => {
		const before = await api.get(`/cards/${state.labelCardId}`)
		for (const id of before.labelIds || []) {
			await api.delete(`/cards/${state.labelCardId}/labels/${id}`)
		}

		await openCard(page, 'Focus label case')
		await page.locator('.card-modal__attrbar button[data-pill="label"]').click()

		const popover = page.locator('.card-modal__attrbar .card-modal__popover')
		await expect(popover).toBeVisible()
		const row = popover.locator('.card-modal__label-toggle', { hasText: 'Focusable' }).first()
		await expect(row).toBeVisible()

		const gate = await gateRequests(page, /\/api\/cards\/\d+\/labels\//)

		await row.press('Enter')

		await expect(row).toHaveAttribute('aria-busy', 'true')
		await settleFocus(page)
		await expect(row).toBeFocused()

		await row.press('Enter')
		await row.press('Enter')

		gate.release()

		await expect(page.locator('.card-modal__attrbar .card-modal__label-chip')).toHaveCount(1)
		await expect(row).not.toHaveAttribute('aria-busy', 'true')
		await expect(row).toBeFocused()

		expect(gate.count()).toBe(1)
		const served = await api.get(`/cards/${state.labelCardId}`)
		expect(served.labelIds).toEqual([state.labelId])
	})
})
