// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, BASE, api, ncLogin } from './helpers.js'

// Mirrors CardDetail.vue — the side layout's persisted collapse flag, which the
// bottom layout must render inert.
const COLLAPSED_KEY = 'kanso.cardDiscussionCollapsed'

const COMMENT_BODY = 'Discussion placement regression comment'

/**
 * Card view: where the Discussion/Activity panel sits (#10408).
 *
 * A per-user, SERVER-SIDE setting with two values — 'side' (the default, today's
 * layout) and 'bottom' (the panel becomes a continuation of the card you scroll
 * down to). Both regimes are covered here on purpose: the setting doubles the
 * card-detail layout matrix, so the default must not be the only thing tested.
 */
test.describe('Card view: discussion panel placement (#10408)', () => {
	const BOARD_TITLE = 'Discussion Position E2E Board ' + Date.now()
	const state = { boardId: 0, cardId: 0, cardUrl: '' }

	/** Put the server-side preference back where a fresh user finds it. */
	async function setPosition(position) {
		await api.put('/settings', { cardDiscussionPosition: position })
	}

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api.post('/cards', {
			stackId: stack.id,
			title: 'Placement Test Card',
			// A long description so the bottom layout genuinely has to be scrolled
			// to — otherwise "it's below the card" proves nothing about scrolling.
			description: Array.from({ length: 40 }, (_, i) => `Body line ${i + 1} of the card description.`).join('\n\n'),
		})
		state.cardId = card.id
		await api.post(`/cards/${card.id}/comments`, { body: COMMENT_BODY })
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	// The preference is per USER, not per board, so it outlives this describe and
	// would follow the worker into every other card-detail spec. Always hand the
	// account back in its default state.
	test.afterEach(async () => {
		await setPosition('side').catch(() => {})
	})

	test.afterAll(async () => {
		await setPosition('side').catch(() => {})
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	/**
	 * Open the card with a known baseline: a chosen server-side placement and a
	 * chosen localStorage collapse flag, so neither leaks between tests.
	 *
	 * @param {import('@playwright/test').Page} page the page
	 * @param {object} opts options
	 * @param {string} opts.position 'side' | 'bottom' — the server-side preference
	 * @param {boolean} opts.collapsed seed the side layout's persisted collapse flag
	 */
	async function openCard(page, { position = 'side', collapsed = false } = {}) {
		await setPosition(position)
		await ncLogin(page)
		await page.addInitScript(([ck, isCollapsed]) => {
			try {
				if (sessionStorage.getItem('kanso-e2e-position-seeded')) return
				sessionStorage.setItem('kanso-e2e-position-seeded', '1')
				if (isCollapsed) localStorage.setItem(ck, '1')
				else localStorage.removeItem(ck)
			} catch (e) { /* localStorage unavailable */ }
		}, [COLLAPSED_KEY, collapsed])
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal__body', { timeout: 20_000 })
		// The placement comes from GET /api/settings, which resolves after mount —
		// wait for the class the setting drives rather than racing it.
		const root = page.locator('.card-modal')
		if (position === 'bottom') {
			await expect(root).toHaveClass(/card-modal--discussion-bottom/, { timeout: 10_000 })
		} else {
			await expect(root).not.toHaveClass(/card-modal--discussion-bottom/, { timeout: 10_000 })
		}
	}

	// ── Regime 1: 'side' — the default, unchanged ────────────────────────────

	test('default is side: the panel sits beside the card and the button still collapses it', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await openCard(page, { position: 'side' })

		const content = page.locator('.card-modal__content')
		const discussion = page.locator('.card-modal__discussion')
		await expect(discussion).toBeVisible()

		// Side by side: the panel starts to the RIGHT of the main pane, on the same row.
		const c = await content.boundingBox()
		const d = await discussion.boundingBox()
		expect(d.x).toBeGreaterThanOrEqual(c.x + c.width - 2)
		expect(Math.abs(d.y - c.y)).toBeLessThanOrEqual(2)

		// The header button keeps its collapse job, and says so.
		const toggle = page.locator('.card-modal__discussion-toggle')
		await expect(toggle).toHaveAttribute('aria-expanded', 'true')
		await toggle.click()
		await expect(discussion).toBeHidden()
		await expect(toggle).toHaveAttribute('aria-expanded', 'false')
		await toggle.click()
		await expect(discussion).toBeVisible()
	})

	// ── Regime 2: 'bottom' — the new layout ──────────────────────────────────

	test('bottom: the panel becomes a full-width continuation below the card, still tabbed', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await openCard(page, { position: 'bottom' })

		const content = page.locator('.card-modal__content')
		const discussion = page.locator('.card-modal__discussion')
		const body = page.locator('.card-modal__body')
		await expect(discussion).toBeVisible()

		// Stacked, not split: the panel starts BELOW the main pane and shares its
		// left edge and width — it is part of the card now, not a rail beside it.
		const c = await content.boundingBox()
		const d = await discussion.boundingBox()
		const b = await body.boundingBox()
		expect(d.y).toBeGreaterThanOrEqual(c.y + c.height - 2)
		expect(Math.abs(d.x - c.x)).toBeLessThanOrEqual(2)
		expect(Math.abs(d.width - b.width)).toBeLessThanOrEqual(2)

		// The split handle belongs to the side layout only.
		await expect(page.locator('.card-modal__resizer')).toBeHidden()

		// You have to scroll to it: the panel starts below the fold of the body's
		// own scroller, which is the point of the whole layout.
		expect(await body.evaluate((el) => el.scrollTop)).toBe(0)
		expect(d.y).toBeGreaterThan(b.y + b.height - 1)

		// Still tabbed — Discussion and Activity, exactly as beside the card.
		const tabs = page.locator('.card-modal__discussion-tab')
		await expect(tabs).toHaveCount(2)
		await expect(discussion.getByText(COMMENT_BODY)).toBeVisible()
		await tabs.filter({ hasText: 'Activity' }).click()
		await expect(page.locator('.card-modal__activity')).toBeVisible()
		await expect(discussion.getByText(COMMENT_BODY)).toHaveCount(0)
		await tabs.filter({ hasText: 'Discussion' }).click()
		await expect(discussion.getByText(COMMENT_BODY)).toBeVisible()
	})

	test('bottom: the header button jumps down to the panel instead of collapsing it', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await openCard(page, { position: 'bottom' })

		const body = page.locator('.card-modal__body')
		const discussion = page.locator('.card-modal__discussion')
		const toggle = page.locator('.card-modal__discussion-toggle')

		// The button's job flipped with the setting: no collapse semantics left.
		await expect(toggle).not.toHaveAttribute('aria-expanded', /.*/)
		// The count rides the button, since the panel is off-screen until scrolled to.
		await expect(toggle.locator('.card-modal__discussion-toggle-count')).toHaveText('1')

		expect(await body.evaluate((el) => el.scrollTop)).toBe(0)
		await toggle.click()

		// It scrolled the card body down to the panel — and never hid it. The
		// scroll is smooth/animated, so poll the settled position rather than
		// measuring once the instant scrollTop first moves.
		await expect.poll(
			async () => discussion.evaluate((pane) => {
				const scroller = pane.closest('.card-modal__body')
				// How far the panel's top sits below the scroller's visible bottom;
				// <= 0 once it has been scrolled into view.
				return Math.round(pane.getBoundingClientRect().top - scroller.getBoundingClientRect().bottom)
			}),
			{ timeout: 10_000 },
		).toBeLessThanOrEqual(0)
		expect(await body.evaluate((el) => el.scrollTop)).toBeGreaterThan(0)
		await expect(discussion).toBeVisible()

		// Focus follows the scroll: in this layout the button is a skip link, and
		// one that only moves the viewport is unusable by keyboard/screen reader.
		await expect(discussion).toBeFocused()

		// Clicking again keeps it visible — this button can never hide the panel here.
		await toggle.click()
		await expect(discussion).toBeVisible()
	})

	// The bottom layout gives the card body its own scroller inside the modal. If
	// that scroller is taller than the modal actually affords, its bottom edge —
	// where the sticky composer lives — is clipped away and the card becomes
	// read-only: you can reach the thread but never post to it.
	test('bottom: the comment composer stays reachable at the end of the scroll', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await openCard(page, { position: 'bottom' })

		const body = page.locator('.card-modal__body')
		const composer = page.locator('.card-modal__composer')

		// Scroll to the very end of the card, the worst case for the pinned composer.
		await body.evaluate((el) => { el.scrollTop = el.scrollHeight })
		await expect(composer).toBeVisible()

		// `toBeVisible` is not enough: Playwright calls an element visible while an
		// ancestor's overflow clips it off-screen. Walk every scrolling/clipping
		// ancestor (the NcModal container is the one that bites — it does not
		// scroll, so nothing can bring the Post button back) and measure the worst
		// overhang. Deliberately generic: no NcModal class name is hardcoded.
		const worstOverhang = await composer.evaluate((el) => {
			const post = el.querySelector('.card-modal__composer-actions button')
			const bottom = post.getBoundingClientRect().bottom
			let worst = bottom - window.innerHeight
			for (let n = el.parentElement; n && n !== document.documentElement; n = n.parentElement) {
				if (getComputedStyle(n).overflowY === 'visible') continue
				worst = Math.max(worst, bottom - n.getBoundingClientRect().bottom)
			}
			return Math.round(worst)
		})
		expect(worstOverhang).toBeLessThanOrEqual(0)

		// The bluntest form of the same question: is the Post button the thing you
		// actually hit when you click where it is drawn?
		const hitsPost = await composer.evaluate((el) => {
			const post = el.querySelector('.card-modal__composer-actions button')
			const r = post.getBoundingClientRect()
			const hit = document.elementFromPoint(Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2))
			return !!hit && (post === hit || post.contains(hit) || hit.contains(post))
		})
		expect(hitsPost).toBe(true)

		// And it actually works: a comment posted from the bottom layout lands. The
		// composer is a Tiptap editor, so drive the ProseMirror node (the pattern
		// comments.spec.js uses).
		const posted = 'Posted from the bottom layout ' + Date.now()
		const prose = composer.locator('.kanso-md-editor .ProseMirror').first()
		await expect(prose).toBeVisible({ timeout: 10_000 })
		await prose.click()
		await page.keyboard.type(posted)
		const post = composer.locator('.card-modal__composer-actions button').first()
		await expect(post).toBeEnabled()
		await post.click()
		// Scope to the rendered comment: the composer still holds the same text
		// until the mutation settles, so an unscoped text match hits both.
		await expect(
			page.locator('.card-modal__comment-body').filter({ hasText: posted }),
		).toBeVisible({ timeout: 10_000 })
	})

	// ── THE trap: a collapse persisted in side mode must not survive the switch ──

	test('a panel collapsed in side mode is never invisible after switching to bottom', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		// The realistic path: collapse it beside the card, THEN switch the setting.
		await openCard(page, { position: 'side' })
		await page.locator('.card-modal__discussion-toggle').click()
		await expect(page.locator('.card-modal__discussion')).toBeHidden()
		expect(await page.evaluate((k) => localStorage.getItem(k), COLLAPSED_KEY)).toBe('1')

		// Flip the preference and reopen the card — the stale collapse flag is
		// still in localStorage and must be inert in the bottom layout. A reload
		// (not goto: the card URL is a hash route, so navigating to the same one
		// is a same-document no-op and the app would never re-read the setting).
		await setPosition('bottom')
		await page.reload()
		await page.waitForSelector('.card-modal__body', { timeout: 20_000 })
		const root = page.locator('.card-modal')
		await expect(root).toHaveClass(/card-modal--discussion-bottom/, { timeout: 10_000 })
		// The flag really did survive — otherwise this test proves nothing.
		await expect(root).toHaveClass(/card-modal--discussion-collapsed/)
		expect(await page.evaluate((k) => localStorage.getItem(k), COLLAPSED_KEY)).toBe('1')

		// …and the panel is there anyway, with its content, reachable by the button.
		const discussion = page.locator('.card-modal__discussion')
		await expect(discussion).toBeVisible()
		const d = await discussion.boundingBox()
		expect(d.width).toBeGreaterThan(0)
		expect(d.height).toBeGreaterThan(0)
		await page.locator('.card-modal__discussion-toggle').click()
		await expect(discussion.getByText(COMMENT_BODY)).toBeVisible()

		// And switching back restores the side layout's collapsed state — the
		// preference changed the regime, it did not destroy the other one's state.
		await setPosition('side')
		await page.reload()
		await page.waitForSelector('.card-modal__body', { timeout: 20_000 })
		await expect(root).not.toHaveClass(/card-modal--discussion-bottom/, { timeout: 10_000 })
		await expect(page.locator('.card-modal__discussion')).toBeHidden()
	})

	// ── The narrow regime must be untouched by either setting ────────────────

	test('below 680px the tab-swap layout is unchanged, whichever placement is set', async ({ page }) => {
		await page.setViewportSize({ width: 500, height: 800 })
		await openCard(page, { position: 'bottom' })

		// The header button has no job in the tabbed layout, in either placement.
		await expect(page.locator('.card-modal__discussion-toggle')).toBeHidden()
		// The narrow layout is a TAB SWAP, not a stack: the card pane is hidden
		// while the Discussion tab is active, and vice versa.
		const discussion = page.locator('.card-modal__discussion')
		const content = page.locator('.card-modal__content')
		await expect(content).toBeVisible()
		await expect(discussion).toBeHidden()

		await page.locator('.card-modal__tabbar .card-modal__tab', { hasText: 'Discussion' }).click()
		await expect(discussion).toBeVisible()
		await expect(content).toBeHidden()
		await expect(discussion.getByText(COMMENT_BODY)).toBeVisible()

		await page.locator('.card-modal__tabbar .card-modal__tab', { hasText: 'Card' }).click()
		await expect(content).toBeVisible()
		await expect(discussion).toBeHidden()
	})

	// ── The reason it is server-side at all ──────────────────────────────────

	test('the setting round-trips through the server and survives a different browser session', async ({ page, browser, user }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await setPosition('side')
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)

		// Flip it through the real settings UI, not the API. NcCheckboxRadioSwitch
		// spreads fallthrough attrs onto its <input>, which is visually hidden
		// behind its label — assert on the input, click the label (the pattern
		// card-features.spec.js already uses).
		await page.locator('[data-test="open-settings"]').click()
		const toggle = page.locator('input[data-test="setting-discussion-bottom"]')
		await expect(toggle).not.toBeChecked()
		await page.getByText('Show the discussion below the card', { exact: true }).click()
		await expect(toggle).toBeChecked()

		// It reached the server (not localStorage) — that is the whole point.
		await expect.poll(
			async () => (await api.get('/settings')).cardDiscussionPosition,
			{ timeout: 10_000 },
		).toBe('bottom')

		// A brand-new browser context: empty localStorage, fresh login, same user.
		const ctx = await browser.newContext()
		try {
			const fresh = await ctx.newPage()
			await fresh.setViewportSize({ width: 1280, height: 800 })
			await ncLogin(fresh, { user: user.user, pass: user.pass })
			await fresh.goto(state.cardUrl)
			await fresh.waitForSelector('.card-modal__body', { timeout: 20_000 })
			await expect(fresh.locator('.card-modal')).toHaveClass(/card-modal--discussion-bottom/, { timeout: 10_000 })
			// Nothing about this placement was stored in the browser.
			expect(await fresh.evaluate(() => localStorage.getItem('kanso.cardDiscussionPosition'))).toBeNull()
			// …and it really is the bottom layout there, not just the class.
			const c = await fresh.locator('.card-modal__content').boundingBox()
			const d = await fresh.locator('.card-modal__discussion').boundingBox()
			expect(d.y).toBeGreaterThanOrEqual(c.y + c.height - 2)
		} finally {
			await ctx.close()
		}
	})
})
