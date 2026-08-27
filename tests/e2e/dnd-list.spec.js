// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// List-view drag and drop. The list renders one flat virtualized row model, so a
// group with no cards has no card row to aim at — its only drop target is the
// overlay on the group header. That overlay used to be permanently
// `pointer-events: none`, which native dragenter/dragover hit-testing skips, so
// a card could never be dropped into an empty column in list view.

import { test, expect, api, ncLogin, BASE } from './helpers.js'

/**
 * Real-pointer drag (Pragmatic DnD uses the native HTML5 drag adapter, so
 * synthetic events are not enough). Mirrors dnd.spec.js's helper, plus an
 * optional assertion hook that runs while the pointer is held over the target.
 *
 * @param {import('@playwright/test').Page} page - the page under test
 * @param {import('@playwright/test').Locator} source - element to pick up
 * @param {import('@playwright/test').Locator} target - element to drop onto
 * @param {object} [options] - drag options
 * @param {'top'|'middle'|'bottom'} [options.position] - where in the target to aim
 * @param {Function} [options.whileHovering] - async assertion run before mouse-up
 * @return {Promise<void>}
 */
async function dragWithMouse(page, source, target, { position = 'middle', whileHovering } = {}) {
	const srcBox = await source.boundingBox()
	const tgtBox = await target.boundingBox()
	if (!srcBox || !tgtBox) throw new Error('Could not get bounding boxes for drag')

	const srcX = srcBox.x + srcBox.width / 2
	const srcY = srcBox.y + srcBox.height / 2
	const tgtX = tgtBox.x + tgtBox.width / 2
	const frac = position === 'top' ? 0.2 : position === 'bottom' ? 0.8 : 0.5
	const tgtY = tgtBox.y + tgtBox.height * frac

	await page.mouse.move(srcX, srcY)
	await page.mouse.down()
	const steps = 15
	for (let i = 1; i <= steps; i++) {
		await page.mouse.move(
			srcX + (tgtX - srcX) * (i / steps),
			srcY + (tgtY - srcY) * (i / steps),
			{ steps: 1 },
		)
		await page.waitForTimeout(20)
	}
	await page.waitForTimeout(200)
	if (whileHovering) await whileHovering()
	await page.mouse.up()
	await page.waitForTimeout(500)
}

/** Switch the board to List view via the Display popover (as display-sort.spec.js does). */
async function pickListView(page) {
	await page.locator('.board-view__display-menu button').first().click()
	// Let the teleported popover settle before clicking the radio.
	await page.waitForTimeout(400)
	await page.locator('.action-radio__text', { hasText: /^List$/ }).click()
	await page.waitForTimeout(150)
	await page.keyboard.press('Escape')
	await page.waitForTimeout(200)
	await expect(page.locator('.board-list-row').first()).toBeVisible({ timeout: 8_000 })
}

test.describe('List view drag and drop', () => {
	const state = { boardId: 0, l1Id: 0, l2Id: 0, boardUrl: '' }

	// Group headers carry a plain card count when no WIP limit is set — the
	// cheapest way to assert which group a row belongs to in a flat row model.
	const groupCount = (page, title) => page
		.locator('.board-list-group')
		.filter({ hasText: title })
		.locator('.board-list-group__count')

	const rowTitles = async (page) => (await page.locator('.board-list-row__title').allTextContents())
		.map((s) => s.trim())

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'ListDnD ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		// L1 holds the cards, L2 stays EMPTY — the case under test.
		const l1 = await api.post('/stacks', { boardId: board.id, title: 'L1' })
		const l2 = await api.post('/stacks', { boardId: board.id, title: 'L2' })
		state.l1Id = l1.id
		state.l2Id = l2.id
		for (const title of ['C1', 'C2', 'C3']) {
			await api.post('/cards', { stackId: l1.id, title })
		}
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('drops a card into an empty group, highlights the target, and persists after reload', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await pickListView(page)

		expect(await rowTitles(page)).toEqual(['C1', 'C2', 'C3'])
		await expect(groupCount(page, 'L1')).toHaveText('3', { timeout: 8_000 })
		await expect(groupCount(page, 'L2')).toHaveText('0')

		const c1 = page.locator('.board-list-row-wrap').filter({ hasText: 'C1' })
		const l2Drop = page.locator(`.board-list-group-drop[data-stack-id="${state.l2Id}"]`)
		await expect(c1).toBeVisible({ timeout: 5_000 })

		await dragWithMouse(page, c1, l2Drop, {
			// The empty group must show a drop highlight while it is hovered.
			whileHovering: async () => {
				await expect(l2Drop).toHaveClass(/board-list-group-drop--over/)
			},
		})

		// C1 moved into the empty group; the flat row order follows the groups.
		await expect(groupCount(page, 'L2')).toHaveText('1', { timeout: 8_000 })
		await expect(groupCount(page, 'L1')).toHaveText('2')
		await expect.poll(async () => await rowTitles(page)).toEqual(['C2', 'C3', 'C1'])

		// Server is the source of truth — the move survives a reload.
		await page.reload()
		await expect(page.locator('.board-list-row').first()).toBeVisible({ timeout: 15_000 })
		await expect(groupCount(page, 'L2')).toHaveText('1', { timeout: 8_000 })
		await expect(groupCount(page, 'L1')).toHaveText('2')
		await expect.poll(async () => await rowTitles(page)).toEqual(['C2', 'C3', 'C1'])
	})

	test('the group header collapse toggle still responds to clicks', async ({ page }) => {
		// Regression guard: the drop overlay covers the header button, so it must be
		// inert whenever no drag is in flight.
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await pickListView(page)

		const l1Header = page.locator('.board-list-group').filter({ hasText: 'L1' })
		await expect(l1Header).toHaveAttribute('aria-expanded', 'true')

		await l1Header.click()
		await expect(l1Header).toHaveAttribute('aria-expanded', 'false', { timeout: 5_000 })
		await expect.poll(async () => await rowTitles(page)).toEqual(['C1'])

		await l1Header.click()
		await expect(l1Header).toHaveAttribute('aria-expanded', 'true', { timeout: 5_000 })
		await expect.poll(async () => await rowTitles(page)).toEqual(['C2', 'C3', 'C1'])
	})

	test('dropping below the last card of a group still reorders within that group', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await pickListView(page)

		expect(await rowTitles(page)).toEqual(['C2', 'C3', 'C1'])

		// Drop C2 on the BOTTOM edge of C3 — the card-target path, which the empty
		// group fix must not have hijacked.
		const c2 = page.locator('.board-list-row-wrap').filter({ hasText: 'C2' })
		const c3 = page.locator('.board-list-row-wrap').filter({ hasText: 'C3' })
		await dragWithMouse(page, c2, c3, { position: 'bottom' })

		await expect.poll(async () => await rowTitles(page)).toEqual(['C3', 'C2', 'C1'])
		await expect(groupCount(page, 'L1')).toHaveText('2')
		await expect(groupCount(page, 'L2')).toHaveText('1')

		await page.reload()
		await expect(page.locator('.board-list-row').first()).toBeVisible({ timeout: 15_000 })
		await expect.poll(async () => await rowTitles(page)).toEqual(['C3', 'C2', 'C1'])
	})
})
