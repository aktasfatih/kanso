// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Card #10655 — a card with four assignees must not silently show three.
//
// Every board surface caps the avatar stack at 3, but only the kanban tile used
// to say so: the list row and the timeline pane sliced to 3 and rendered no
// "+N" badge, so the 4th assignee vanished with nothing prompting the user to
// open the card. All three now mount the same AssigneeAvatars component; this
// spec pins the badge AND its count on each of them, so dropping the badge (or
// letting one surface drift back to a hand-rolled slice) goes red.
//
// It also closes a standing coverage gap: nothing anywhere asserted that a card
// can carry MORE THAN ONE assignee. Multi-assign is supported at every layer
// (kanso_card_assignees is a join table, AssigneeService::assign() is a pure
// append), but it was never tested — the "Paired task" card below is the pin.

import { test, expect, api, ncLogin, provisionUser, deleteUser, BASE, me } from './helpers.js'

// The stack shows this many avatars; everyone past it collapses into "+N".
const MAX_VISIBLE = 3

/**
 * Assert an avatar stack inside `scope` renders exactly `avatars` avatars and,
 * when `badge` is a string, an overflow badge with exactly that text.
 *
 * The badge text is asserted, not just its presence: "3 avatars render" is true
 * with or without the badge, so only the count discriminates the bug.
 *
 * @param {import('@playwright/test').Locator} scope Row/tile to look inside.
 * @param {object} expected What the stack should show.
 * @param {number} expected.avatars How many avatars must render.
 * @param {string|null} expected.badge Badge text, or null for "no badge at all".
 */
async function expectAvatarStack(scope, { avatars, badge }) {
	await expect(scope.locator('.assignee-stack__avatar')).toHaveCount(avatars)
	const overflow = scope.locator('.assignee-stack__overflow')
	if (badge === null) {
		await expect(overflow).toHaveCount(0)
	} else {
		await expect(overflow).toHaveCount(1)
		await expect(overflow).toHaveText(badge)
	}
}

test.describe('Assignee overflow badge on every board surface (#10655)', () => {
	const state = { boardId: 0, extras: [] }
	const EXTRA_PASS = 'Kanso#Assignee2026'

	test.beforeAll(async ({}, workerInfo) => {
		// Three extra identities beyond `me`, named off the worker index so
		// parallel workers never fight over the same accounts.
		const names = ['a', 'b', 'c'].map((s) => `kanso_assignee_w${workerInfo.workerIndex}_${s}`)
		for (const name of names) {
			await provisionUser(name, EXTRA_PASS, { displayName: name })
			state.extras.push(name)
		}

		const board = await api.post('/boards', { title: 'Assignee overflow ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		// AssigneeService refuses a participant who cannot read the board, so
		// share it with each extra user first (READ is enough).
		for (const uid of state.extras) {
			await api.post(`/boards/${board.id}/acl`, { participant: uid, participantType: 'user', permission: 1 })
		}

		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })

		// 4 assignees → 3 avatars + "+1". Dated so the timeline schedules it
		// (undated cards land in the collapsed "unscheduled" list instead).
		const crowded = await api.post('/cards', { stackId: stack.id, title: 'Crowded task' })
		await api.patch(`/cards/${crowded.id}`, { duedate: '2026-08-10T00:00:00+00:00' })
		await api.put(`/cards/${crowded.id}/assignees/${me}`)
		for (const uid of state.extras) {
			await api.put(`/cards/${crowded.id}/assignees/${uid}`)
		}

		// 2 assignees → 2 avatars, no badge. This is also the only coverage
		// anywhere that a card holds more than one assignee at all.
		const paired = await api.post('/cards', { stackId: stack.id, title: 'Paired task' })
		await api.patch(`/cards/${paired.id}`, { duedate: '2026-08-11T00:00:00+00:00' })
		await api.put(`/cards/${paired.id}/assignees/${me}`)
		await api.put(`/cards/${paired.id}/assignees/${state.extras[0]}`)

		// Multi-assign is real on the server, not just in the UI cache.
		const seeded = await api.get(`/cards/${crowded.id}`)
		expect(seeded.assigneeIds.length).toBe(MAX_VISIBLE + 1)
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
		for (const uid of state.extras) await deleteUser(uid).catch(() => {})
	})

	/**
	 * Open the board and switch it to the named display mode.
	 *
	 * @param {import('@playwright/test').Page} page The page.
	 * @param {string} view 'Board', 'List' or 'Timeline'.
	 */
	async function openBoard(page, view) {
		await ncLogin(page)
		// The view mode is remembered per board in localStorage — start clean so
		// the menu click is what decides the mode.
		await page.addInitScript(() => { try { localStorage.clear() } catch (e) { /* private mode */ } })
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		if (view === 'Board') return
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByRole('menuitemradio', { name: view, exact: true }).click()
		await page.keyboard.press('Escape')
	}

	test('kanban tile shows 3 avatars + "+1"', async ({ page }) => {
		await openBoard(page, 'Board')
		const crowded = page.locator('.card-tile', { hasText: 'Crowded task' })
		await expect(crowded).toBeVisible({ timeout: 15_000 })
		await expectAvatarStack(crowded, { avatars: MAX_VISIBLE, badge: '+1' })

		const paired = page.locator('.card-tile', { hasText: 'Paired task' })
		await expectAvatarStack(paired, { avatars: 2, badge: null })
	})

	test('list view shows 3 avatars + "+1", and no badge under the cap', async ({ page }) => {
		await openBoard(page, 'List')
		await page.waitForSelector('.board-list-row', { timeout: 10_000 })

		const crowded = page.locator('.board-list-row', { hasText: 'Crowded task' })
		await expect(crowded).toBeVisible()
		await expectAvatarStack(crowded, { avatars: MAX_VISIBLE, badge: '+1' })

		const paired = page.locator('.board-list-row', { hasText: 'Paired task' })
		await expect(paired).toBeVisible()
		await expectAvatarStack(paired, { avatars: 2, badge: null })
	})

	test('timeline view shows 3 avatars + "+1", and no badge under the cap', async ({ page }) => {
		await openBoard(page, 'Timeline')
		await page.waitForSelector('.timeline__pane-row', { timeout: 10_000 })

		const crowded = page.locator('.timeline__pane-row', { hasText: 'Crowded task' })
		await expect(crowded).toBeVisible()
		await expectAvatarStack(crowded, { avatars: MAX_VISIBLE, badge: '+1' })

		const paired = page.locator('.timeline__pane-row', { hasText: 'Paired task' })
		await expect(paired).toBeVisible()
		await expectAvatarStack(paired, { avatars: 2, badge: null })
	})
})
