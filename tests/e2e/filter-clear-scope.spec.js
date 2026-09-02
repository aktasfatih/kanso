// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// #10091 — "Clear filters" may only clear the facets the control actually
// RENDERS.
//
// The filter bar is shared by two surfaces that render DIFFERENT facet sets, over
// one and the same filter state. A View hides the Labels facet on purpose (label
// ids are board-scoped and collide across boards) while seeding the View's own
// saved label filter into that state — so a blanket clear wiped a constraint the
// user could not see, silently widening the page from "this View" to the entire
// unfiltered cross-board feed, with a page reload as the only way back.
//
// Two halves, one invariant:
//   1. On a View, clearing keeps the hidden saved label filter — and the feed
//      request that follows still carries the `fl` short key.
//   2. On a board, where Labels IS rendered, clearing still clears labels.
// Without the second half the fix could be "never clear labels", which would
// break the surface the button was written for.

test.describe('Clear filters is scoped to the rendered facets (#10091)', () => {
	test('on a View it keeps the hidden saved label filter (and the feed keeps `fl`)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })

		const stamp = Math.floor(Date.now() / 1000)
		// One board; two cards share the View's label (one bug, one feature) and a
		// third carries no label at all. The View is defined by that label alone, so
		// "the label filter survived" is directly readable off the rendered rows: two
		// rows, never the whole cross-board feed.
		const board = await api.post('/boards', { title: 'ClearScope ' + stamp })
		const label = await api.post('/labels', { boardId: board.id, title: 'vclear ' + stamp, color: 'cc0066' })
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		const bugTitle = 'ClearScope bug ' + stamp
		const featureTitle = 'ClearScope feature ' + stamp
		const bug = await api.post('/cards', { stackId: stack.id, title: bugTitle })
		const feature = await api.post('/cards', { stackId: stack.id, title: featureTitle })
		await api.post('/cards', { stackId: stack.id, title: 'ClearScope unlabelled ' + stamp })
		await api.put(`/cards/${bug.id}/labels/${label.id}`)
		await api.put(`/cards/${feature.id}/labels/${label.id}`)
		await api.patch(`/cards/${bug.id}`, { type: 'bug' })
		await api.patch(`/cards/${feature.id}`, { type: 'feature' })

		const created = await api.put('/views', {
			name: 'ClearScope ' + stamp,
			filter: { labels: [label.id] },
			groupBy: 'status',
			display: 'list',
		})
		const view = created.views[created.views.length - 1]

		try {
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/views/${view.id}`)

			const rowBug = page.locator('.board-list-row__title', { hasText: bugTitle })
			const rowFeature = page.locator('.board-list-row__title', { hasText: featureTitle })
			const trigger = page.locator('.board-filter-bar__trigger')
			await expect(rowBug).toBeVisible({ timeout: 20_000 })
			await expect(rowFeature).toBeVisible({ timeout: 15_000 })
			// The saved label filter counts as one active constraint even though its
			// facet is hidden — the trigger deliberately over-reports rather than hide
			// an active constraint.
			await expect(trigger).toContainText('Filter · 1')

			// There is no Views section to click through: this surface passes no saved
			// views, so the empty "Views / Default (no filter)" block is not rendered.
			await trigger.click()
			await expect(page.locator('.board-filter-bar__saved-item')).toHaveCount(0)

			// Layer a VISIBLE facet on top: Type = Bug.
			await page.locator('.board-filter-bar__dim-row[data-dim="types"]').click()
			await page.locator('.board-filter-bar__opt', { hasText: /^Bug$/ }).click()
			await expect(rowFeature).toHaveCount(0, { timeout: 15_000 })
			await expect(rowBug).toBeVisible()
			await expect(trigger).toContainText('Filter · 2')

			// ── Clear ────────────────────────────────────────────────────────────────
			await page.locator('.board-filter-bar__back').click()
			await page.locator('.board-filter-bar__clear').click()

			// The visible facet is gone…
			await expect(rowFeature).toBeVisible({ timeout: 15_000 })
			await expect(rowBug).toBeVisible()
			// …and the hidden one is NOT: the View is still exactly its two labelled
			// cards, not the unfiltered cross-board feed (which would render the whole
			// dev database into this list).
			await expect(page.locator('.board-list-row__title')).toHaveCount(2)
			await expect(trigger).toContainText('Filter · 1')

			// With nothing left that this control renders, Clear is no longer offered —
			// it would be a button that clears nothing visible.
			await expect(page.locator('.board-filter-bar__clear')).toHaveCount(0)
			await page.keyboard.press('Escape')

			// ── The wire, not just the DOM ───────────────────────────────────────────
			// Clearing leaves the query key untouched (that is the point), so force the
			// next feed request by changing the sort: it must still carry `fl`. Without
			// this the spec would pass on a client-side-only survival while the server
			// was being asked for the unfiltered feed — the heaviest query in the app.
			const filteredFeed = page.waitForRequest(
				(r) => r.url().includes('/api/views/cards')
					&& r.url().includes('sortMode=title')
					&& r.url().includes(`fl=${label.id}`),
				{ timeout: 20_000 },
			)
			await page.locator('.view-page__sort button').first().click()
			await page.waitForTimeout(400)
			await page.locator('.action-radio__text', { hasText: /^Title$/ }).click()
			await filteredFeed
			await page.keyboard.press('Escape')
			await expect(page.locator('.board-list-row__title')).toHaveCount(2)
		} finally {
			await api.delete(`/views/${view.id}`).catch(() => {})
			await api.delete(`/boards/${board.id}`).catch(() => {})
		}
	})

	test('on a board, where the Labels facet IS rendered, clearing still clears labels', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })

		const stamp = Math.floor(Date.now() / 1000)
		const board = await api.post('/boards', { title: 'ClearScopeBoard ' + stamp })
		const label = await api.post('/labels', { boardId: board.id, title: 'bclear ' + stamp, color: '0066cc' })
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		const taggedTitle = 'ClearScopeBoard tagged ' + stamp
		const plainTitle = 'ClearScopeBoard plain ' + stamp
		const tagged = await api.post('/cards', { stackId: stack.id, title: taggedTitle })
		await api.post('/cards', { stackId: stack.id, title: plainTitle })
		await api.put(`/cards/${tagged.id}/labels/${label.id}`)

		try {
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/board/${board.id}`)
			await page.waitForSelector('.board-view__header', { timeout: 20_000 })

			const tileTagged = page.locator('.card-tile__title', { hasText: taggedTitle })
			const tilePlain = page.locator('.card-tile__title', { hasText: plainTitle })
			await expect(tileTagged).toHaveCount(1)
			await expect(tilePlain).toHaveCount(1)

			// Filter by the label through the rendered Labels facet.
			const trigger = page.locator('.board-filter-bar__trigger')
			await trigger.click()
			await page.locator('.board-filter-bar__dim-row[data-dim="labels"]').click()
			await page.locator('.board-filter-bar__opt-text', { hasText: 'bclear ' + stamp }).click()
			await expect(tilePlain).toHaveCount(0, { timeout: 15_000 })
			await expect(tileTagged).toHaveCount(1)
			await expect.poll(() => page.url()).toContain(`fl=${label.id}`)

			// Clear: on THIS surface the Labels facet is rendered, so it must still be
			// cleared — every card returns, the count badge drops, and the shareable
			// URL loses its `fl` param.
			await page.locator('.board-filter-bar__back').click()
			await page.locator('.board-filter-bar__clear').click()
			await page.keyboard.press('Escape')

			await expect(tilePlain).toHaveCount(1, { timeout: 15_000 })
			await expect(tileTagged).toHaveCount(1)
			await expect(trigger).not.toContainText('·')
			await expect.poll(() => page.url()).not.toContain('fl=')
		} finally {
			await api.delete(`/boards/${board.id}`).catch(() => {})
		}
	})
})
