// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')

async function api(method, path, body) {
	const r = await fetch(API + path, {
		method,
		headers: { ...HEADERS, Authorization: AUTH },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`${method} ${path} → ${r.status}: ${await r.text()}`)
	return method === 'DELETE' ? null : r.json()
}

async function ncLogin(page) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	if (!(await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false))) return
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

/** yyyy-mm-dd offset by `days` from now, as an ATOM datetime the API accepts. */
function isoOffset(days) {
	return new Date(Date.now() + days * 24 * 60 * 60 * 1000).toISOString()
}

test.describe('Board filter bar + saved filters (#3407)', () => {
	const state = {
		boardId: 0,
		labelId: 0,
		// four cards spanning the filter dimensions we test
		matchId: 0, // has label + overdue → passes "label AND overdue"
		labelOnlyId: 0, // has label, NOT overdue → fails "overdue"
		overdueOnlyId: 0, // overdue, NO label → fails "label"
		plainId: 0, // neither
	}

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Filters ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api('POST', '/stacks', { boardId: board.id, title: 'To do' })

		// Give the board an estimate scale so the "Estimate" filter dimension is
		// offered (it is hidden on a 'none'-scale board) — needed by the
		// clear-removes-URL-param test below.
		await api('PUT', `/boards/${board.id}`, { estimateScale: 'fibonacci' })

		const label = await api('POST', '/labels', { boardId: board.id, title: 'Backend', color: 'e74c3c' })
		state.labelId = label.id

		// matchId: label + overdue (due yesterday)
		const c1 = await api('POST', '/cards', { stackId: stack.id, title: 'Match Card' })
		await api('PUT', `/cards/${c1.id}/labels/${state.labelId}`)
		await api('PATCH', `/cards/${c1.id}`, { duedate: isoOffset(-1) })
		state.matchId = c1.id

		// labelOnly: label, due in the far future (not overdue)
		const c2 = await api('POST', '/cards', { stackId: stack.id, title: 'Label Only Card' })
		await api('PUT', `/cards/${c2.id}/labels/${state.labelId}`)
		await api('PATCH', `/cards/${c2.id}`, { duedate: isoOffset(30) })
		state.labelOnlyId = c2.id

		// overdueOnly: overdue, no label
		const c3 = await api('POST', '/cards', { stackId: stack.id, title: 'Overdue Only Card' })
		await api('PATCH', `/cards/${c3.id}`, { duedate: isoOffset(-1) })
		state.overdueOnlyId = c3.id

		// plain: no label, no due
		const c4 = await api('POST', '/cards', { stackId: stack.id, title: 'Plain Card' })
		state.plainId = c4.id
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('label AND overdue filter shows only matching cards; save + reload + re-apply; shared URL applies on load', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// All four cards visible with no filter.
		await expect(page.locator('.card-tile__title', { hasText: 'Match Card' })).toHaveCount(1)
		await expect(page.locator('.card-tile__title', { hasText: 'Plain Card' })).toHaveCount(1)

		// ── Apply a label + overdue filter (progressive drill-in, #3785) ──────────
		// The Filter popover opens at the ROOT dimension list. Drill into "Labels",
		// check "Backend", go back, drill into "Due date", pick "Overdue".
		await page.locator('.board-filter-bar__filter button').first().click()
		await page.locator('.board-filter-bar__dim-row[data-dim="labels"]').click()
		await page.locator('.board-filter-bar__opt-text', { hasText: 'Backend' }).click()
		await page.locator('.board-filter-bar__back').click()
		await page.locator('.board-filter-bar__dim-row[data-dim="due"]').click()
		await page.locator('.board-filter-bar__opt-text', { hasText: 'Overdue' }).click()
		// Close the popover by pressing Escape so the board is interactable.
		await page.keyboard.press('Escape')

		// Only the Match Card (label AND overdue) remains.
		await expect(page.locator('.card-tile__title', { hasText: 'Match Card' })).toHaveCount(1)
		await expect(page.locator('.card-tile__title', { hasText: 'Label Only Card' })).toHaveCount(0)
		await expect(page.locator('.card-tile__title', { hasText: 'Overdue Only Card' })).toHaveCount(0)
		await expect(page.locator('.card-tile__title', { hasText: 'Plain Card' })).toHaveCount(0)

		// The active filter is reflected in the URL (shareable). In hash-history
		// mode the query lives after the hash, so it shows up in page.url().
		await expect.poll(() => page.url()).toContain('fd=overdue')
		expect(page.url()).toContain(`fl=${state.labelId}`)

		// ── Save it as a named view (Saved views now live INSIDE Filter, #3785) ───
		// The save-as input is an NcTextField in the Filter popover root; it is
		// teleported to the body, so target it by its placeholder globally.
		await page.locator('.board-filter-bar__filter button').first().click()
		const nameInput = page.getByPlaceholder('View name')
		await nameInput.fill('Backend overdue')
		await nameInput.press('Enter')
		// The saved view now appears as a saved-item row (reopen the popover).
		await page.keyboard.press('Escape')
		await page.locator('.board-filter-bar__filter button').first().click()
		await expect(page.locator('.board-filter-bar__saved-item', { hasText: 'Backend overdue' })).toBeVisible()
		await page.keyboard.press('Escape')

		// ── Reload → clear the filter → re-apply the saved view ──────────────────
		// Navigate to the board WITHOUT filter params (fresh, no filter).
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		// All cards back (filter cleared).
		await expect(page.locator('.card-tile__title', { hasText: 'Plain Card' })).toHaveCount(1)

		// Apply the saved view (from the Filter popover's Views section).
		await page.locator('.board-filter-bar__filter button').first().click()
		await page.locator('.board-filter-bar__saved-item', { hasText: 'Backend overdue' }).click()
		await page.keyboard.press('Escape')
		// Filter re-applied: only the Match Card remains.
		await expect(page.locator('.card-tile__title', { hasText: 'Match Card' })).toHaveCount(1)
		await expect(page.locator('.card-tile__title', { hasText: 'Plain Card' })).toHaveCount(0)

		// ── A shared URL with filter params applies the filter on load ────────────
		const sharedUrl = `${BASE}/index.php/apps/kanso#/board/${state.boardId}?fl=${state.labelId}&fd=overdue`
		await page.goto(sharedUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		// Filter is active straight from the URL: only the Match Card shows.
		await expect(page.locator('.card-tile__title', { hasText: 'Match Card' })).toHaveCount(1)
		await expect(page.locator('.card-tile__title', { hasText: 'Label Only Card' })).toHaveCount(0)
		await expect(page.locator('.card-tile__title', { hasText: 'Overdue Only Card' })).toHaveCount(0)
		await expect(page.locator('.card-tile__title', { hasText: 'Plain Card' })).toHaveCount(0)
	})

	test('the "Default (no filter)" view exits a saved view back to unfiltered', async ({ page }) => {
		await ncLogin(page)
		// Land on the board with the saved view active straight from the URL.
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}?fl=${state.labelId}&fd=overdue`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		// Filtered: only the Match Card is shown.
		await expect(page.locator('.card-tile__title', { hasText: 'Match Card' })).toHaveCount(1)
		await expect(page.locator('.card-tile__title', { hasText: 'Plain Card' })).toHaveCount(0)

		// Open the Filter popover and pick "Default (no filter)" from its Views.
		await page.locator('.board-filter-bar__filter button').first().click()
		await page.locator('.board-filter-bar__saved-item', { hasText: 'Default (no filter)' }).click()
		await page.keyboard.press('Escape')

		// Back to unfiltered: every card returns and the URL filter params are gone.
		await expect(page.locator('.card-tile__title', { hasText: 'Plain Card' })).toHaveCount(1)
		await expect(page.locator('.card-tile__title', { hasText: 'Overdue Only Card' })).toHaveCount(1)
		await expect.poll(() => page.url()).not.toContain('fl=')
		expect(page.url()).not.toContain('fd=')
	})

	// #3828: clearing a filter must also strip its query param from the shareable
	// URL. FILTER_QUERY_KEYS (the set of params the URL-sync watcher owns and may
	// remove) previously omitted ft/fe/fw, so clearing a type / estimate / waiting
	// filter left a stale ft=/fe=/fw= dangling in the URL. Load each filter from
	// the URL, clear it via the UI, and assert the param is gone.
	test('clearing a type / estimate / waiting filter removes its URL param (#3828)', async ({ page }) => {
		await ncLogin(page)

		// ── Type filter (ft): drill into "Type", uncheck the applied "Bug" ────────
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}?ft=bug`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		expect(page.url()).toContain('ft=bug')
		await page.locator('.board-filter-bar__filter button').first().click()
		await page.locator('.board-filter-bar__dim-row[data-dim="types"]').click()
		// Toggle the checked "Bug" option off (it is applied from the URL).
		await page.locator('.board-filter-bar__opt-text', { hasText: 'Bug' }).click()
		await page.keyboard.press('Escape')
		await expect.poll(() => page.url()).not.toContain('ft=')

		// ── Estimate filter (fe): drill into "Estimate", uncheck the applied token ─
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}?fe=3`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		expect(page.url()).toContain('fe=3')
		await page.locator('.board-filter-bar__filter button').first().click()
		await page.locator('.board-filter-bar__dim-row[data-dim="estimates"]').click()
		await page.locator('.board-filter-bar__opt-text', { hasText: /^3$/ }).click()
		await page.keyboard.press('Escape')
		await expect.poll(() => page.url()).not.toContain('fe=')

		// ── Waiting filter (fw): drill into "Client status", pick "Any" to clear ──
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}?fw=waiting`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		expect(page.url()).toContain('fw=waiting')
		await page.locator('.board-filter-bar__filter button').first().click()
		await page.locator('.board-filter-bar__dim-row[data-dim="waiting"]').click()
		await page.locator('.board-filter-bar__opt-text', { hasText: 'Any' }).click()
		await page.keyboard.press('Escape')
		await expect.poll(() => page.url()).not.toContain('fw=')
	})
})
