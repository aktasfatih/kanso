// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// A filter on a dimension the surface has nothing to offer for must still be
// CLEARABLE — and a dimension the surface OWNS must still stay hidden.
//
// #10091 scoped "Clear filters" to the facets the bar renders. The gap that left:
// a facet can be unavailable (no labels on the board, no estimation scale, and
// `archived`, which a board never offers) while a constraint on it is live — from
// a shared link, or simply because a board-settings change made it unavailable
// after the fact. The board then rendered filtered, or empty, showing `Filter · 1`
// with no row to open and no Clear button.
//
// The rule is now "render what constrains the surface", with one exception: a
// LOCKED dimension (the View page's seeded label filter) is the surface's own
// identity rather than a filter the user applied, so it stays hidden and Clear
// still may not touch it. Both halves are asserted here; without the last test,
// the fix would just be #10091 again.

const PANEL = '.board-filter-bar__panel'
const TRIGGER = '.board-filter-bar__trigger'

// ── Driving the filter popover ────────────────────────────────────────────────
// The panel is an NcPopover (floating-vue), and both of its state changes are
// ASYNCHRONOUS in ways that quietly poison a spec that just clicks and asserts:
//
//  1. `Escape` resolves long before the panel is gone. Clicking the trigger right
//     after therefore lands on a popover floating-vue still considers shown, and
//     TOGGLES IT SHUT instead of opening it — with nothing left to reopen it.
//  2. A closed popper is not removed immediately: it is kept mounted with
//     `visibility: hidden` for several seconds before being disposed. Every
//     visibility-blind assertion (`toHaveCount`, `toHaveText`) still passes
//     against that corpse, so a spec can march happily through a closed panel
//     until it hits the first assertion that does need visibility — a `click()`,
//     which then waits for an element that is hidden, then detached, then never
//     comes back. That is a 120s hang with no assertion error, which is exactly
//     how this spec used to fail on CI (fast local timing merely hid the race).
//
// So: never touch the trigger without knowing what the panel is doing, and pin
// the open/closed state on VISIBILITY, never on presence.
async function openFilterPanel(page) {
	await expect(page.locator(PANEL)).toBeHidden()
	await page.locator(TRIGGER).click()
	await expect(page.locator(PANEL)).toBeVisible()
}

async function closeFilterPanel(page) {
	await page.keyboard.press('Escape')
	await expect(page.locator(PANEL)).toBeHidden()
}

test.describe('A hidden-but-active filter is clearable — unless the surface owns it', () => {
	test('on a board, `?far=only` renders the Archived facet and Clear brings the cards back', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })

		const stamp = Math.floor(Date.now() / 1000)
		const board = await api.post('/boards', { title: 'HiddenActive ' + stamp })
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		const oneTitle = 'HiddenActive one ' + stamp
		const twoTitle = 'HiddenActive two ' + stamp
		await api.post('/cards', { stackId: stack.id, title: oneTitle })
		await api.post('/cards', { stackId: stack.id, title: twoTitle })

		try {
			await ncLogin(page)
			// `far=only` is a real filter key the board applies on mount, but a board
			// never offers the Archived facet (it drops archived cards before the
			// predicate runs), so this is the dead-end shape: every card filtered out.
			await page.goto(`${BASE}/index.php/apps/kanso#/board/${board.id}?far=only`)
			await page.waitForSelector('.board-view__header', { timeout: 20_000 })

			const tileOne = page.locator('.card-tile__title', { hasText: oneTitle })
			const tileTwo = page.locator('.card-tile__title', { hasText: twoTitle })
			const trigger = page.locator(TRIGGER)
			await expect(trigger).toContainText('Filter · 1', { timeout: 15_000 })
			await expect(tileOne).toHaveCount(0)
			await expect(tileTwo).toHaveCount(0)

			// The constraint is on screen: its own row, badged, with the active value
			// spelled out — not an invisible one behind an empty menu.
			await openFilterPanel(page)
			const archivedRow = page.locator('.board-filter-bar__dim-row[data-dim="archived"]')
			await expect(archivedRow).toHaveCount(1)
			await expect(archivedRow).toBeVisible()
			await expect(archivedRow.locator('.board-filter-bar__dim-badge')).toHaveText('1')
			await expect(archivedRow).toContainText('Only archived')

			// …and therefore clearable, in the UI, without touching the URL.
			await page.locator('.board-filter-bar__clear').click()
			await closeFilterPanel(page)

			await expect(tileOne).toHaveCount(1, { timeout: 15_000 })
			await expect(tileTwo).toHaveCount(1)
			await expect(trigger).not.toContainText('·')
			await expect.poll(() => page.url()).not.toContain('far=')

			// Cleared away, the facet is unavailable again — a board has nothing to
			// offer here, so it does not linger as an empty control.
			await openFilterPanel(page)
			// Panel first, then the absence — see the View test.
			await expect(page.locator('.board-filter-bar__dim-row[data-dim="types"]')).toBeVisible()
			await expect(page.locator('.board-filter-bar__dim-row[data-dim="archived"]')).toHaveCount(0)
			await closeFilterPanel(page)
		} finally {
			await api.delete(`/boards/${board.id}`).catch(() => {})
		}
	})

	// The same shape via the dimension that gets there without a hand-made URL: a
	// label filter outlives the label (delete the board's last label and the Labels
	// facet has nothing to offer, while `fl` survives in state and in the URL). A
	// board with no labels at all is the state such a board ends up in.
	//
	// Its own test rather than a second act of the one above: the board case needs
	// a surface that arrives already carrying the filter, and re-navigating an
	// open board only changes the hash — no reload, so the previous scenario's
	// popover and filter state bleed into this one.
	test('on a board, `?fl=<no such label>` renders the Labels facet and Clear brings the cards back', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })

		const stamp = Math.floor(Date.now() / 1000)
		const board = await api.post('/boards', { title: 'HiddenLabel ' + stamp })
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		const oneTitle = 'HiddenLabel one ' + stamp
		const twoTitle = 'HiddenLabel two ' + stamp
		await api.post('/cards', { stackId: stack.id, title: oneTitle })
		await api.post('/cards', { stackId: stack.id, title: twoTitle })

		try {
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/board/${board.id}?fl=99999999`)
			await page.waitForSelector('.board-view__header', { timeout: 20_000 })

			const tileOne = page.locator('.card-tile__title', { hasText: oneTitle })
			const tileTwo = page.locator('.card-tile__title', { hasText: twoTitle })
			const trigger = page.locator(TRIGGER)
			await expect(trigger).toContainText('Filter · 1', { timeout: 20_000 })
			await expect(tileOne).toHaveCount(0)
			await expect(tileTwo).toHaveCount(0)

			await openFilterPanel(page)
			const labelRow = page.locator('.board-filter-bar__dim-row[data-dim="labels"]')
			await expect(labelRow).toHaveCount(1)
			await expect(labelRow).toBeVisible()
			await expect(labelRow.locator('.board-filter-bar__dim-badge')).toHaveText('1')

			await page.locator('.board-filter-bar__clear').click()
			await closeFilterPanel(page)

			await expect(tileOne).toHaveCount(1, { timeout: 15_000 })
			await expect(tileTwo).toHaveCount(1)
			await expect(trigger).not.toContainText('·')
			await expect.poll(() => page.url()).not.toContain('fl=')

			// And with the constraint gone, a board with no labels offers no Labels
			// facet again.
			await openFilterPanel(page)
			await expect(page.locator('.board-filter-bar__dim-row[data-dim="types"]')).toBeVisible()
			await expect(labelRow).toHaveCount(0)
			await closeFilterPanel(page)
		} finally {
			await api.delete(`/boards/${board.id}`).catch(() => {})
		}
	})

	test('on a View the seeded label filter stays hidden and unclearable (#10091 holds)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })

		const stamp = Math.floor(Date.now() / 1000)
		const board = await api.post('/boards', { title: 'HiddenLocked ' + stamp })
		const label = await api.post('/labels', { boardId: board.id, title: 'vlock ' + stamp, color: '00aa88' })
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		const keptTitle = 'HiddenLocked kept ' + stamp
		const kept = await api.post('/cards', { stackId: stack.id, title: keptTitle })
		await api.post('/cards', { stackId: stack.id, title: 'HiddenLocked unlabelled ' + stamp })
		await api.put(`/cards/${kept.id}/labels/${label.id}`)

		// Both facets a cross-board View withholds because they are board-scoped:
		// labels and estimates. `__none__` is the Unestimated sentinel, so it keeps
		// the labelled (unestimated) card in the feed while still being an active
		// constraint on a facet this page does not render.
		const created = await api.put('/views', {
			name: 'HiddenLocked ' + stamp,
			filter: { labels: [label.id], estimates: ['__none__'] },
			groupBy: 'status',
			display: 'list',
		})
		const view = created.views[created.views.length - 1]

		try {
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/views/${view.id}`)

			const rowKept = page.locator('.board-list-row__title', { hasText: keptTitle })
			const trigger = page.locator(TRIGGER)
			await expect(rowKept).toBeVisible({ timeout: 20_000 })
			await expect(page.locator('.board-list-row__title')).toHaveCount(1)
			await expect(trigger).toContainText('Filter · 2')

			// Both filters are active and both facets are unavailable here — exactly
			// the shape the board cases above now surface. They must NOT surface here:
			// these constraints ARE the View.
			await openFilterPanel(page)
			// Wait for the panel to actually render before asserting ABSENCE — a
			// count-0 assertion is satisfied by a popover that has not opened yet.
			await expect(page.locator('.board-filter-bar__dim-row[data-dim="types"]')).toBeVisible()
			await expect(page.locator('.board-filter-bar__dim-row[data-dim="labels"]')).toHaveCount(0)
			await expect(page.locator('.board-filter-bar__dim-row[data-dim="estimates"]')).toHaveCount(0)
			// With nothing rendered that is constraining the page, Clear is not offered
			// at all — so it cannot wipe the View's identity (#10091). That Clear also
			// spares this filter when a VISIBLE facet is layered on top is what
			// filter-clear-scope.spec.js pins; it is not repeated here.
			await expect(page.locator('.board-filter-bar__clear')).toHaveCount(0)
			await closeFilterPanel(page)
			// Still the View's one labelled card, not the whole cross-board feed.
			await expect(page.locator('.board-list-row__title')).toHaveCount(1)
			await expect(rowKept).toBeVisible()
		} finally {
			await api.delete(`/views/${view.id}`).catch(() => {})
			await api.delete(`/boards/${board.id}`).catch(() => {})
		}
	})
})
