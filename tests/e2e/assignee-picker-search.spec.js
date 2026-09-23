// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Card #10704 — on a board with more people than the participants cap, the
// assignee picker only ever listed the first page and said nothing about it,
// so everyone past it was unassignable through the UI.
//
// The picker now carries a search box that asks the SERVER for the substring
// (the cached page cannot contain the person we are after, so filtering it
// client-side would be useless), and a note that appears exactly when the
// server held people back.
//
// The board here is seeded past the cap with real Nextcloud users: the defect
// only exists above it, and a stubbed payload would prove the note renders
// without proving anyone can actually be assigned.

import { test, expect, api, ncLogin, BASE, provisionUser, deleteUser } from './helpers.js'

// ParticipantService::RESULT_LIMIT. Seeded deliberately above it.
const CAP = 25
const POOL = 30

test.describe('Assigning someone the participants cap leaves out (#10704)', () => {
	const state = { boardId: 0, cardId: 0, smallBoardId: 0, smallCardId: 0, boardUrl: '', smallBoardUrl: '', uids: [] }

	// Provisioning + sharing 30 accounts is ~60 round trips; the default test
	// timeout is not the budget for a fixture this size.
	test.beforeAll(async ({}, testInfo) => {
		test.setTimeout(180_000)
		const prefix = `e2epool${testInfo.workerIndex}_`

		const board = await api.post('/boards', { title: 'Big board ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		state.cardId = (await api.post('/cards', { stackId: stack.id, title: 'Needs the 30th person' })).id

		// "Pool Person NN" sorts after the acting user's own display name, so the
		// tail of this list is exactly what the cap sheds.
		for (let i = 1; i <= POOL; i++) {
			const uid = prefix + String(i).padStart(2, '0')
			state.uids.push(uid)
			await provisionUser(uid, 'Kanso-e2e-pool!1', { displayName: `Pool Person ${String(i).padStart(2, '0')}` })
			await api.post(`/boards/${board.id}/acl`, { participant: uid, participantType: 'user', permission: 1 })
		}

		// A second board nobody else is on: the control for the note, which must
		// not nag on a board whose list really is everyone.
		const small = await api.post('/boards', { title: 'Small board ' + Math.floor(Date.now() / 1000) })
		state.smallBoardId = small.id
		state.smallBoardUrl = `${BASE}/index.php/apps/kanso#/board/${small.id}`
		const smallStack = await api.post('/stacks', { boardId: small.id, title: 'To do' })
		state.smallCardId = (await api.post('/cards', { stackId: smallStack.id, title: 'Just me here' })).id
	})

	test.afterAll(async () => {
		test.setTimeout(180_000)
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
		if (state.smallBoardId) await api.delete(`/boards/${state.smallBoardId}`).catch(() => {})
		// Leave the instance as we found it — 30 stray accounts would otherwise
		// turn up in every other spec's share and mention pickers.
		for (const uid of state.uids) await deleteUser(uid)
	})

	/**
	 * Open a card's modal and its assignee picker.
	 *
	 * @param {import('@playwright/test').Page} page The page.
	 * @param {string} boardUrl The board to open.
	 * @param {string} title The card title to open.
	 * @return {Promise<import('@playwright/test').Locator>} the open popover.
	 */
	async function openAssignPicker(page, boardUrl, title) {
		await ncLogin(page)
		await page.goto(boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })
		await page.locator('.card-tile').filter({ hasText: title }).click()
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })
		await page.locator('.card-modal__attrbar button[data-pill="assign"]').click()
		const popover = page.locator('.card-modal__attrbar .card-modal__popover')
		await expect(popover).toBeVisible()
		return popover
	}

	test('the picker says the list is only the first page, and searching assigns someone past it', async ({ page }) => {
		const before = await api.get(`/cards/${state.cardId}`)
		for (const uid of before.assigneeIds || []) {
			await api.delete(`/cards/${state.cardId}/assignees/${uid}`)
		}

		const popover = await openAssignPicker(page, state.boardUrl, 'Needs the 30th person')
		const rows = popover.locator('.card-modal__assign-option')
		await expect(rows).toHaveCount(CAP, { timeout: 15_000 })

		// The truncation is stated, not left to be inferred from a list that
		// simply stops. This is the half the bug was missing entirely.
		const note = popover.locator('[data-assign-note]')
		await expect(note).toBeVisible()
		await expect(note).toContainText(String(CAP))

		// …and the person we want is genuinely not on screen, so clicking around
		// could never have reached them.
		const target = 'Pool Person 30'
		await expect(popover.locator('.card-modal__assign-option', { hasText: target })).toHaveCount(0)

		// Search: the answer comes from the server, not from the page in hand.
		await popover.locator('[data-assign-search]').fill('person 30')
		const targetRow = popover.locator('.card-modal__assign-option', { hasText: target })
		await expect(targetRow).toBeVisible()
		await expect(rows).toHaveCount(1)

		await targetRow.click()

		// The pill names them rather than falling back to their bare uid…
		const pill = page.locator('.card-modal__attrbar .card-modal__assignee-pill', { hasText: target })
		await expect(pill).toBeVisible()

		// …and keeps the name once the search that found them is cleared: the
		// cached page still cannot resolve this uid, so dropping what the search
		// turned up would put a raw uid back on the card a keystroke later.
		await popover.locator('[data-assign-search]').fill('')
		await expect(rows).toHaveCount(CAP, { timeout: 15_000 })
		await expect(pill).toBeVisible()

		// …and the server really holds the assignment.
		await expect.poll(
			async () => (await api.get(`/cards/${state.cardId}`)).assigneeIds,
			{ timeout: 15_000 },
		).toEqual([state.uids[POOL - 1]])
	})

	test('a search that matches nobody says so instead of looking like an empty board', async ({ page }) => {
		const popover = await openAssignPicker(page, state.boardUrl, 'Needs the 30th person')
		await popover.locator('[data-assign-search]').fill('nobody-by-that-name')

		const empty = popover.locator('.card-modal__popover-empty')
		await expect(empty).toContainText('matches that')
		// The "nobody has access to this board yet" line would be a lie here.
		await expect(empty).not.toContainText('Nobody has access to this board yet')
	})

	test('a board everyone fits on gets no truncation note at all', async ({ page }) => {
		const popover = await openAssignPicker(page, state.smallBoardUrl, 'Just me here')
		await expect(popover.locator('.card-modal__assign-option')).toHaveCount(1)
		// Unconditional hedging would be its own (smaller) lie: this list IS the
		// board, and the note must stay quiet to mean anything on the big one.
		await expect(popover.locator('[data-assign-note]')).toHaveCount(0, { timeout: 2000 })
	})
})
