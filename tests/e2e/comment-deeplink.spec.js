// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Scroll-to-comment deep links (#3870).
 *
 * A reminder notification links to a card carrying a `#comment-<id>` fragment
 * (Notifier::cardLink). The fragment-free deep-link boot (main.js) stashes that
 * id into the route query (`?comment=<id>`) before it hash-routes into the SPA;
 * CardDetail then scrolls to + briefly highlights that comment once the thread
 * loads. This spec drives the full-page card route with such a target and asserts
 * the targeted comment is scrolled into view and carries the highlight class.
 */

import { test, expect, api, ncLogin, BASE, collectConsoleErrors } from './helpers.js'

test.describe('Scroll-to-comment deep links (#3870)', () => {
	const state = { boardId: 0, cardId: 0, comments: [], replyId: 0 }

	test.beforeAll(async () => {
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Comment Deeplink E2E') {
				await api.delete(`/boards/${b.id}`)
			}
		}
		const board = await api.post('/boards', { title: 'Comment Deeplink E2E' })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Card With Many Comments' })
		state.cardId = card.id

		// Enough top-level comments that the target is well below the fold, so a
		// scroll genuinely has to happen (not already in view).
		for (let i = 1; i <= 8; i++) {
			const c = await api.post(`/cards/${card.id}/comments`, { body: `Comment number ${i} body text here` })
			state.comments.push(c.id)
		}
		// A reply under the first comment - replies are deep-linkable too.
		const reply = await api.post(`/cards/${card.id}/comments`, {
			body: 'A nested reply to comment one',
			parentCommentId: state.comments[0],
		})
		state.replyId = reply.id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('full-page card route with ?comment=<id> scrolls to + highlights the target', async ({ page }) => {
		// The 6th comment - far enough down the thread to require a scroll.
		const targetId = state.comments[5]
		await ncLogin(page)

		// Collect AFTER logging in, and filter by the message's source bundle. The
		// old `favicon|manifest|ResizeObserver` allowlist was a string allowlist,
		// and it rotted exactly as one does: CI failed here on "could not load
		// recommendation preview Event" + a 404, both from
		// /apps/recommendations/js/ — another app's code, logged on the Nextcloud
		// DASHBOARD that ncLogin redirects through, not on the card page this test
		// asserts about. collectConsoleErrors() keys on the source URL instead, so
		// it does not need a new entry every time an unrelated app changes wording.
		const errors = collectConsoleErrors(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/card/${state.cardId}?comment=${targetId}`)

		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const target = page.locator(`#comment-${targetId}`)
		await expect(target).toBeVisible({ timeout: 10_000 })

		// The transient highlight class lands on the target while it fades.
		await expect(target).toHaveClass(/card-modal__comment-group--highlight/, { timeout: 5000 })

		// And it is actually scrolled into the viewport (not merely present in DOM).
		await expect(target).toBeInViewport({ timeout: 5000 })

		// The highlight is transient: it clears after the fade (~4s).
		await expect(target).not.toHaveClass(/card-modal__comment-group--highlight/, { timeout: 8000 })

		// No console errors from Kanso's own code on the scroll-to-comment path.
		expect(errors, `console errors: ${errors.join('\n')}`).toEqual([])
	})

	test('raw #comment-<id> fragment on the full-page route also scrolls + highlights', async ({ page }) => {
		// An in-app link that carries the raw fragment (no query) still works: the
		// SPA reads window.location.hash as a fallback.
		const targetId = state.comments[6]
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/card/${state.cardId}#comment-${targetId}`)

		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const target = page.locator(`#comment-${targetId}`)
		await expect(target).toBeVisible({ timeout: 10_000 })
		await expect(target).toHaveClass(/card-modal__comment-group--highlight/, { timeout: 5000 })
		await expect(target).toBeInViewport({ timeout: 5000 })
	})

	test('the target stays in view when the composer lands after the scroll', async ({ page }) => {
		// The thread pane is still settling when the deep-link scroll runs. The
		// comment composer only renders once the BOARD read has resolved (it is
		// gated on the viewer's edit permission), and its MarkdownEditor is a lazy
		// chunk on top of that - while the comments themselves come from their own,
		// independent query. On a slow connection the composer therefore appears
		// AFTER the scroll, and `.card-modal__thread-scroll` (`flex: 1`) shrinks by
		// its full height. `scrollTop` survives that; the comment we centred does
		// not. Nothing used to scroll again, so the reader silently landed on a
		// different comment than the one the link pointed at.
		//
		// The board read is gated on the test rather than on a sleep, so the
		// ordering is fixed rather than raced: it is held back until the highlight
		// proves the scroll has happened, and only then allowed through.
		let releaseBoardRead
		const boardReadGate = new Promise((resolve) => { releaseBoardRead = resolve })
		await page.route('**/apps/kanso/api/boards/*', async (route) => {
			await boardReadGate
			await route.continue()
		})

		const targetId = state.comments[6]
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/card/${state.cardId}?comment=${targetId}`)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const target = page.locator(`#comment-${targetId}`)
		await expect(target).toBeVisible({ timeout: 10_000 })
		// The scroll has run by the time the highlight is on, and the composer is
		// provably not on screen yet - so the reflow below really is after it.
		await expect(target).toHaveClass(/card-modal__comment-group--highlight/, { timeout: 5000 })
		await expect(page.locator('.card-modal__composer')).toHaveCount(0)
		await expect(target).toBeInViewport({ timeout: 5000 })

		// Now let the composer in and shrink the thread pane under the target.
		releaseBoardRead()
		await expect(page.locator('.card-modal__composer .kanso-md-editor')).toBeVisible({ timeout: 10_000 })

		// The deep link's target must survive that reflow, not be left below it.
		await expect(target).toBeInViewport({ timeout: 5000 })
	})

	test('a reply is deep-linkable and gets highlighted', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/card/${state.cardId}?comment=${state.replyId}`)

		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const reply = page.locator(`#comment-${state.replyId}`)
		await expect(reply).toBeVisible({ timeout: 10_000 })
		await expect(reply).toHaveClass(/card-modal__comment--highlight/, { timeout: 5000 })
	})

	test('an unknown comment fragment opens the card normally with no error', async ({ page }) => {
		await ncLogin(page)

		// Same collector as the first test in this file, for the same reason: the
		// `favicon|manifest|ResizeObserver` allowlist rotted on messages from
		// /apps/recommendations/js/ logged during ncLogin's dashboard detour.
		// collectConsoleErrors() keys on the source bundle, and is attached after the
		// login so that detour is out of scope by construction.
		const errors = collectConsoleErrors(page)

		// A comment id that does not exist in this thread.
		await page.goto(`${BASE}/index.php/apps/kanso#/card/${state.cardId}?comment=99999999`)

		await page.waitForSelector('.card-modal', { timeout: 10_000 })
		// Card opens fine and the thread renders.
		await expect(page.locator('.card-modal__comment-group').first()).toBeVisible({ timeout: 10_000 })
		// Nothing got the highlight class.
		await expect(page.locator('.card-modal__comment-group--highlight')).toHaveCount(0)

		expect(errors, `console errors: ${errors.join('\n')}`).toEqual([])
	})
})
