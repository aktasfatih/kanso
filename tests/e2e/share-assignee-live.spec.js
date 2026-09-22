// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Card #10669 — "When we share it with a new person, I have to refresh the page.
// We have to refresh the page for it to show up under assignees."
//
// The server was never at fault: AclService::create appends its ENTITY_ACL
// change row. The break was in the client's cache. The participant list — the
// assignee picker's data source — lives under its own key with a deliberate
// 3-minute staleTime, and the three ACL mutations invalidated only the board
// key. So the one action that changes who has access was also the one action
// that could not refresh the list it changes.
//
// This spec drives the whole thing through the UI, because that staleness is
// invisible at the API layer: every request involved already returned the right
// answer. It has to WARM the picker before sharing — that first open is what
// parks the pre-share list under the staleTime, and without it the test would
// pass on an empty cache and guard nothing.
//
// Scope note: this is the SHARER's own tab, which is what was reported. The
// recipient's already-open tabs are a separate matter — useBoardDelta consumes
// only ENTITY_CARD rows, so an ENTITY_ACL row still reaches them on the next
// full board read, and wiring that up is its own change.
//
// The storageState opt-out below is deliberate even though only the owner ever
// logs in: it makes the identity this test acts as explicit (the `user`
// fixture, admin off-isolate and the worker account under E2E_ISOLATE=1) rather
// than inheriting whatever session the shared state file happens to hold.

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.use({
	storageState: { cookies: [], origins: [] },
	viewport: { width: 1600, height: 900 },
})

test.describe('Sharing a board updates the assignee picker without a reload (#10669)', () => {
	const CARD = 'Needs an owner'
	const state = { boardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Share assignee live ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		await api.post('/cards', { stackId: stack.id, title: CARD })
		// Deliberately NOT shared with the peer here: performing the share through
		// the real sharing dialog, mid-session, is the whole test.
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	/**
	 * Open the seeded card and its assignee picker.
	 *
	 * @param {import('@playwright/test').Page} page The page.
	 * @return {Promise<import('@playwright/test').Locator>} The open popover.
	 */
	async function openAssignPicker(page) {
		await page.locator('.card-tile').filter({ hasText: CARD }).click()
		await page.waitForSelector('.card-modal__attrbar', { timeout: 10_000 })
		await page.locator('.card-modal__attrbar button[data-pill="assign"]').click()
		const popover = page.locator('.card-modal__attrbar .card-modal__popover')
		await expect(popover).toBeVisible()
		return popover
	}

	test('a user shared with mid-session becomes assignable immediately', async ({ page, user, peer }) => {
		await ncLogin(page, { user: user.user, pass: user.pass })
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		// ── 1. Warm the cache ────────────────────────────────────────────────────
		// The peer is not a member yet, so the picker must not offer them. This
		// open is also what makes the rest of the test mean something: it parks the
		// pre-share participant list under a 3-minute staleTime, which is exactly
		// the state the reporter was in when they reached for the assignee picker.
		let popover = await openAssignPicker(page)
		await expect(popover.locator('.card-modal__assign-option')).not.toHaveCount(0)
		await expect(popover.locator('.card-modal__assign-option', { hasText: peer.user })).toHaveCount(0)
		await page.keyboard.press('Escape') // close the picker
		await page.keyboard.press('Escape') // close the card
		await expect(page.locator('.card-modal__attrbar')).toHaveCount(0)

		// ── 2. Share the board, through the dialog a user would use ──────────────
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.locator('#bs-rail-tab-sharing').click()

		const shared = page.waitForResponse(
			(r) => r.url().includes(`/boards/${state.boardId}/acl`)
				&& r.request().method() === 'POST'
				&& r.ok(),
		)
		// The sharee search needs >= 2 characters and debounces 250 ms.
		await page.locator('.sharing__search-input').fill(peer.user)
		const result = page.locator('.sharing__dropdown-item', { hasText: peer.user }).first()
		await expect(result).toBeVisible({ timeout: 10_000 })
		await result.click()
		await shared

		await expect(page.locator('.sharing__entry', { hasText: peer.user })).toBeVisible({ timeout: 8_000 })
		await page.keyboard.press('Escape') // close board settings
		await expect(page.locator('#bs-pane-sharing')).toHaveCount(0)

		// ── 3. No reload — the picker has to know ────────────────────────────────
		// Nothing below reloads the page, and nothing waits out the staleTime: if
		// the share did not invalidate the participant list, this is where the
		// reporter had to press F5.
		popover = await openAssignPicker(page)
		const peerOption = popover.locator('.card-modal__assign-option', { hasText: peer.user })
		await expect(peerOption).toHaveCount(1, { timeout: 10_000 })

		// And it is a working option, not just a rendered row — the point of the
		// picker is assigning, and the server refuses a non-reader.
		await peerOption.click()
		await expect(
			page.locator('.card-modal__attrbar .card-modal__assignee-pill').filter({ hasText: peer.user }),
		).toHaveCount(1, { timeout: 10_000 })
	})
})
