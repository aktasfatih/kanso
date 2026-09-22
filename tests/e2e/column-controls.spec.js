// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

/**
 * Open the ⋯ NcActions menu for the first column on the page and return the
 * teleported dialog locator. All tests in this file have a single column.
 *
 * NcActions teleports its menu panel to <body> as a [role="dialog"]. We wait
 * for it to appear and return a locator scoped to that dialog so callers can
 * find items without accidentally hitting other page elements.
 */
async function openColumnMenu(page) {
	// The NcActions trigger button lives inside .stack-column__actions
	await page.locator('.stack-column__actions button').first().click()
	// Wait for the teleported menu dialog to appear
	const dialog = page.locator('[role="dialog"]').first()
	await expect(dialog).toBeVisible()
	return dialog
}

/**
 * Submit the WIP-limit field. The menu holds MORE THAN ONE submittable field
 * (the column description arrived in #10474), and every one of them renders a
 * control whose accessible name is "Submit" — so the button is looked up inside
 * the WIP field's own <form>, not anywhere in the menu.
 */
async function submitWipField(page, dialog) {
	const wipForm = dialog.locator('form', {
		has: page.getByRole('spinbutton', { name: /wip limit/i }),
	})
	await wipForm.getByRole('button', { name: /^submit$/i }).click()
}

test.describe('Column controls (role + WIP limit)', () => {
	const state = { boardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.send('POST', '/boards', { title: 'Column Controls E2E' })
		state.boardId = board.id
		await api.send('POST', '/stacks', { boardId: board.id, title: 'Control Column' })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await api.send('DELETE', `/boards/${state.boardId}`).catch(() => {})
		}
	})

	// ── Test: Set status to "Done" via ⋯ menu → role chip shows "Done" ─────────
	test('Set status to "Done" via ⋯ menu → role chip shows "Done"', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column__header', { timeout: 15_000 })

		const dialog = await openColumnMenu(page)

		// NcActionRadio renders as <input type="radio"> visually hidden behind an
		// SVG icon. The <li> wrapper is the clickable row; clicking the label text
		// avoids the pointer-events blocker on the SVG.
		// The listitem contains a radio + a text node with the role label.
		const doneItem = dialog.locator('li', { hasText: /^done$/i })
		await expect(doneItem).toBeVisible()
		await doneItem.click()

		// Role chip should now show "Done"
		const chip = page.locator('.stack-column__role-chip', { hasText: 'Done' })
		await expect(chip).toBeVisible()

		// Persisted: reload and still shows "Done"
		await page.reload()
		await page.waitForSelector('.stack-column__header', { timeout: 15_000 })
		await expect(page.locator('.stack-column__role-chip', { hasText: 'Done' })).toBeVisible()
	})

	// ── Test: Set WIP limit via ⋯ menu → badge shows limit ──────────────────────
	test('Set WIP limit via ⋯ menu → badge shows limit', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column__header', { timeout: 15_000 })

		const dialog = await openColumnMenu(page)

		// NcActionInput renders as role="spinbutton" with accessible name "WIP limit"
		const wipInput = dialog.getByRole('spinbutton', { name: /wip limit/i })
		await expect(wipInput).toBeVisible()
		await wipInput.fill('3')
		// Submit via the NcActionInput's own submit button
		await submitWipField(page, dialog)

		// Close menu if still open
		await page.keyboard.press('Escape')

		// WIP badge should reflect the new limit (e.g. "0 / 3")
		const badge = page.locator('.stack-column__badge', { hasText: '/ 3' })
		await expect(badge).toBeVisible()

		// Persisted: reload and still shows limit
		await page.reload()
		await page.waitForSelector('.stack-column__header', { timeout: 15_000 })
		await expect(page.locator('.stack-column__badge', { hasText: '/ 3' })).toBeVisible()
	})

	// ── Test: Clear WIP limit (set to 0) removes the "/ N" from badge ───────────
	test('Clear WIP limit (set to 0) removes the "/ N" from badge', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column__header', { timeout: 15_000 })

		const dialog = await openColumnMenu(page)

		const wipInput = dialog.getByRole('spinbutton', { name: /wip limit/i })
		await expect(wipInput).toBeVisible()
		await wipInput.fill('0')
		await submitWipField(page, dialog)

		await page.keyboard.press('Escape')

		// Badge should no longer contain "/ N"
		await expect(page.locator('.stack-column__badge', { hasText: '/ 3' })).toHaveCount(0, { timeout: 8_000 })
	})

	// ── Test: Rename action in ⋯ menu triggers inline rename ────────────────────
	// Run LAST so earlier tests are not affected by the title mutation.
	test('Rename action in ⋯ menu triggers inline rename', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column__header', { timeout: 15_000 })

		const dialog = await openColumnMenu(page)

		// Verify the Rename column action exists in the menu.
		const renameItem = dialog.locator('li', { hasText: /rename column/i })
		await expect(renameItem).toBeVisible()
		const renameBtn = renameItem.locator('button')
		await renameBtn.click()

		// The app guards the rename field against the closing menu's focus-trap
		// blur (StackColumn.onTitleBlur), so the input stays open on its own.
		const input = page.locator('.stack-column__title-input')
		await expect(input).toBeVisible()
		await input.fill('Renamed via Menu')
		await input.press('Enter')

		await expect(page.locator('.stack-column__title', { hasText: 'Renamed via Menu' })).toBeVisible()
	})
})
