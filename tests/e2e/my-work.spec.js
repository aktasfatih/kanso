// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// My Work hub e2e (#3458): the three personal cross-board pages (My tasks,
// Reviews, Inbox) are unified under one nav entry with a tab strip. Switching
// tabs swaps the embedded sub-view; each is shown WITHOUT its own header (the
// hub supplies the single "My Work" title). A board filter is present.

import { test, expect, api, me, ncLogin, BASE } from './helpers.js'

test.describe('My Work hub', () => {
	test('one nav entry, three tabs, each swapping the embedded sub-view', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-work`)

		const hub = page.locator('.my-work-view')
		await expect(hub).toBeVisible({ timeout: 10_000 })

		// Single hub title; the three tabs live in a tablist.
		await expect(page.getByRole('heading', { name: 'My Work' })).toBeVisible()
		const tabs = page.locator('[role="tablist"] button[role="tab"]')
		await expect(tabs).toHaveCount(3)

		// Default tab = My tasks: the embedded MyCardsView renders WITHOUT its own
		// header (the hub owns the title), proving embedded mode.
		await expect(page.locator('.my-cards-view')).toBeVisible({ timeout: 8_000 })
		await expect(page.locator('.my-cards-view__header')).toHaveCount(0)

		// Switch to Reviews.
		await page.getByRole('tab', { name: 'Reviews' }).click()
		await expect(page.locator('.my-reviews-view')).toBeVisible({ timeout: 8_000 })
		await expect(page.locator('.my-cards-view')).toHaveCount(0)

		// Switch to Inbox.
		await page.getByRole('tab', { name: 'Inbox' }).click()
		await expect(page.locator('.inbox-view')).toBeVisible({ timeout: 8_000 })
		await expect(page.locator('.my-reviews-view')).toHaveCount(0)

		// The board filter control is present.
		await expect(page.locator('#my-work-board-filter')).toBeVisible()
	})

	test('the standalone sub-view routes still render with their own header', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)
		// Not embedded → its own header is shown (backward compatible).
		await expect(page.locator('.my-cards-view__header')).toBeVisible({ timeout: 10_000 })
	})
})

// #10187 — the hub double-inset its embedded pages on DESKTOP too.
//
// MyWorkView draws the page box (padding: 24px 32px; max-width: 860px) and then
// renders each sub-view inside it; every sub-view re-applied the identical box.
// So embedded content sat 64px from the edge — visibly stepped 32px right of the
// hub's own "My Work" heading — inside a max-width nested in an equal max-width,
// which can only ever narrow what the hub already constrained. #10183 fixed the
// phone half only (its acceptance criteria said "desktop unchanged"); the
// mobile assertion lives in tests/e2e/mobile-pwa.spec.js. This is the desktop
// half, and it belongs here because this spec is the desktop one.
test.describe('My Work hub inset (#10187)', () => {
	const state = { boardId: 0, cardId: 0, title: '' }

	test.beforeAll(async () => {
		state.title = 'HubInset ' + Math.floor(Date.now() / 1000)
		const board = await api.post('/boards', { title: 'HubInset E2E ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api.post('/cards', { stackId: stack.id, title: state.title })
		state.cardId = card.id
		// Populate BOTH the My tasks and the Reviews tab, so those two are
		// measured against a real list rather than an empty state.
		await api.put(`/cards/${card.id}/assignees/${me}`)
		await api.put(`/cards/${card.id}/reviews/${me}`)
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('every embedded page lines up with the hub header instead of insetting again', async ({ page }) => {
		await ncLogin(page)
		// A desktop viewport — well clear of the 680px phone breakpoint, and wide
		// enough that the hub's 860px box is the only thing constraining width.
		await page.setViewportSize({ width: 1440, height: 900 })
		await page.goto(`${BASE}/index.php/apps/kanso#/my-work`)
		await expect(page.locator('.my-work-view')).toBeVisible({ timeout: 15_000 })

		for (const [tabName, viewSelector] of [
			['My tasks', '.my-cards-view'],
			['Reviews', '.my-reviews-view'],
			['Inbox', '.inbox-view'],
		]) {
			await page.getByRole('tab', { name: tabName, exact: true }).click()
			const view = page.locator(viewSelector)
			await expect(view).toBeVisible({ timeout: 15_000 })
			// Measuring mid-load would read the spinner's box, not the content's.
			await expect(view.locator('.my-cards-view__spinner, .my-reviews-view__spinner, .inbox-view__spinner'))
				.toHaveCount(0, { timeout: 15_000 })

			// Probe the embedded view's first VISIBLE block — a section list, or
			// the empty state when a tab has nothing. Either way it is a
			// block-level child that fills its parent's content box, so its edges
			// ARE that content box: purely geometric, no style read-back. The
			// "visible" filter matters — MyCardsView renders one <section> per
			// date bucket and the empty buckets are display:none, whose rect is
			// all zeros and would satisfy a left-edge check vacuously.
			const box = await view.evaluate((el) => {
				const hub = document.querySelector('.my-work-view')
				const title = document.querySelector('.my-work-view__title')
				const r = (n) => n.getBoundingClientRect()
				const content = [...el.children].find((c) => r(c).width > 0)
				if (!content) throw new Error('the embedded view rendered no visible block to measure')
				return {
					contentLeft: r(content).left,
					contentRight: r(content).right,
					titleLeft: r(title).left,
					// The hub's own content box, from its header: the header is a
					// block child of the hub, so it spans exactly that box.
					hubContentRight: r(document.querySelector('.my-work-view__header')).right,
					hubWidth: r(hub).width,
				}
			})

			// The hub already supplies the inset, so the embedded page must start
			// on the same line as the hub's heading — not 32px right of it.
			expect(box.contentLeft - box.titleLeft, `${tabName}: embedded page is inset a second time`)
				.toBeLessThanOrEqual(1)
			// …and it must not be narrowed on the right either, which is what a
			// second padding and a nested max-width both do.
			expect(box.hubContentRight - box.contentRight, `${tabName}: embedded page is narrowed inside the hub`)
				.toBeLessThanOrEqual(1)
			// Sanity: the hub really IS its own 860px box here — otherwise the two
			// assertions above could pass for the wrong reason (a viewport too
			// narrow for either box's max-width to bind).
			expect(box.hubWidth, `${tabName}: hub is not the constraining 860px box`)
				.toBeCloseTo(860, 0)
		}
	})

	test('the standalone routes keep their own page box', async ({ page }) => {
		await ncLogin(page)
		await page.setViewportSize({ width: 1440, height: 900 })
		for (const [route, viewSelector] of [
			['my-tasks', '.my-cards-view'],
			['reviews', '.my-reviews-view'],
			['inbox', '.inbox-view'],
		]) {
			await page.goto(`${BASE}/index.php/apps/kanso#/${route}`)
			const view = page.locator(viewSelector)
			await expect(view).toBeVisible({ timeout: 15_000 })
			// Not embedded → the modifier must not be applied, so the view keeps
			// the inset and the width cap it owns as a page in its own right.
			await expect(view).not.toHaveClass(/--embedded/)
			const style = await view.evaluate((el) => {
				const cs = getComputedStyle(el)
				return { paddingLeft: cs.paddingLeft, maxWidth: cs.maxWidth }
			})
			expect(style.paddingLeft, `#/${route} lost its page inset`).toBe('32px')
			expect(style.maxWidth, `#/${route} lost its width cap`).toBe('860px')
		}
	})
})
