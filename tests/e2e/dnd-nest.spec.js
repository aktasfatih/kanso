// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Drag to nest (#5885): a card row has THREE hit zones, not two — the top and
// bottom edges still reorder, the centre band makes the dragged card a sub-card
// of the hovered one. The centre band is only offered when the server would
// accept the relation (one level deep, no self-parent, a parent can't become a
// child), so a user never gets an affordance that 400s.
//
// The tests run in file order against one board and build on each other, the
// same way dnd.spec.js does.

import { test, expect, api, ncLogin, BASE } from './helpers.js'

/**
 * Real-pointer drag (Pragmatic DnD uses the native HTML5 drag adapter, so
 * synthetic events are not enough). Same helper as dnd-list.spec.js, with the
 * assertion hook that runs while the pointer is still held over the target —
 * the only way to check a drop affordance BEFORE the release.
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
	const frac = position === 'top' ? 0.15 : position === 'bottom' ? 0.85 : 0.5
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
	// Settle on the target with a 1px jiggle. Pragmatic's element adapter only
	// re-evaluates a drop target on a native dragover, so a pointer parked
	// perfectly still on a target that (re)registered a moment ago never gets
	// one — a real hand always keeps them coming. Keeps us inside the same hit
	// zone; only the event stream matters.
	for (let i = 0; i < 4; i++) {
		await page.mouse.move(tgtX + (i % 2 ? 1 : -1), tgtY, { steps: 1 })
		await page.waitForTimeout(60)
	}
	await page.waitForTimeout(200)
	if (whileHovering) await whileHovering()
	await page.mouse.move(tgtX, tgtY, { steps: 1 })
	await page.waitForTimeout(60)
	await page.mouse.up()
	await page.waitForTimeout(600)
}

/** Switch the board to List view via the Display popover (as dnd-list.spec.js does). */
async function pickListView(page) {
	await page.locator('.board-view__display-menu button').first().click()
	await page.waitForTimeout(400)
	await page.locator('.action-radio__text', { hasText: /^List$/ }).click()
	await page.waitForTimeout(150)
	await page.keyboard.press('Escape')
	await page.waitForTimeout(200)
	await expect(page.locator('.board-list-row').first()).toBeVisible({ timeout: 8_000 })
	// The virtualizer positions rows absolutely and re-lays them out once the
	// group heights settle; grab bounding boxes only after that, or a drag can
	// start on whatever row has since slid under the pointer.
	await page.waitForTimeout(600)
}

test.describe('Drag a card onto another card to nest it (#5885)', () => {
	const state = { boardId: 0, n1Id: 0, n2Id: 0, ids: {}, boardUrl: '' }

	/** The stored parent of a card, straight from the server (no cache in between). */
	const parentOf = async (title) => {
		const card = await api.get(`/cards/${state.ids[title]}`)
		return card.parentCardId ?? null
	}

	const tile = (page, title) => page
		.locator('.stack-column')
		.locator('.card-tile-wrap .card-tile')
		.filter({ hasText: title })

	const row = (page, title) => page
		.locator('.board-list-row-wrap')
		.filter({ hasText: title })

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'NestDnD ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		// N1 holds every card; N2 stays empty — the "drop into open space" target
		// that un-nests a sub-card.
		const n1 = await api.post('/stacks', { boardId: board.id, title: 'N1' })
		const n2 = await api.post('/stacks', { boardId: board.id, title: 'N2' })
		state.n1Id = n1.id
		state.n2Id = n2.id
		// Created in order, so the column renders NP, NC, NX, NY top-to-bottom.
		for (const title of ['NP', 'NC', 'NX', 'NY']) {
			const card = await api.post('/cards', { stackId: n1.id, title })
			state.ids[title] = card.id
		}
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('dropping on a tile centre nests the card and persists after reload', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 15_000 })
		await expect(tile(page, 'NP')).toBeVisible({ timeout: 8_000 })
		expect(await parentOf('NC')).toBeNull()

		await dragWithMouse(page, tile(page, 'NC'), tile(page, 'NP'), {
			position: 'middle',
			// The nest affordance must be readable BEFORE the drop: the whole target
			// tile highlights (reordering only ever draws a thin edge line).
			whileHovering: async () => {
				await expect(tile(page, 'NP')).toHaveClass(/card-tile--nest-target/)
				await expect(page.locator('.card-tile__drop-line')).toHaveCount(0)
			},
		})

		await expect.poll(async () => await parentOf('NC'), { timeout: 10_000 })
			.toBe(state.ids.NP)

		// The parent tile grows its sub-card progress badge.
		await expect(tile(page, 'NP').locator('.card-tile__children')).toHaveText('0/1', { timeout: 8_000 })

		// Server is the source of truth — the relation survives a reload.
		await page.reload()
		await page.waitForSelector('.stack-column', { timeout: 15_000 })
		await expect(tile(page, 'NP').locator('.card-tile__children')).toHaveText('0/1', { timeout: 10_000 })
	})

	test('a sub-card renders indented under its parent in list view', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await pickListView(page)

		const child = page.locator('.board-list-row--child').filter({ hasText: 'NC' })
		await expect(child).toBeVisible({ timeout: 8_000 })
	})

	test('dropping on a tile edge still reorders and leaves the parent untouched', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 15_000 })

		const cards = page.locator('.stack-column').nth(0).locator('.card-tile-wrap .card-tile')
		await expect(cards.nth(2)).toContainText('NX', { timeout: 8_000 })
		await expect(cards.nth(3)).toContainText('NY')

		// Drop NY on NX's TOP edge — the classic reorder path.
		await dragWithMouse(page, tile(page, 'NY'), tile(page, 'NX'), {
			position: 'top',
			whileHovering: async () => {
				// Edge line, not the nest highlight.
				await expect(page.locator('.card-tile__drop-line')).toHaveCount(1)
				await expect(tile(page, 'NX')).not.toHaveClass(/card-tile--nest-target/)
			},
		})

		await expect(cards.nth(2)).toContainText('NY', { timeout: 8_000 })
		await expect(cards.nth(3)).toContainText('NX')
		// A reorder never invents a parent.
		expect(await parentOf('NY')).toBeNull()
		expect(await parentOf('NX')).toBeNull()

		// …and it never REMOVES one either: NC is a sub-card of NP, and reordering
		// it must leave that relation exactly where it was.
		await dragWithMouse(page, tile(page, 'NC'), tile(page, 'NY'), { position: 'bottom' })
		await page.waitForTimeout(500)
		expect(await parentOf('NC')).toBe(state.ids.NP)
	})

	test('a card that already has a parent offers no nest zone (one level only)', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 15_000 })
		// NC is a sub-card of NP from the first test.
		expect(await parentOf('NC')).toBe(state.ids.NP)

		await dragWithMouse(page, tile(page, 'NX'), tile(page, 'NC'), {
			position: 'middle',
			whileHovering: async () => {
				// Centre of a card that is itself a child → suppressed, so the drop
				// falls back to the nearest edge and stays a plain reorder.
				await expect(tile(page, 'NC')).not.toHaveClass(/card-tile--nest-target/)
				await expect(page.locator('.card-tile__drop-line')).toHaveCount(1)
			},
		})

		// No two-level nesting: NX did not become NC's child.
		await page.waitForTimeout(500)
		expect(await parentOf('NX')).toBeNull()
		expect(await parentOf('NC')).toBe(state.ids.NP)
	})

	test('moving a sub-card to another column keeps it a sub-card', async ({ page }) => {
		// On a kanban board a sub-card is an ordinary-looking tile, so the everyday
		// "drag this subtask into the next column" gesture must NOT quietly break
		// the relation. Detaching stays an explicit action in the card detail panel.
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 15_000 })
		expect(await parentOf('NC')).toBe(state.ids.NP)

		// N2 is empty: its card list is the "open space" column drop target.
		const n2Cards = page.locator('.stack-column').nth(1).locator('.stack-column__cards')
		await dragWithMouse(page, tile(page, 'NC'), n2Cards)

		await expect.poll(async () => {
			const card = await api.get(`/cards/${state.ids.NC}`)
			return card.stackId
		}, { timeout: 10_000 }).toBe(state.n2Id)
		expect(await parentOf('NC')).toBe(state.ids.NP)
		// The parent keeps its sub-card badge across the column move.
		await expect(tile(page, 'NP').locator('.card-tile__children')).toHaveText('0/1', { timeout: 8_000 })
	})

	test('list view nests on a centre drop and pulls the card into the parent column', async ({ page }) => {
		// Detach NC through the API (the panel action drag-to-nest deliberately does
		// NOT replace) so the drag below is a genuine re-nest from another column.
		await api.put(`/cards/${state.ids.NC}/parent`, { parentCardId: null })

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await pickListView(page)

		// NC sits in N2 while its parent NP is in N1 (previous test), so the list
		// renders it top-level. Re-nesting has to move it into its parent's column,
		// or the list could never indent it.
		await expect(row(page, 'NC')).toBeVisible({ timeout: 8_000 })

		await dragWithMouse(page, row(page, 'NC'), row(page, 'NP'), {
			position: 'middle',
			whileHovering: async () => {
				await expect(row(page, 'NP')).toHaveClass(/board-list-row-wrap--nest-target/)
				await expect(page.locator('.board-list-drop-indicator')).toHaveCount(0)
			},
		})

		await expect.poll(async () => await parentOf('NC'), { timeout: 10_000 })
			.toBe(state.ids.NP)

		// Indented under NP, in NP's group.
		const child = page.locator('.board-list-row--child').filter({ hasText: 'NC' })
		await expect(child).toBeVisible({ timeout: 10_000 })
		await expect.poll(async () => {
			const card = await api.get(`/cards/${state.ids.NC}`)
			return card.stackId
		}, { timeout: 10_000 }).toBe(state.n1Id)
	})
})
