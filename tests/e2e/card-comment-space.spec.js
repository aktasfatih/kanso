// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, BASE, api, ncLogin } from './helpers.js'

/**
 * The comments pane claims the height the modal actually affords (#10657).
 *
 * NcModal's size rules give the card container a `max-height` and no `height`,
 * so it shrink-wrapped, the `flex: 1; min-height: 0` chain inside CardDetail had
 * no definite ancestor height to distribute, and three hard-coded `64vh` caps
 * became the real constraint on both panes. The result was a small comment
 * scroller that got relatively smaller the taller your screen was.
 *
 * ⚠️ VIEWPORT. The `desktop` project in playwright.config.js deliberately sets
 * no `viewport`, so it runs at Chromium's 1280×720 default — and at 720px this
 * change does not enlarge the thread at all (it *costs* ~37px there, because the
 * card used to overflow the modal container and rely on a second, outer
 * scrollbar to reach the composer). Every test here therefore pins 1280×1000
 * explicitly. Without that line these assertions are worse than vacuous: they
 * would fail on the fixed code.
 *
 * Thresholds are measured live on the dev stack, pre- and post-fix, and set
 * midway between the two:
 *   .card-modal__thread-scroll   357px → 421px   (side layout)   → 390
 *   .modal-container             820px → 884px                   → 852
 *   .card-modal__body            640px → 704px   (bottom layout) → 672
 */
test.describe('Card view: the discussion pane claims the modal height (#10657)', () => {
	const BOARD_TITLE = 'Comment Space E2E Board ' + Date.now()
	const state = { boardId: 0, cardId: 0, cardUrl: '', sparseUrl: '' }

	// Enough comments that the thread genuinely overflows any plausible pane
	// height — otherwise "the scroller is taller" would measure a list, not a pane.
	const COMMENT_COUNT = 14

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api.post('/cards', {
			stackId: stack.id,
			title: 'Long discussion card',
			description: 'A card whose discussion is the point.',
		})
		state.cardId = card.id
		for (let i = 1; i <= COMMENT_COUNT; i++) {
			await api.post(`/cards/${card.id}/comments`, { body: `Vertical space comment number ${i}` })
		}
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`

		// A near-empty card: the case that pins the modal-container height rule.
		// A busy card is tall enough to press the container against NcModal's
		// `max-height` on its own, so it cannot tell "the shell states a height"
		// apart from "this particular card happened to fill it".
		const sparse = await api.post('/cards', { stackId: stack.id, title: 'Sparse card', description: 'One line.' })
		await api.post(`/cards/${sparse.id}/comments`, { body: 'The only comment on this card' })
		state.sparseUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${sparse.id}`
	})

	// The discussion placement is a per-USER server setting, so it would follow
	// the worker into every other card-detail spec. Always hand it back.
	test.afterEach(async () => {
		await api.put('/settings', { cardDiscussionPosition: 'side' }).catch(() => {})
	})

	test.afterAll(async () => {
		await api.put('/settings', { cardDiscussionPosition: 'side' }).catch(() => {})
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	/**
	 * Open the card with a chosen discussion placement at a TALL viewport.
	 *
	 * @param {import('@playwright/test').Page} page the page
	 * @param {string} position 'side' | 'bottom' — the server-side preference
	 * @param {string} url which card to open; defaults to the busy one
	 */
	async function openTall(page, position, url = state.cardUrl) {
		await api.put('/settings', { cardDiscussionPosition: position })
		// See the VIEWPORT note above — the `desktop` project's default is 1280×720.
		await page.setViewportSize({ width: 1280, height: 1000 })
		await ncLogin(page)
		await page.goto(url)
		await page.waitForSelector('.card-modal__thread-scroll', { timeout: 20_000 })
		await expect(page.locator('.card-modal__comment-body').first()).toBeVisible({ timeout: 15_000 })
	}

	/**
	 * The worst overhang of the Post button past any clipping ancestor. Positive
	 * means part of it is cut off by an overflow ancestor (or the viewport) and no
	 * amount of scrolling inside the pane can bring it back.
	 *
	 * @param {import('@playwright/test').Locator} composer the composer element
	 * @returns {Promise<number>} pixels of overhang, ≤ 0 when fully visible
	 */
	async function postButtonOverhang(composer) {
		return composer.evaluate((el) => {
			const post = el.querySelector('.card-modal__composer-actions button')
			const bottom = post.getBoundingClientRect().bottom
			let worst = bottom - window.innerHeight
			for (let n = el.parentElement; n && n !== document.documentElement; n = n.parentElement) {
				if (getComputedStyle(n).overflowY === 'visible') continue
				worst = Math.max(worst, bottom - n.getBoundingClientRect().bottom)
			}
			return Math.round(worst)
		})
	}

	test('side layout: the thread scroller takes the modal slack, not a fixed 64vh', async ({ page }) => {
		await openTall(page, 'side')

		// 1) The modal container really did claim the height NcModal affords it,
		//    instead of shrink-wrapping around a 64vh-capped body.
		const container = await page.locator('.modal-container').boundingBox()
		expect(container, 'no modal container').not.toBeNull()
		expect(
			Math.round(container.height),
			'the modal container still shrink-wraps — CardModal.vue grants it no height',
		).toBeGreaterThanOrEqual(852)

		// 2) …and that height reached the thread scroller, which is the thing the
		//    user was complaining about.
		const thread = page.locator('.card-modal__thread-scroll')
		const threadBox = await thread.boundingBox()
		expect(threadBox, 'no thread scroller').not.toBeNull()
		expect(
			Math.round(threadBox.height),
			'the comment scroller is still capped well below what the modal affords',
		).toBeGreaterThanOrEqual(390)

		// It is a real scroller with more to show — otherwise the number above
		// would be measuring a short list rather than the pane it lives in.
		const overflows = await thread.evaluate((el) => el.scrollHeight - el.clientHeight)
		expect(overflows, 'seed more comments: the thread does not overflow').toBeGreaterThan(50)

		// 3) The growth must not have been bought by pushing the pane past the
		//    modal's clip: the discussion ends exactly where the container does.
		const discussion = await page.locator('.card-modal__discussion').boundingBox()
		expect(
			Math.round((discussion.y + discussion.height) - (container.y + container.height)),
			'the discussion pane hangs past the bottom of the modal container',
		).toBeLessThanOrEqual(1)

		// 4) The composer and its Post button stay fully visible and hittable — the
		//    failure mode a too-aggressive height reintroduces.
		const composer = page.locator('.card-modal__composer')
		await expect(composer).toBeVisible()
		expect(await postButtonOverhang(composer)).toBeLessThanOrEqual(0)
		const hitsPost = await composer.evaluate((el) => {
			const post = el.querySelector('.card-modal__composer-actions button')
			const r = post.getBoundingClientRect()
			const hit = document.elementFromPoint(Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2))
			return !!hit && (post === hit || post.contains(hit) || hit.contains(post))
		})
		expect(hitsPost, 'the Post button is not what you click where it is drawn').toBe(true)
	})

	// The deliberate cost of the fix, asserted rather than left to chance: a card
	// with almost nothing on it now opens at the same height as a busy one,
	// instead of shrink-wrapping to its content. This is also the only case that
	// can tell the shell's stated height apart from a card that simply happened to
	// be tall enough to reach NcModal's `max-height` unaided.
	test('a near-empty card gets the same full-height comment pane', async ({ page }) => {
		await openTall(page, 'side', state.sparseUrl)

		const container = await page.locator('.modal-container').boundingBox()
		expect(
			Math.round(container.height),
			'a sparse card still shrink-wraps — the modal height depends on card content',
		).toBeGreaterThanOrEqual(852)

		const thread = await page.locator('.card-modal__thread-scroll').boundingBox()
		expect(Math.round(thread.height)).toBeGreaterThanOrEqual(390)

		// And nothing hangs out of the bottom of the modal on a short card either.
		const composer = page.locator('.card-modal__composer')
		await expect(composer).toBeVisible()
		expect(await postButtonOverhang(composer)).toBeLessThanOrEqual(0)
	})

	test('bottom layout: the card body claims the same height', async ({ page }) => {
		await openTall(page, 'bottom')
		await expect(page.locator('.card-modal')).toHaveClass(/card-modal--discussion-bottom/)

		// Here the BODY is the single scroller, so it is the body that was capped.
		const body = page.locator('.card-modal__body')
		const bodyBox = await body.boundingBox()
		expect(bodyBox, 'no card body').not.toBeNull()
		expect(
			Math.round(bodyBox.height),
			'the bottom layout body is still capped below what the modal affords',
		).toBeGreaterThanOrEqual(672)

		// Scroll to the very end — the worst case for the composer pinned there.
		await body.evaluate((el) => { el.scrollTop = el.scrollHeight })
		const composer = page.locator('.card-modal__composer')
		await expect(composer).toBeVisible()
		expect(await postButtonOverhang(composer)).toBeLessThanOrEqual(0)
	})
})
