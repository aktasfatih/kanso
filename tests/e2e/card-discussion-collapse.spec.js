// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, BASE, api, ncLogin } from './helpers.js'

// These mirror CardDetail.vue (DISCUSSION_COLLAPSED_KEY / DISCUSSION_WIDTH_KEY).
const COLLAPSED_KEY = 'kanso.cardDiscussionCollapsed'
const WIDTH_KEY = 'kanso.cardDiscussionWidth'

const COMMENT_BODY = 'Collapse regression comment'

test.describe('Card view: collapsible discussion pane (#9854)', () => {
	const BOARD_TITLE = 'Discussion Collapse E2E Board ' + Date.now()
	const state = { boardId: 0, cardId: 0, commentId: 0, cardUrl: '', pageUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api.post('/cards', {
			stackId: stack.id,
			title: 'Collapse Test Card',
			description: 'Card used to verify collapsing the discussion pane.',
		})
		state.cardId = card.id
		// One comment: it drives the collapsed toggle's count badge, and it is the
		// content the sub-680px regression test asserts still renders.
		const comment = await api.post(`/cards/${card.id}/comments`, { body: COMMENT_BODY })
		state.commentId = comment.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
		state.pageUrl = `${BASE}/index.php/apps/kanso#/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	/**
	 * Open a card view from a known localStorage baseline so one test's persisted
	 * collapse/width can never leak into the next.
	 *
	 * @param {import('@playwright/test').Page} page the page
	 * @param {object} opts options
	 * @param {boolean} opts.collapsed seed the persisted collapsed flag
	 * @param {number|null} opts.width seed a persisted discussion width (px)
	 * @param {string} opts.url the card URL to open (modal route by default)
	 */
	async function openCard(page, { collapsed = false, width = null, url = null } = {}) {
		await ncLogin(page)
		// Seed ONCE per test: an init script re-runs on every document load, so an
		// unguarded one would re-seed on reload and quietly destroy exactly the
		// persistence this spec is here to prove. sessionStorage survives reloads
		// within the tab, so it is the right sentinel.
		await page.addInitScript(([ck, wk, isCollapsed, px]) => {
			try {
				if (sessionStorage.getItem('kanso-e2e-collapse-seeded')) return
				sessionStorage.setItem('kanso-e2e-collapse-seeded', '1')
				if (isCollapsed) localStorage.setItem(ck, '1')
				else localStorage.removeItem(ck)
				if (px) localStorage.setItem(wk, String(px))
				else localStorage.removeItem(wk)
			} catch (e) { /* localStorage unavailable */ }
		}, [COLLAPSED_KEY, WIDTH_KEY, collapsed, width])
		await page.goto(url ?? state.cardUrl)
		await page.waitForSelector('.card-modal__body', { timeout: 15_000 })
	}

	test('collapsing gives the main pane the full body width; expanding restores the persisted width', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await openCard(page, { width: 520 })

		const discussion = page.locator('.card-modal__discussion')
		const toggle = page.locator('.card-modal__discussion-toggle')
		await expect(discussion).toBeVisible()
		await expect(toggle).toHaveAttribute('aria-expanded', 'true')
		// a11y: the toggle points at the pane it controls.
		const controls = await toggle.getAttribute('aria-controls')
		expect(controls).toBe(`card-modal-discussion-${state.cardId}`)
		await expect(page.locator(`#${controls}`)).toBeVisible()

		const widened = await discussion.boundingBox()
		expect(Math.abs(widened.width - 520)).toBeLessThanOrEqual(6)
		const contentBefore = await page.locator('.card-modal__content').boundingBox()

		// Collapse.
		await toggle.click()
		await expect(discussion).toBeHidden()
		await expect(page.locator('.card-modal__resizer')).toBeHidden()
		await expect(toggle).toHaveAttribute('aria-expanded', 'false')

		// The main pane now spans the whole body — no residual rail or gutter.
		const body = await page.locator('.card-modal__body').boundingBox()
		const contentAfter = await page.locator('.card-modal__content').boundingBox()
		expect(contentAfter.width).toBeGreaterThan(contentBefore.width + 400)
		expect(Math.abs(contentAfter.width - body.width)).toBeLessThanOrEqual(2)

		// Persisted.
		expect(await page.evaluate((k) => localStorage.getItem(k), COLLAPSED_KEY)).toBe('1')

		// Expanding brings the pane back at its previously persisted width (#3661).
		await toggle.click()
		await expect(discussion).toBeVisible()
		const restored = await discussion.boundingBox()
		expect(Math.abs(restored.width - widened.width)).toBeLessThanOrEqual(2)
		expect(await page.evaluate((k) => localStorage.getItem(k), COLLAPSED_KEY)).toBeNull()
	})

	test('the collapsed state survives closing and reopening the card', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await openCard(page)

		await page.locator('.card-modal__discussion-toggle').click()
		await expect(page.locator('.card-modal__discussion')).toBeHidden()

		// Leave the card (back to the board) and reopen it, then reload the app.
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view, .kanso-board, .card-tile', { timeout: 15_000 }).catch(() => {})
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal__body', { timeout: 15_000 })
		await expect(page.locator('.card-modal__discussion')).toBeHidden()

		await page.reload()
		await page.waitForSelector('.card-modal__body', { timeout: 15_000 })
		await expect(page.locator('.card-modal__discussion')).toBeHidden()
		await expect(page.locator('.card-modal__discussion-toggle')).toHaveAttribute('aria-expanded', 'false')
	})

	test('collapsed, the toggle carries the comment count', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await openCard(page, { collapsed: true })

		const toggle = page.locator('.card-modal__discussion-toggle')
		await expect(page.locator('.card-modal__discussion')).toBeHidden()
		await expect(toggle.locator('.card-modal__discussion-toggle-count')).toHaveText('1')

		// Expanded again, the count lives in the pane's own tab, not on the toggle.
		await toggle.click()
		await expect(toggle.locator('.card-modal__discussion-toggle-count')).toHaveCount(0)
		await expect(page.locator('.card-modal__discussion-count')).toHaveText('1')
	})

	test('the toggle is keyboard operable', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await openCard(page)

		const toggle = page.locator('.card-modal__discussion-toggle')
		await toggle.focus()
		await expect(toggle).toBeFocused()
		await page.keyboard.press('Enter')
		await expect(page.locator('.card-modal__discussion')).toBeHidden()
		await page.keyboard.press('Enter')
		await expect(page.locator('.card-modal__discussion')).toBeVisible()
		// The resizer stays an operable separator whenever the pane is expanded.
		await expect(page.locator('.card-modal__resizer')).toHaveAttribute('role', 'separator')
		await expect(page.locator('.card-modal__resizer')).toBeVisible()
	})

	test('collapsing works in the full-page card view too', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await openCard(page, { url: state.pageUrl })
		await expect(page.locator('.card-modal--mode-page')).toHaveCount(1)

		const discussion = page.locator('.card-modal__discussion')
		await expect(discussion).toBeVisible()
		await page.locator('.card-modal__discussion-toggle').click()
		await expect(discussion).toBeHidden()

		const body = await page.locator('.card-modal__body').boundingBox()
		const content = await page.locator('.card-modal__content').boundingBox()
		expect(Math.abs(content.width - body.width)).toBeLessThanOrEqual(2)
	})

	// THE regression this feature can most easily break: the collapsed flag is
	// persisted globally, so a desktop collapse must NOT follow the user into the
	// stacked/tabbed layout below 680px — there it would blank the Discussion tab.
	test('below 680px a persisted collapse never blanks the Discussion tab', async ({ page }) => {
		await page.setViewportSize({ width: 500, height: 800 })
		await openCard(page, { collapsed: true })

		// The desktop toggle has no job in the tabbed layout.
		await expect(page.locator('.card-modal__discussion-toggle')).toBeHidden()

		// Switch to the Discussion tab — it must render the pane and its content.
		await page.locator('.card-modal__tabbar .card-modal__tab', { hasText: 'Discussion' }).click()
		const discussion = page.locator('.card-modal__discussion')
		await expect(discussion).toBeVisible()
		const box = await discussion.boundingBox()
		expect(box.width).toBeGreaterThan(0)
		expect(box.height).toBeGreaterThan(0)
		await expect(discussion.getByText(COMMENT_BODY)).toBeVisible()
	})

	// The same trap, reached the realistic way: collapse on a wide window, then
	// narrow it. The media-query switch must hand control back to the tab bar.
	test('collapsing on desktop then narrowing keeps the Discussion tab usable', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await openCard(page)

		await page.locator('.card-modal__discussion-toggle').click()
		await expect(page.locator('.card-modal__discussion')).toBeHidden()

		await page.setViewportSize({ width: 500, height: 800 })
		await expect(page.locator('.card-modal__discussion-toggle')).toBeHidden()
		await page.locator('.card-modal__tabbar .card-modal__tab', { hasText: 'Discussion' }).click()
		const discussion = page.locator('.card-modal__discussion')
		await expect(discussion).toBeVisible()
		await expect(discussion.getByText(COMMENT_BODY)).toBeVisible()
	})

	// Following a mention/notification must never land on a card showing nothing:
	// a deep link to a comment reveals the pane even when it is collapsed.
	test('a deep link to a comment reveals a collapsed pane', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await openCard(page, { collapsed: true, url: `${state.cardUrl}?comment=${state.commentId}` })

		const discussion = page.locator('.card-modal__discussion')
		await expect(discussion).toBeVisible()
		await expect(discussion.getByText(COMMENT_BODY)).toBeVisible()
		// One-off reveal — the saved preference is left alone.
		expect(await page.evaluate((k) => localStorage.getItem(k), COLLAPSED_KEY)).toBe('1')
	})
})
