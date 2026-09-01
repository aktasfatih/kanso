// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10062 — "Find the card on board" is the reverse of search: from an open card,
// point at where that card actually sits. The failure modes are all silent ones,
// so each is pinned here: the target card is the LAST of 60 in its column (its
// tile is not even mounted before the action runs, so this genuinely exercises
// the virtualizer, not a scrollIntoView on an already-visible node), the column
// starts collapsed in one case, and an active filter that excludes the card must
// TELL the user rather than scroll nowhere.

import { test, expect, BASE, api, ncLogin } from './helpers.js'

test.describe('Find the card on board (#10062)', () => {
	const TOTAL = 60
	const state = { boardId: 0, stackId: 0, targetId: 0, archivedId: 0 }

	const boardUrl = () => `${BASE}/index.php/apps/kanso#/board/${state.boardId}`
	const cardUrl = (id, query = '') =>
		`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${id}${query}`

	const findAction = (page) => page.locator('[data-test="card-find-on-board"]')
	const targetTile = (page) => page.locator(`.card-tile[data-card-id="${state.targetId}"]`)

	async function openCardMenu(page, url) {
		await page.goto(url)
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 15_000 })
		await page.locator('.card-modal__actions-menu button').first().click()
	}

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Find on board ' + Date.now() })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'Long column' })).id
		// 60 cards; the one we hunt for is dead last, far below the fold.
		for (let i = 1; i < TOTAL; i++) {
			await api.post('/cards', { stackId: state.stackId, title: `Filler ${i}` })
		}
		state.targetId = (await api.post('/cards', { stackId: state.stackId, title: 'Needle in the haystack' })).id
		state.archivedId = (await api.post('/cards', { stackId: state.stackId, title: 'Archived needle' })).id
		await api.patch(`/cards/${state.archivedId}`, { archived: true })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('scrolls a far-down card into view and rings it', async ({ page }) => {
		await ncLogin(page)

		// Baseline: the card is genuinely NOT rendered on a cold board load. If this
		// ever stops holding, the test below would prove nothing about scrolling.
		await page.goto(boardUrl())
		await page.waitForSelector('.card-tile-wrap', { timeout: 15_000 })
		await expect(targetTile(page)).toHaveCount(0)

		await openCardMenu(page, cardUrl(state.targetId))
		await expect(findAction(page)).toBeVisible({ timeout: 8000 })
		await findAction(page).click()

		// The modal closed and we are back on the board.
		await expect(page.locator('.card-modal')).toHaveCount(0, { timeout: 10_000 })

		// The tile is now mounted, on screen, and wearing the "here it is" ring.
		await expect(targetTile(page)).toBeVisible({ timeout: 10_000 })
		await expect(targetTile(page)).toBeInViewport({ timeout: 10_000 })
		await expect(targetTile(page)).toHaveClass(/card-tile--revealed/)

		// `reveal` is consumed, so a reload does not re-fire the jump.
		await expect.poll(() => page.url(), { timeout: 10_000 }).not.toContain('reveal=')
	})

	test('expands a collapsed column first', async ({ page }) => {
		await ncLogin(page)
		await page.goto(boardUrl())
		await page.waitForSelector('.stack-column', { timeout: 15_000 })

		// Collapse the column the same way the UI does (per-user localStorage).
		await page.evaluate(([boardId, stackId]) => {
			localStorage.setItem(`kanso.stackCollapsed.${boardId}`, JSON.stringify([stackId]))
		}, [String(state.boardId), state.stackId])
		await page.reload()
		await expect(page.locator('.stack-column--collapsed')).toHaveCount(1, { timeout: 15_000 })

		await openCardMenu(page, cardUrl(state.targetId))
		await findAction(page).click()

		// The rail expanded and the card is on screen inside it.
		await expect(page.locator('.stack-column--collapsed')).toHaveCount(0, { timeout: 10_000 })
		await expect(targetTile(page)).toBeInViewport({ timeout: 10_000 })
	})

	test('finds the card inside its swimlane when the board is grouped', async ({ page }) => {
		await ncLogin(page)
		await page.goto(boardUrl())
		await page.waitForSelector('.stack-column', { timeout: 15_000 })

		// Group by assignee: every card is unassigned, so they all land in one lane
		// whose columns are registered under a lane-scoped ref key, not the flat one.
		await page.evaluate(([boardId]) => {
			localStorage.setItem(`kanso.swimlaneMode.${boardId}`, 'assignee')
		}, [String(state.boardId)])
		await page.reload()
		await expect(page.locator('.swimlane')).toHaveCount(1, { timeout: 15_000 })
		await expect(targetTile(page)).toHaveCount(0)

		await openCardMenu(page, cardUrl(state.targetId))
		await findAction(page).click()

		await expect(targetTile(page)).toBeInViewport({ timeout: 10_000 })
		await expect(page.locator('.swimlane')).toHaveCount(1)
	})

	test('says so when the active filter hides the card', async ({ page }) => {
		await ncLogin(page)
		// fp=4 → only Urgent cards. The target has no priority, so it is filtered
		// out: there is no tile to scroll to and the user has to be told why.
		await openCardMenu(page, cardUrl(state.targetId, '?fp=4'))
		await findAction(page).click()

		await expect(page.locator('.toastify.toast-warning')).toContainText(/hidden by the current filter/i, { timeout: 10_000 })
		await expect(targetTile(page)).toHaveCount(0)
	})

	test('is not offered on an archived card, which is not on the board at all', async ({ page }) => {
		await ncLogin(page)
		await openCardMenu(page, cardUrl(state.archivedId))
		// The ⋯ menu is open (the archived checkbox proves it) but this action is
		// absent — better than offering a jump that could only fail.
		await expect(page.locator('[data-test="card-archived-toggle"]')).toBeAttached({ timeout: 8000 })
		await expect(findAction(page)).toHaveCount(0)
	})
})
