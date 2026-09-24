// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// Every 20s budget below covers a server round-trip: creating a sub-card, then
// the parent's progress recomputing from it. They were 8s, which is inside the
// range a saturated CI runner reaches for a create (#10332 saw this test fail on
// the create at :41 with the board already loaded — a budget problem, not a
// missing wait), and 8s buys nothing that 20s does not: an auto-retrying
// assertion only spends its budget when it is already failing.
test.describe('Parent / Child cards', () => {
	// Unique board title to avoid collisions with parallel test runs
	const BOARD_TITLE = 'Parent Child Test Board ' + Date.now()
	const state = {
		boardId: 0,
		stackId: 0,
		parentCardId: 0,
		boardUrl: '',
	}

	test.beforeAll(async () => {
		// Tear down any prior board with the same title prefix for safety
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title.startsWith('Parent Child Test Board')) {
				await api.delete(`/boards/${b.id}`)
			}
		}

		// Create fresh board + stack + parent card
		const board = await api.post('/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id
		const parentCard = await api.post('/cards', { stackId: stack.id, title: 'Parent Card' })
		state.parentCardId = parentCard.id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await api.delete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	// The sub-cards below are created through the UI by the first test — which is
	// exactly what that test exists to prove, so they cannot be seeded in
	// beforeAll without making it vacuous. A retry, though, re-runs ONLY the
	// failing test: beforeAll hands it a pristine, CHILDLESS parent and the test
	// that populated it never runs, so every later test here fails deterministically
	// on every attempt. Each of them therefore asserts its own precondition into
	// place first, creating only what is MISSING — on a normal full run the
	// children are already there and nothing is added, so the counts stay 2.
	async function fetchChildren() {
		return (await api.get(`/cards/${state.parentCardId}`)).children ?? []
	}

	async function ensureTwoChildren() {
		const children = await fetchChildren()
		let created = false
		for (const title of ['Sub-task Alpha', 'Sub-task Beta']) {
			if (children.some((c) => c.title === title)) continue
			const child = await api.post('/cards', { stackId: state.stackId, title })
			await api.put(`/cards/${child.id}/parent`, { parentCardId: state.parentCardId })
			created = true
		}
		return created ? await fetchChildren() : children
	}

	// …and the 1/2 progress the tests below read comes from the toggle test, so
	// the done flag has to be ensured the same way.
	async function ensureOneChildDone() {
		const children = await ensureTwoChildren()
		if (children.some((c) => Number(c.doneAt) > 0)) return children
		await api.patch(`/cards/${children[0].id}`, { done: true })
		return await fetchChildren()
	}

	test('add two sub-cards via UI, assert Children section shows 2 items and progress 0/2', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		// Open the parent card modal
		const parentTile = page.locator('.card-tile').filter({ hasText: 'Parent Card' })
		await expect(parentTile).toBeVisible()
		await parentTile.click()

		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		// The add sub-card input should be present (this card has no parent)
		const addChildInput = page.getByPlaceholder('Add a sub-card…')
		await expect(addChildInput).toBeVisible()

		// Add first sub-card "Sub-task Alpha"
		await addChildInput.fill('Sub-task Alpha')
		await addChildInput.press('Enter')

		// Wait for the child to appear in the list
		await expect(page.locator('.card-modal__child').filter({ hasText: 'Sub-task Alpha' }))
			.toBeVisible({ timeout: 20_000 })

		// Add second sub-card "Sub-task Beta"
		await addChildInput.fill('Sub-task Beta')
		await addChildInput.press('Enter')

		await expect(page.locator('.card-modal__child').filter({ hasText: 'Sub-task Beta' }))
			.toBeVisible({ timeout: 20_000 })

		// Assert Children section shows 2 items
		await expect(page.locator('.card-modal__child')).toHaveCount(2)

		// Assert progress shows 0 / 2
		await expect(page.locator('.card-modal__section-count'))
			.toHaveText('0 / 2')
	})

	test('toggle one child done via API, reload parent modal, assert progress 1/2', async ({ page }) => {
		// Self-sufficient on a retry: the sub-cards come from the UI test above.
		const children = await ensureTwoChildren()
		expect(children.length).toBe(2)

		await ncLogin(page)

		// Mark the first child done via the API (set done: true)
		const firstChild = children[0]
		await api.patch(`/cards/${firstChild.id}`, { done: true })

		// Open the board and the parent card modal
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		const parentTile = page.locator('.card-tile').filter({ hasText: 'Parent Card' })
		await expect(parentTile).toBeVisible()
		await parentTile.click()
		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		// Progress should now show 1 / 2
		await expect(page.locator('.card-modal__section-count'))
			.toHaveText('1 / 2', { timeout: 20_000 })

		// The done child should have its done indicator active
		const doneChildItem = page.locator('.card-modal__child').filter({ hasText: firstChild.title })
		await expect(doneChildItem.locator('.card-modal__child-dot--done'))
			.toBeVisible()

		// Close the modal
		await page.keyboard.press('Escape')
		await page.waitForSelector('.card-modal', { state: 'hidden', timeout: 5000 }).catch(() => {})

		// The parent tile should now show a child-progress badge with 1/2
		await expect(
			page.locator('.card-tile').filter({ hasText: 'Parent Card' })
				.locator('.card-tile__children'),
		).toHaveText(/1\/2/, { timeout: 20_000 })
	})

	test('reload board and assert parent tile persists child badge 1/2', async ({ page }) => {
		// Self-sufficient on a retry: both the sub-cards and the done flag that
		// makes this 1/2 are set by the two tests above.
		await ensureOneChildDone()

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		// Tile badge should show 1/2 after fresh load
		const parentTile = page.locator('.card-tile').filter({ hasText: 'Parent Card' })
		await expect(parentTile.locator('.card-tile__children'))
			.toHaveText(/1\/2/, { timeout: 20_000 })

		// Open and re-verify modal progress
		await parentTile.click()
		await page.waitForSelector('.card-modal', { timeout: 15_000 })
		await expect(page.locator('.card-modal__section-count'))
			.toHaveText('1 / 2', { timeout: 20_000 })
		await expect(page.locator('.card-modal__child')).toHaveCount(2)
	})

	test('open a child card from parent modal - child shows its Parent row', async ({ page }) => {
		// Self-sufficient on a retry: there is no child link to click otherwise.
		await ensureTwoChildren()

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		// Open parent modal
		const parentTile = page.locator('.card-tile').filter({ hasText: 'Parent Card' })
		await parentTile.click()
		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		// Click the first child link
		const firstChildLink = page.locator('.card-modal__child-link').first()
		await expect(firstChildLink).toBeVisible()
		await firstChildLink.click()

		// Wait for the child card modal to open (URL changes to child cardId)
		await page.waitForFunction(
			() => window.location.hash.includes('/card/'),
			{ timeout: 20_000 },
		)

		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		// The child card modal should show the "Parent card" section (not Sub-cards)
		const parentSection = page.locator('.card-modal__parent-link')
		await expect(parentSection).toBeVisible({ timeout: 20_000 })
		await expect(parentSection).toHaveText('Parent Card')

		// The "Add sub-card" input should NOT be present (one-level rule: a card
		// with a parent shows the Parent section instead of the Sub-cards editor).
		await expect(page.getByPlaceholder('Add a sub-card…')).toHaveCount(0)
	})

	test('detach a child - parent progress drops to 0/1', async ({ page }) => {
		// Self-sufficient on a retry: this needs both children AND the one done
		// flag, so that removing the undone one leaves 1 / 1.
		const children = await ensureOneChildDone()
		// Find the child that is NOT done
		const undoneChild = children.find((c) => Number(c.doneAt) === 0)
		expect(undoneChild).toBeTruthy()

		await ncLogin(page)

		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		// Open the parent card modal
		const parentTile = page.locator('.card-tile').filter({ hasText: 'Parent Card' })
		await parentTile.click()
		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		// Hover the undone child item to reveal the remove button, then click it
		const undoneChildItem = page.locator('.card-modal__child').filter({ hasText: undoneChild.title })
		await undoneChildItem.hover()
		const removeBtn = undoneChildItem.locator('.card-modal__child-remove')
		await expect(removeBtn).toBeVisible()
		await removeBtn.click()

		// Progress should now be 1 / 1 (only the done child remains). Since we
		// detached the undone one, 1 done out of 1 total → progress text "1 / 1".
		await expect(page.locator('.card-modal__section-count'))
			.toHaveText('1 / 1', { timeout: 20_000 })

		// The list should now have 1 item
		await expect(page.locator('.card-modal__child')).toHaveCount(1)
	})
})
