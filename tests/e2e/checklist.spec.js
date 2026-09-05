// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, me } from './helpers.js'

// Wait for a checklist-item toggle PATCH to complete so badge/progress
// assertions aren't racing the optimistic render on a slow CI runner.
// Toggles hit PATCH /api/checklist/{itemId}; adds hit POST .../checklist.
function waitForChecklistPatch(page) {
	return page.waitForResponse(
		(res) =>
			/\/api\/checklist\/\d+(?:\?|$)/.test(res.url())
			&& res.request().method() === 'PATCH'
			&& res.status() < 400,
		{ timeout: 20_000 },
	)
}

// Toggle a checklist item to done, exactly once.
//
// This used to click up to 4× to "absorb a cold-start race where the first
// @change is dropped". The @change was never dropped: a just-added row renders
// from the optimistic create and carried a NEGATIVE placeholder id until the
// settle refetch landed, so the first click fired
// `PATCH /api/checklist/-1788…`, which matched no row, rolled the tick back and
// lost it. The app now swaps in the server row as soon as the create resolves
// and disables the checkbox until it has (see useChecklist.addItem.onSuccess),
// so the deterministic precondition is simply "the row carries its real id".
// Waiting on that, then clicking once, is the whole story — if the toggle does
// not land, that is a regression and this must fail.
async function toggleChecklistItem(page, itemText) {
	const item = page.locator('.card-modal__checklist-item').filter({ hasText: itemText })
	await expect(item).toBeVisible({ timeout: 15_000 })
	// Real, server-assigned id — never the optimistic `-<timestamp>` placeholder.
	await expect(item).toHaveAttribute('data-item-id', /^\d+$/, { timeout: 15_000 })

	const checkbox = item.locator('.card-modal__checklist-checkbox')
	await expect(checkbox).toBeVisible({ timeout: 15_000 })

	// If it's already checked (e.g. persisted from a prior run/retry), nothing
	// to do — the item is already done.
	if (await checkbox.isChecked()) return

	// Disabled while a previous toggle PATCH is still pending.
	await expect(checkbox).toBeEnabled({ timeout: 15_000 })

	const patch = waitForChecklistPatch(page)
	await checkbox.click()
	await patch
	await expect(checkbox).toBeChecked({ timeout: 15_000 })
}

test.describe('Checklist', () => {
	const state = { boardId: 0, cardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		// Tear down any prior board with the same name to ensure hermetic setup
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Checklist Test Board') {
				await api.delete(`/boards/${b.id}`)
			}
		}

		// Create fresh board + stack + card
		const board = await api.post('/boards', { title: 'Checklist Test Board' })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Card With Checklist' })
		state.cardId = card.id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test('add two checklist items via UI, toggle one done, assert progress and persistence', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// Open the card modal by clicking the card tile
		const cardTile = page.locator('.card-tile').filter({ hasText: 'Card With Checklist' })
		await expect(cardTile).toBeVisible({ timeout: 5000 })
		await cardTile.click()

		// Wait for the card modal to open
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Checklist section should be visible
		const checklistSection = page.locator('.card-modal__checklist')
		await expect(checklistSection).toBeVisible({ timeout: 5000 })

		// Add first item "Buy groceries" via the add input
		const addInput = page.locator('.card-modal__checklist-add-input')
		await addInput.fill('Buy groceries')
		await addInput.press('Enter')

		// Wait for the item to appear in the list
		await expect(page.locator('.card-modal__checklist-item').filter({ hasText: 'Buy groceries' }))
			.toBeVisible({ timeout: 5000 })

		// Add second item "Write tests"
		await addInput.fill('Write tests')
		await addInput.press('Enter')

		await expect(page.locator('.card-modal__checklist-item').filter({ hasText: 'Write tests' }))
			.toBeVisible({ timeout: 5000 })

		// Assert progress shows 0/2 initially
		await expect(page.locator('.card-modal__checklist-count'))
			.toHaveText('0 / 2', { timeout: 3000 })

		// Toggle "Buy groceries" done by clicking its checkbox. This waits for the
		// item + checkbox to be visible/enabled and awaits the PATCH response so
		// the progress/badge assertions below don't race the optimistic render on
		// a slow CI runner.
		await toggleChecklistItem(page, 'Buy groceries')

		// Progress should update to 1/2
		await expect(page.locator('.card-modal__checklist-count'))
			.toHaveText('1 / 2', { timeout: 15_000 })

		// The progress bar should be visible and partially filled
		await expect(page.locator('.card-modal__checklist-bar')).toBeVisible()
		await expect(page.locator('.card-modal__checklist-bar-fill')).toBeVisible()

		// Regression guard for the boardQueryKey type-mismatch bug (Deck #3576):
		// the optimistic checklist-progress patch must land on the board tile
		// IMMEDIATELY, while the modal is still open and before any refetch. The
		// card tile stays mounted behind the modal, so its badge should already
		// read 1/2 from the optimistic setQueryData on the board cache. With the
		// bug, that write hit a numeric-keyed sibling entry and no-op'd, so the
		// tile only corrected on the next poll. A short timeout keeps this from
		// masking the bug by waiting for the 5s poll to bail us out.
		await expect(
			page.locator('.card-tile').filter({ hasText: 'Card With Checklist' })
				.locator('.card-tile__checklist'),
		).toHaveText(/1\/2/, { timeout: 1500 })

		// Close the modal by pressing Escape or clicking outside
		await page.keyboard.press('Escape')

		// Wait for modal to close
		await page.waitForSelector('.card-modal', { state: 'hidden', timeout: 5000 }).catch(() => {})

		// The card tile should now show a checklist badge with 1/2
		await expect(
			page.locator('.card-tile').filter({ hasText: 'Card With Checklist' })
				.locator('.card-tile__checklist'),
		).toHaveText(/1\/2/, { timeout: 15_000 })

		// Re-open the board fresh and assert persistence (navigate to the board
		// URL rather than page.reload() so the check is independent of the
		// post-Escape route).
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// Tile badge should still show 1/2 after reload
		const tileAfterReload = page.locator('.card-tile').filter({ hasText: 'Card With Checklist' })
		await expect(tileAfterReload.locator('.card-tile__checklist'))
			.toHaveText(/1\/2/, { timeout: 15_000 })

		// Open the card again and verify modal progress is also 1/2
		await tileAfterReload.click()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })
		await expect(page.locator('.card-modal__checklist-count'))
			.toHaveText('1 / 2', { timeout: 15_000 })

		// Verify items are still present
		await expect(page.locator('.card-modal__checklist-item').filter({ hasText: 'Buy groceries' }))
			.toBeVisible()
		await expect(page.locator('.card-modal__checklist-item').filter({ hasText: 'Write tests' }))
			.toBeVisible()

		// Verify the done item still has the line-through style
		const doneItem = page.locator('.card-modal__checklist-item').filter({ hasText: 'Buy groceries' })
		await expect(doneItem.locator('.card-modal__checklist-checkbox')).toBeChecked()
	})

	test('complete all items - badge turns success color, progress bar turns green', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// Open card modal
		const cardTile = page.locator('.card-tile').filter({ hasText: 'Card With Checklist' })
		await cardTile.click()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Ensure the checklist has hydrated before interacting: on a cold-start
		// slow runner the item row/checkbox can lag, which previously hung the
		// whole test on an un-actionable .check(). Wait for both items to render
		// first.
		await expect(page.locator('.card-modal__checklist-item').filter({ hasText: 'Buy groceries' }))
			.toBeVisible({ timeout: 15_000 })
		await expect(page.locator('.card-modal__checklist-item').filter({ hasText: 'Write tests' }))
			.toBeVisible({ timeout: 15_000 })

		// Toggle "Write tests" done (Buy groceries is already done from previous
		// test). The helper waits for the row + checkbox to be visible/enabled and
		// awaits the PATCH response so nothing races the optimistic render.
		await toggleChecklistItem(page, 'Write tests')

		// Progress should show 2/2
		await expect(page.locator('.card-modal__checklist-count'))
			.toHaveText('2 / 2', { timeout: 15_000 })

		// Progress bar should have the complete class (green)
		await expect(page.locator('.card-modal__checklist-bar-fill--complete'))
			.toBeVisible({ timeout: 15_000 })

		// Close and check tile badge has --complete styling
		await page.keyboard.press('Escape')
		await page.waitForSelector('.card-modal', { state: 'hidden', timeout: 5000 }).catch(() => {})

		const badge = page.locator('.card-tile').filter({ hasText: 'Card With Checklist' })
			.locator('.card-tile__checklist--complete')
		await expect(badge).toBeVisible({ timeout: 15_000 })
		await expect(badge).toHaveText(/2\/2/)
	})
})

// Rich checklist steps (#3745): per-item assignee, due date (with overdue
// styling), done_at stamping, and the cross-board /api/my-steps feed.
test.describe('Checklist steps', () => {
	const state = { boardId: 0, cardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Checklist Steps Board') {
				await api.delete(`/boards/${b.id}`)
			}
		}

		const board = await api.post('/boards', { title: 'Checklist Steps Board' })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Card With Steps' })
		state.cardId = card.id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test('assign a step, set an overdue due date, complete it - done_at stamps and my-steps tracks it', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		const cardTile = page.locator('.card-tile').filter({ hasText: 'Card With Steps' })
		await cardTile.click()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Add the step.
		const addInput = page.locator('.card-modal__checklist-add-input')
		await addInput.fill('Send contract')
		await addInput.press('Enter')
		const item = page.locator('.card-modal__checklist-item').filter({ hasText: 'Send contract' })
		await expect(item).toBeVisible({ timeout: 10_000 })

		// Assign it to the current user via the row's assign picker.
		await item.hover()
		const assignRes = page.waitForResponse(
			(res) => /\/api\/checklist\/\d+\/assign/.test(res.url())
				&& res.request().method() === 'POST' && res.status() < 400,
			{ timeout: 20_000 },
		)
		await item.locator('.card-modal__step-btn[title="Assign step"]').click()
		await item.locator('.card-modal__assign-option').filter({ hasText: me }).first().click()
		await assignRes

		// The assignee avatar renders on the row.
		await expect(item.locator('.card-modal__step-assignee')).toBeVisible({ timeout: 10_000 })

		// The step now surfaces in the cross-board my-steps feed (open + assigned).
		const openSteps = await api.get('/my-steps')
		const mine = openSteps.find((s) => s.title === 'Send contract')
		if (!mine) throw new Error('assigned open step missing from /api/my-steps')
		if (mine.cardTitle !== 'Card With Steps') throw new Error('my-steps row lost its card context')

		// Set a PAST due date → the chip renders with overdue styling.
		await item.hover()
		const dueRes = page.waitForResponse(
			(res) => /\/api\/checklist\/\d+\/due/.test(res.url())
				&& res.request().method() === 'PUT' && res.status() < 400,
			{ timeout: 20_000 },
		)
		await item.locator('.card-modal__step-btn[title="Set step due date"]').click()
		await item.locator('.card-modal__date-input').fill('2020-01-01T09:00')
		await dueRes
		await expect(item.locator('.card-modal__step-due')).toBeVisible({ timeout: 10_000 })
		await expect(item.locator('.card-modal__step-due--overdue')).toBeVisible({ timeout: 10_000 })

		// Complete the step → done_at stamps server-side and the overdue accent
		// is suppressed on the done row.
		await toggleChecklistItem(page, 'Send contract')
		await expect(item.locator('.card-modal__step-due--overdue')).toHaveCount(0, { timeout: 10_000 })

		const items = await api.get(`/cards/${state.cardId}/checklist`)
		const step = items.find((i) => i.title === 'Send contract')
		if (!step) throw new Error('step missing from checklist payload')
		if (step.assignedUser !== me) throw new Error(`assignedUser not persisted: ${step.assignedUser}`)
		if (!step.assignedRole) throw new Error('assignedRole was not frozen at assign time')
		if (!step.assignedAt) throw new Error('assignedAt was not stamped')
		if (!step.dueDate || !step.dueDate.startsWith('2020-01-01')) throw new Error(`dueDate not persisted: ${step.dueDate}`)
		if (!step.doneAt || step.doneAt <= 0) throw new Error('done toggle did not stamp done_at')

		// A DONE step leaves the my-steps feed (it lists OPEN steps only).
		const stepsAfterDone = await api.get('/my-steps')
		if (stepsAfterDone.some((s) => s.title === 'Send contract')) {
			throw new Error('completed step still listed in /api/my-steps')
		}

		// Un-done clears the stamp again (done stays the source of truth).
		const checkbox = item.locator('.card-modal__checklist-checkbox')
		const patch = waitForChecklistPatch(page)
		await checkbox.click()
		await patch
		const reopened = (await api.get(`/cards/${state.cardId}/checklist`)).find((i) => i.title === 'Send contract')
		if (reopened.doneAt !== null) throw new Error('un-done did not clear done_at')
		if (reopened.assignedUser !== me) throw new Error('un-done must not touch the assignee')
	})
})
