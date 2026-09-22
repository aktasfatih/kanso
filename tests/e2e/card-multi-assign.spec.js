// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Card #10603 — multi-assign has to be REACHABLE from the card modal.
//
// Multi-assign has always worked at every layer below the UI (the join table,
// the append-only AssigneeService, the array in the API), and
// assignee-overflow.spec.js already pins that a card can CARRY several
// assignees. What nothing covered was the part a user actually touches: adding
// the second one. The picker used to close on the first pick and — on a board
// whose participant list was then exhausted, i.e. any personal board with a
// single participant — the "Assign" pill disappeared with it, leaving a card
// with one assignee and no assignee control at all. That reads as "one
// assignee is the maximum", which is exactly what was reported.
//
// So this spec drives the modal, not the API: add a 2nd and a 3rd assignee in
// one open picker, remove one and keep the rest, and assert the control never
// vanishes once every participant is on the card.
//
// It stays the storageState admin throughout (the extra identities are only
// ever assignees, never logged in), so it needs no `test.use` opt-out.

import { test, expect, api, ncLogin, provisionUser, deleteUser, BASE, me } from './helpers.js'

test.describe('Adding a second assignee from the card modal (#10603)', () => {
	const EXTRA_PASS = 'Kanso#MultiAssign2026'
	const state = { boardId: 0, cardId: 0, trioCardId: 0, fullCardId: 0, extras: [], boardUrl: '' }

	test.beforeAll(async ({}, workerInfo) => {
		// Two extra identities beyond `me`, named off the worker index so
		// parallel workers never fight over the same accounts.
		const names = ['x', 'y'].map((s) => `kanso_multiassign_w${workerInfo.workerIndex}_${s}`)
		for (const name of names) {
			await provisionUser(name, EXTRA_PASS, { displayName: name })
			state.extras.push(name)
		}

		const board = await api.post('/boards', { title: 'Multi assign ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
		// AssigneeService refuses a participant who cannot read the board.
		for (const uid of state.extras) {
			await api.post(`/boards/${board.id}/acl`, { participant: uid, participantType: 'user', permission: 1 })
		}
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })

		// One card per test, each seeded through the API, so a retry re-enters a
		// known state instead of inheriting whatever the previous attempt left
		// behind (every test below re-seeds its own card's assignees first).
		const card = await api.post('/cards', { stackId: stack.id, title: 'Pair up on this' })
		state.cardId = card.id
		const trio = await api.post('/cards', { stackId: stack.id, title: 'Three on this one' })
		state.trioCardId = trio.id
		// A card that already has EVERY participant, for the "the control must
		// not disappear when the candidate list runs dry" case.
		const full = await api.post('/cards', { stackId: stack.id, title: 'Everyone is on it' })
		state.fullCardId = full.id
		for (const uid of [me, ...state.extras]) {
			await api.put(`/cards/${full.id}/assignees/${uid}`)
		}
	})

	/**
	 * Force a card's assignees to exactly `uids`, so each test (and each retry)
	 * starts from the state it describes.
	 *
	 * @param {number} cardId The card to reset.
	 * @param {string[]} uids The assignees it should end up with.
	 */
	async function setAssignees(cardId, uids) {
		const card = await api.get(`/cards/${cardId}`)
		for (const uid of card.assigneeIds || []) {
			if (!uids.includes(uid)) await api.delete(`/cards/${cardId}/assignees/${uid}`)
		}
		for (const uid of uids) await api.put(`/cards/${cardId}/assignees/${uid}`)
	}

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
		for (const uid of state.extras) await deleteUser(uid).catch(() => {})
	})

	/**
	 * Open the board and the named card's modal.
	 *
	 * @param {import('@playwright/test').Page} page The page.
	 * @param {string} title The card title to open.
	 */
	async function openCard(page, title) {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })
		await page.locator('.card-tile').filter({ hasText: title }).click()
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })
	}

	/** The assignee name pills in the attribute bar (not the contact ones). */
	const assigneePill = (page, name) =>
		page.locator('.card-modal__attrbar .card-modal__assignee-name', { hasText: name })

	test('a second and third assignee go on without reopening the picker', async ({ page }) => {
		await setAssignees(state.cardId, [])
		await openCard(page, 'Pair up on this')

		const assignPill = page.locator('.card-modal__attrbar button[data-pill="assign"]')
		await expect(assignPill).toBeVisible()
		await assignPill.click()

		const popover = page.locator('.card-modal__attrbar .card-modal__popover')
		await expect(popover).toBeVisible()

		// First assignee.
		await popover.locator('.card-modal__assign-option', { hasText: state.extras[0] }).click()
		await expect(assigneePill(page, state.extras[0])).toBeVisible()

		// …and the picker is STILL open, so the second one is one click away.
		// This is the whole bug: it used to close here, and on a board with no
		// candidates left it took the "Assign" pill with it.
		await expect(popover).toBeVisible()

		// Second assignee, from the same open picker.
		await popover.locator('.card-modal__assign-option', { hasText: state.extras[1] }).click()
		await expect(assigneePill(page, state.extras[1])).toBeVisible()

		// Third — myself, still without reopening anything.
		await popover.locator('.card-modal__assign-option', { hasText: me }).first().click()
		await expect(page.locator('.card-modal__attrbar .card-modal__assignee-pill')).toHaveCount(3)

		// Assigned rows stay listed and are marked as assigned (they toggle off),
		// rather than disappearing from the list - visually and for a screen reader.
		await expect(popover.locator('.card-modal__assign-option--active')).toHaveCount(3)
		await expect(popover.locator('.card-modal__assign-option[aria-pressed="true"]')).toHaveCount(3)

		// The server agrees — not just the optimistic cache.
		await expect.poll(
			async () => (await api.get(`/cards/${state.cardId}`)).assigneeIds.length,
			{ timeout: 10_000 },
		).toBe(3)

		// And the board tile shows them: 3 avatars, no overflow badge at the cap.
		await page.keyboard.press('Escape') // close picker
		await page.keyboard.press('Escape') // close card
		const tile = page.locator('.card-tile').filter({ hasText: 'Pair up on this' })
		await expect(tile.locator('.assignee-stack__avatar')).toHaveCount(3)
	})

	test('removing one of several assignees leaves the others alone', async ({ page }) => {
		await setAssignees(state.trioCardId, [me, ...state.extras])
		await openCard(page, 'Three on this one')

		const pills = page.locator('.card-modal__attrbar .card-modal__assignee-pill')
		await expect(pills).toHaveCount(3)

		// Drop the first extra; the other two must survive.
		await pills.filter({ hasText: state.extras[0] }).getByTitle('Remove assignee').click()
		await expect(pills).toHaveCount(2)
		await expect(assigneePill(page, state.extras[1])).toBeVisible()
		await expect(assigneePill(page, me)).toBeVisible()

		const served = await api.get(`/cards/${state.trioCardId}`)
		expect(served.assigneeIds).not.toContain(state.extras[0])
		expect(served.assigneeIds).toContain(state.extras[1])
	})

	test('the assign control stays put when every participant is already assigned', async ({ page }) => {
		await setAssignees(state.fullCardId, [me, ...state.extras])
		await openCard(page, 'Everyone is on it')

		await expect(page.locator('.card-modal__attrbar .card-modal__assignee-pill')).toHaveCount(3)

		// The dead-end: this pill used to be `v-if`-ed away the moment no
		// unassigned candidate was left.
		const assignPill = page.locator('.card-modal__attrbar button[data-pill="assign"]')
		await expect(assignPill).toBeVisible()
		await assignPill.click()

		const popover = page.locator('.card-modal__attrbar .card-modal__popover')
		// Everyone is listed, all of them marked assigned, plus an explanation of
		// why there is nobody else to pick.
		await expect(popover.locator('.card-modal__assign-option--active')).toHaveCount(3)
		await expect(popover.locator('.card-modal__popover-empty')).toContainText('already assigned to this card')
	})
})
