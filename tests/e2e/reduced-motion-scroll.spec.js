// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * JS-driven scrolls honour "reduce motion" (#10475).
 *
 * App.vue carries an unscoped `@media (prefers-reduced-motion: reduce)` block
 * that sets `scroll-behavior: auto !important` — the app's motion policy for
 * everything CSS drives. It cannot reach a JS-initiated scroll: per CSSOM-View,
 * an explicit `behavior` option on `scrollIntoView()` / `scrollTo()` OVERRIDES
 * the CSS property, `!important` included. Three call sites hardcoded
 * `behavior: 'smooth'` and so animated regardless of the preference; they now
 * ask `scrollBehavior()` (src/utils/motion.js) at scroll time.
 *
 * IMPORTANT FOR FUTURE READERS: the assertion is the `behavior` ARGUMENT, not
 * the absence of an animation. Observing "did it animate?" means sampling
 * scrollTop over time — inherently timing-dependent, and indistinguishable from
 * a fast smooth scroll on a short distance. The argument is the entire
 * mechanism, so a recorder around the two scroll methods is both exact and
 * stable. Do not "improve" this into a scroll-position race.
 */

import { test, expect, api, ncLogin, BASE } from './helpers.js'

/**
 * Patch both scroll methods to record the `behavior` each call asked for.
 * Installed as an init script so it is in place before the app boots.
 */
function recordScrolls() {
	window.__kansoScrolls = []
	const wrap = (orig, kind) => function (...args) {
		try {
			window.__kansoScrolls.push({ kind, behavior: args[0] && args[0].behavior })
		} catch (e) { /* never let the recorder break the app */ }
		return orig.apply(this, args)
	}
	const into = Element.prototype.scrollIntoView
	if (into) Element.prototype.scrollIntoView = wrap(into, 'scrollIntoView')
	const to = Element.prototype.scrollTo
	if (to) Element.prototype.scrollTo = wrap(to, 'scrollTo')
}

/**
 * The behaviors explicitly requested so far. Calls that pass no `behavior` are
 * dropped: the app makes plenty of those (`{ block: 'nearest' }` and friends)
 * and they already follow the CSS `scroll-behavior`, so they are not what this
 * spec is about.
 *
 * @param {import('@playwright/test').Page} page the page
 * @return {Promise<string[]>} requested behaviors, in call order
 */
async function requestedBehaviors(page) {
	return await page.evaluate(() => (window.__kansoScrolls || [])
		.map((c) => c.behavior)
		.filter((b) => b !== undefined))
}

/** Drop everything recorded so far, so a test only sees its own action. */
async function resetScrolls(page) {
	await page.evaluate(() => { window.__kansoScrolls = [] })
}

test.describe('JS scrolls honour prefers-reduced-motion (#10475)', () => {
	const state = { boardId: 0, cardId: 0, commentIds: [], cardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Reduced Motion E2E ' + Date.now() })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		const card = await api.post('/cards', {
			stackId: stack.id,
			title: 'Reduced motion card',
			// Long enough that the bottom-layout discussion really is below the fold.
			description: Array.from({ length: 40 }, (_, i) => `Body line ${i + 1}.`).join('\n\n'),
		})
		state.cardId = card.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
		// Enough comments that the deep-link target is well down the thread.
		for (let i = 1; i <= 8; i++) {
			const c = await api.post(`/cards/${card.id}/comments`, { body: `Comment number ${i} body text` })
			state.commentIds.push(c.id)
		}
		// A dated card so the timeline has something to render around today.
		const dated = await api.post('/cards', { stackId: stack.id, title: 'Dated task' })
		const soon = new Date(Date.now() + 3 * 86400_000).toISOString()
		await api.patch(`/cards/${dated.id}`, { duedate: soon })
	})

	// The discussion placement is a per-USER server setting, so it would follow
	// this worker into every other card-detail spec if left flipped.
	test.afterEach(async () => {
		await api.put('/settings', { cardDiscussionPosition: 'side' }).catch(() => {})
	})

	test.afterAll(async () => {
		await api.put('/settings', { cardDiscussionPosition: 'side' }).catch(() => {})
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	/** Open the card in the 'bottom' layout, where the header button is a skip link. */
	async function openBottomCard(page) {
		await api.put('/settings', { cardDiscussionPosition: 'bottom' })
		await ncLogin(page)
		await page.addInitScript(recordScrolls)
		await page.setViewportSize({ width: 1280, height: 800 })
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal__body', { timeout: 20_000 })
		await expect(page.locator('.card-modal')).toHaveClass(/card-modal--discussion-bottom/, { timeout: 10_000 })
	}

	/** Open the board's timeline view, where "Jump to today" scrolls the track. */
	async function openTimeline(page) {
		await ncLogin(page)
		await page.addInitScript(recordScrolls)
		await page.setViewportSize({ width: 1280, height: 800 })
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByText('Timeline', { exact: true }).click()
		await expect(page.locator('.timeline__pane-head')).toBeVisible({ timeout: 10_000 })
	}

	/** Load the card by deep link to a comment far down the thread. */
	async function openCommentDeepLink(page) {
		await ncLogin(page)
		await page.addInitScript(recordScrolls)
		await page.setViewportSize({ width: 1280, height: 800 })
		const targetId = state.commentIds[5]
		await page.goto(`${BASE}/index.php/apps/kanso#/card/${state.cardId}?comment=${targetId}`)
		await page.waitForSelector('.card-modal', { timeout: 15_000 })
		await expect(page.locator(`#comment-${targetId}`)).toBeVisible({ timeout: 15_000 })
	}

	// ── Reduced motion: every one of the three must be instant ────────────────

	test.describe('with prefers-reduced-motion: reduce', () => {
		test.use({ reducedMotion: 'reduce' })

		test('jump-to-discussion scrolls instantly', async ({ page }) => {
			await openBottomCard(page)
			await resetScrolls(page)

			await page.locator('.card-modal__discussion-toggle').click()

			await expect.poll(() => requestedBehaviors(page), { timeout: 10_000 }).toEqual(['auto'])
			await expect(page.locator('.card-modal__discussion')).toBeFocused()
		})

		test('the comment deep link scrolls instantly', async ({ page }) => {
			await openCommentDeepLink(page)

			await expect.poll(() => requestedBehaviors(page), { timeout: 10_000 }).toContain('auto')
			expect(await requestedBehaviors(page)).not.toContain('smooth')
		})

		test('the timeline jump-to-today scrolls instantly', async ({ page }) => {
			await openTimeline(page)
			await resetScrolls(page)

			await page.getByRole('button', { name: 'Jump to today' }).click()

			await expect.poll(() => requestedBehaviors(page), { timeout: 10_000 }).toContain('auto')
			expect(await requestedBehaviors(page)).not.toContain('smooth')
		})
	})

	// ── No preference: the animation is still there ───────────────────────────
	//
	// Without these, "always pass 'auto'" would pass the suite above — the fix
	// has to be conditional on the preference, not a blanket removal.

	test.describe('with no motion preference', () => {
		test.use({ reducedMotion: 'no-preference' })

		test('jump-to-discussion still scrolls smoothly', async ({ page }) => {
			await openBottomCard(page)
			await resetScrolls(page)

			await page.locator('.card-modal__discussion-toggle').click()

			await expect.poll(() => requestedBehaviors(page), { timeout: 10_000 }).toEqual(['smooth'])
		})

		test('the comment deep link still scrolls smoothly', async ({ page }) => {
			await openCommentDeepLink(page)

			await expect.poll(() => requestedBehaviors(page), { timeout: 10_000 }).toContain('smooth')
		})

		test('the timeline jump-to-today still scrolls smoothly', async ({ page }) => {
			await openTimeline(page)
			await resetScrolls(page)

			await page.getByRole('button', { name: 'Jump to today' }).click()

			await expect.poll(() => requestedBehaviors(page), { timeout: 10_000 }).toContain('smooth')
		})
	})
})
