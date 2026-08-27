// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Drag a sub-card OUT of its parent — the inverse of the centre-band nest.
//
// It is offered in LIST view only, because that is the only surface that draws
// the hierarchy: children render indented under their parent, so "drag the row
// out of the indent" is a gesture the user can see themselves perform. The crux
// is the distinction the tests below pin down:
//
//   drop among the parent's OWN children  → plain reorder, parent untouched
//   drop at a TOP-LEVEL position          → the parent is cleared
//
// On the kanban board a sub-card is an ordinary tile in a flat column, so a
// drag there never changes the parent (guarded in dnd-nest.spec.js). It does
// now carry a ↳ marker so it is at least recognisable, and the card's own
// detail panel keeps the explicit, keyboard-reachable detach.

import { test, expect, api, ncLogin, BASE } from './helpers.js'

/**
 * Real-pointer drag (Pragmatic DnD uses the native HTML5 drag adapter, so
 * synthetic events are not enough). Same helper as dnd-nest.spec.js, including
 * the hook that asserts the drop affordance while the pointer is still held.
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
	// Settle on the target with a 1px jiggle: Pragmatic's element adapter only
	// re-evaluates a drop target on a native dragover, so a perfectly still
	// pointer on a freshly registered target never gets one.
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

/** Switch the board to List view via the Display popover. */
async function pickListView(page) {
	await page.locator('.board-view__display-menu button').first().click()
	await page.waitForTimeout(400)
	await page.locator('.action-radio__text', { hasText: /^List$/ }).click()
	await page.waitForTimeout(150)
	await page.keyboard.press('Escape')
	await page.waitForTimeout(200)
	await expect(page.locator('.board-list-row').first()).toBeVisible({ timeout: 8_000 })
	// The virtualizer lays rows out absolutely and re-positions them once the
	// group heights settle; take bounding boxes only after that.
	await page.waitForTimeout(600)
}

test.describe('Drag a sub-card out of its parent (list view)', () => {
	const state = { boardId: 0, u1Id: 0, u2Id: 0, ids: {}, boardUrl: '' }

	/** The stored parent of a card, straight from the server (no cache in between). */
	const parentOf = async (title) => {
		const card = await api.get(`/cards/${state.ids[title]}`)
		return card.parentCardId ?? null
	}

	const row = (page, title) => page
		.locator('.board-list-row-wrap')
		.filter({ hasText: title })

	const rowTitles = async (page) => (await page.locator('.board-list-row__title').allTextContents())
		.map((s) => s.trim())

	/**
	 * Put the fixture back the way each test expects to find it. Called at the top
	 * of every test so the file is retry-safe: Playwright retries a failed test in
	 * place, and these tests deliberately mutate shared board state.
	 *
	 * @return {Promise<void>}
	 */
	async function resetFixture() {
		for (const title of ['UA', 'UB', 'UX']) {
			await api.put(`/cards/${state.ids[title]}/parent`, { parentCardId: state.ids.UP })
		}
	}

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'UnnestDnD ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const u1 = await api.post('/stacks', { boardId: board.id, title: 'U1' })
		const u2 = await api.post('/stacks', { boardId: board.id, title: 'U2' })
		state.u1Id = u1.id
		state.u2Id = u2.id
		// Created in order, so the column's sort keys run UP, UA, UB, UT — and the
		// list renders UP with UA + UB indented under it, then UT at top level.
		for (const title of ['UP', 'UA', 'UB', 'UT']) {
			const card = await api.post('/cards', { stackId: u1.id, title })
			state.ids[title] = card.id
		}
		// UX lives in the OTHER column as a sub-card of UP: a card that stores a
		// parent but renders flush-left, because the list only indents a child
		// under a parent in the same group. UZ gives it something to reorder against.
		for (const title of ['UX', 'UZ']) {
			const card = await api.post('/cards', { stackId: u2.id, title })
			state.ids[title] = card.id
		}
		await api.put(`/cards/${state.ids.UA}/parent`, { parentCardId: state.ids.UP })
		await api.put(`/cards/${state.ids.UB}/parent`, { parentCardId: state.ids.UP })
		await api.put(`/cards/${state.ids.UX}/parent`, { parentCardId: state.ids.UP })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('reordering a child among its siblings does NOT take it out of its parent', async ({ page }) => {
		await resetFixture()
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await pickListView(page)

		// UA and UB are UP's children; U1 therefore renders UP with two indented
		// rows under it, then UT. (The exact sibling order is whatever the previous
		// run left, so it is asserted after the drag, not before.)
		await expect(page.locator('.board-list-row--child')).toHaveCount(2, { timeout: 8_000 })
		expect(await parentOf('UB')).toBe(state.ids.UP)

		// UB onto UA's TOP edge: still inside UP's children, so this is an ordinary
		// reorder — the indicator is indented to the child level and carries none of
		// the detach styling or wording.
		await dragWithMouse(page, row(page, 'UB'), row(page, 'UA'), {
			position: 'top',
			whileHovering: async () => {
				await expect(page.locator('.board-list-drop-indicator')).toHaveCount(1)
				await expect(page.locator('.board-list-drop-indicator--nested')).toHaveCount(1)
				await expect(page.locator('.board-list-drop-indicator--unnest')).toHaveCount(0)
				await expect(page.locator('.board-list-nest-hint--unnest')).toHaveCount(0)
			},
		})

		await expect.poll(async () => (await rowTitles(page)).slice(0, 4), { timeout: 10_000 })
			.toEqual(['UP', 'UB', 'UA', 'UT'])
		// The whole point: the relation survived the reorder.
		expect(await parentOf('UB')).toBe(state.ids.UP)
		expect(await parentOf('UA')).toBe(state.ids.UP)
		// Both are still drawn indented under UP.
		await expect(page.locator('.board-list-row--child')).toHaveCount(2)
	})

	test('dragging a child row out to a top-level position clears its parent', async ({ page }) => {
		await resetFixture()
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await pickListView(page)

		expect(await parentOf('UA')).toBe(state.ids.UP)
		await expect(page.locator('.board-list-row--child').filter({ hasText: 'UA' }))
			.toBeVisible({ timeout: 8_000 })

		// UA onto UT's BOTTOM edge — a top-level slot, so UA leaves UP.
		await dragWithMouse(page, row(page, 'UA'), row(page, 'UT'), {
			position: 'bottom',
			whileHovering: async () => {
				// Distinct from both a plain reorder line and the nest highlight.
				await expect(page.locator('.board-list-drop-indicator--unnest')).toHaveCount(1)
				await expect(page.locator('.board-list-drop-indicator--nested')).toHaveCount(0)
				await expect(page.locator('.board-list-nest-hint--unnest')).toBeVisible()
				await expect(page.locator('.board-list-row-wrap--nest-target')).toHaveCount(0)
			},
		})

		await expect.poll(async () => await parentOf('UA'), { timeout: 10_000 }).toBeNull()

		// The row un-indents and lands where it was dropped.
		await expect(page.locator('.board-list-row--child').filter({ hasText: 'UA' }))
			.toHaveCount(0, { timeout: 10_000 })
		await expect.poll(async () => (await rowTitles(page)).slice(0, 4), { timeout: 10_000 })
			.toEqual(['UP', 'UB', 'UT', 'UA'])

		// Server is the source of truth — it survives a reload.
		await page.reload()
		await expect(page.locator('.board-list-row').first()).toBeVisible({ timeout: 15_000 })
		expect(await parentOf('UA')).toBeNull()
		await expect(page.locator('.board-list-row--child').filter({ hasText: 'UA' }))
			.toHaveCount(0, { timeout: 8_000 })
		// UB is untouched by all of this.
		expect(await parentOf('UB')).toBe(state.ids.UP)
	})

	test('a sub-card whose parent is in another column reorders without losing it', async ({ page }) => {
		// It renders flush-left (the list only indents a child under a parent in the
		// SAME group), so there is no indent to drag it out of — and every position
		// open to it is a top-level one. Levels are therefore compared as RENDERED,
		// never as stored, or this card could never be reordered at all.
		await resetFixture()
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await pickListView(page)

		expect(await parentOf('UX')).toBe(state.ids.UP)
		await expect(page.locator('.board-list-row--child').filter({ hasText: 'UX' }))
			.toHaveCount(0)

		await dragWithMouse(page, row(page, 'UX'), row(page, 'UZ'), {
			position: 'bottom',
			whileHovering: async () => {
				// An ordinary reorder: no detach rule, no detach pill.
				await expect(page.locator('.board-list-drop-indicator--unnest')).toHaveCount(0)
				await expect(page.locator('.board-list-nest-hint--unnest')).toHaveCount(0)
			},
		})

		// Reordered inside U2, and still a sub-card of UP over in U1.
		await expect.poll(async () => (await rowTitles(page)).slice(-2), { timeout: 10_000 })
			.toEqual(['UZ', 'UX'])
		expect(await parentOf('UX')).toBe(state.ids.UP)
	})

	test('a kanban tile marks a sub-card; a top-level card carries no marker', async ({ page }) => {
		await resetFixture()
		// The board draws no indent, so without this marker a sub-card is
		// indistinguishable from any other tile — which is exactly why a kanban
		// drag must not be allowed to detach one.
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 15_000 })

		const tile = (title) => page.locator('.card-tile').filter({ hasText: title })
		await expect(tile('UP')).toBeVisible({ timeout: 8_000 })

		expect(await parentOf('UB')).toBe(state.ids.UP)
		await expect(tile('UB').locator('.card-tile__subcard')).toBeVisible({ timeout: 8_000 })
		// …including one whose parent lives in another column — the tile is all the
		// board has to go on there.
		await expect(tile('UX').locator('.card-tile__subcard')).toBeVisible({ timeout: 8_000 })
		// UT and UZ never had a parent; UP is one.
		await expect(tile('UT').locator('.card-tile__subcard')).toHaveCount(0)
		await expect(tile('UZ').locator('.card-tile__subcard')).toHaveCount(0)
		await expect(tile('UP').locator('.card-tile__subcard')).toHaveCount(0)
	})

	test('the card panel still detaches a sub-card without any dragging', async ({ page }) => {
		// Drag must never be the only way out: the panel action is the accessible
		// path, and it is one click from the sub-card itself.
		await resetFixture()
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 15_000 })

		await page.locator('.card-tile').filter({ hasText: 'UB' }).click()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const parentLink = page.locator('.card-modal__parent-link')
		await expect(parentLink).toHaveText('UP', { timeout: 8_000 })

		await page.getByTitle('Detach from parent').click()

		await expect.poll(async () => await parentOf('UB'), { timeout: 10_000 }).toBeNull()
		await expect(parentLink).toHaveCount(0, { timeout: 8_000 })
	})
})
