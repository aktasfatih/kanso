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
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		// Open the card modal by clicking the card tile
		const cardTile = page.locator('.card-tile').filter({ hasText: 'Card With Checklist' })
		await expect(cardTile).toBeVisible()
		await cardTile.click()

		// Wait for the card modal to open
		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		// Checklist section should be visible
		const checklistSection = page.locator('.card-modal__checklist')
		await expect(checklistSection).toBeVisible()

		// Add first item "Buy groceries" via the add input
		const addInput = page.locator('.card-modal__checklist-add-input')
		await addInput.fill('Buy groceries')
		await addInput.press('Enter')

		// Wait for the item to appear in the list
		await expect(page.locator('.card-modal__checklist-item').filter({ hasText: 'Buy groceries' }))
			.toBeVisible()

		// Add second item "Write tests"
		await addInput.fill('Write tests')
		await addInput.press('Enter')

		await expect(page.locator('.card-modal__checklist-item').filter({ hasText: 'Write tests' }))
			.toBeVisible()

		// Assert progress shows 0/2 initially
		await expect(page.locator('.card-modal__checklist-count'))
			.toHaveText('0 / 2')

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
		).toHaveText(/1\/2/)

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
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		// Tile badge should still show 1/2 after reload
		const tileAfterReload = page.locator('.card-tile').filter({ hasText: 'Card With Checklist' })
		await expect(tileAfterReload.locator('.card-tile__checklist'))
			.toHaveText(/1\/2/, { timeout: 15_000 })

		// Open the card again and verify modal progress is also 1/2
		await tileAfterReload.click()
		await page.waitForSelector('.card-modal', { timeout: 15_000 })
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

	// The two items, and the first one's done flag, are created through the UI by
	// the test above — which is what THAT test proves, so seeding them in
	// beforeAll would make it vacuous. A retry re-runs only the failing test on a
	// fresh worker, so this one would meet an empty checklist and fail on every
	// attempt. Ensure, don't add: only the missing item is created, so a normal
	// full run still has exactly two and the 2/2 assertion stays meaningful.
	async function ensureTwoItemsOneDone() {
		const items = await api.get(`/cards/${state.cardId}/checklist`)
		for (const title of ['Buy groceries', 'Write tests']) {
			if (items.some((i) => i.title === title)) continue
			items.push(await api.post(`/cards/${state.cardId}/checklist`, { title }))
		}
		const first = items.find((i) => i.title === 'Buy groceries')
		if (!first.done) await api.patch(`/checklist/${first.id}`, { done: true })
	}

	test('complete all items - badge turns success color, progress bar turns green', async ({ page }) => {
		await ensureTwoItemsOneDone()

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		// Open card modal
		const cardTile = page.locator('.card-tile').filter({ hasText: 'Card With Checklist' })
		await cardTile.click()
		await page.waitForSelector('.card-modal', { timeout: 15_000 })

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
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		const cardTile = page.locator('.card-tile').filter({ hasText: 'Card With Steps' })
		await cardTile.click()
		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		// Add the step.
		const addInput = page.locator('.card-modal__checklist-add-input')
		await addInput.fill('Send contract')
		await addInput.press('Enter')
		const item = page.locator('.card-modal__checklist-item').filter({ hasText: 'Send contract' })
		await expect(item).toBeVisible()
		// The row renders from the optimistic create with a NEGATIVE placeholder id
		// and is not addressable on the server until the POST resolves — the app
		// keeps the step pickers disabled for that window (asserted by the test
		// below). Wait for the real id so a slow create surfaces here as a legible
		// failure instead of a 30s actionability timeout on the click at :270 —
		// same precondition toggleChecklistItem() uses.
		await expect(item).toHaveAttribute('data-item-id', /^\d+$/, { timeout: 15_000 })

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
		await expect(item.locator('.card-modal__step-assignee')).toBeVisible()

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
		await expect(item.locator('.card-modal__step-due')).toBeVisible()
		await expect(item.locator('.card-modal__step-due--overdue')).toBeVisible()

		// …and the signal reaches the BOARD (#10696): the tile's existing checklist
		// badge tints, so a card carrying a late step stops looking identical to one
		// that is on track. The board is loaded FRESH first, so every optimistic cache
		// patch is gone and the tint can only come from the server's own board
		// summary — which is what makes this a test of the overdue aggregate and
		// not of the client-side guess.
		const stepsTile = page.locator('.card-tile').filter({ hasText: 'Card With Steps' })
		// Route back to the BOARD url first (the open card owns the route, so a bare
		// reload would reopen the modal over the board), THEN reload: a goto that
		// only changes the fragment is a same-document navigation and would leave
		// the query cache — and with it the optimistic patch — fully intact.
		await page.goto(state.boardUrl)
		await page.reload()
		await page.waitForSelector('.card-tile', { timeout: 15_000 })
		await expect(stepsTile.locator('.card-tile__checklist--overdue')).toBeVisible({ timeout: 15_000 })

		// …and the hover preview agrees with the tile it floats over (#10708). It is
		// a SECOND consumer of the same summary field and used to draw a neutral,
		// on-track badge over a red one. Asserted on the preview component itself -
		// the tile assertion above cannot see it.
		await stepsTile.hover()
		await expect(page.locator('.card-tile:hover')).toHaveCount(1)
		await page.keyboard.press('Space')
		const preview = page.locator('.card-preview')
		await expect(preview).toBeVisible({ timeout: 15_000 })
		await expect(preview.locator('.card-preview__checklist--overdue')).toBeVisible({ timeout: 15_000 })
		await page.keyboard.press('Escape')
		await expect(preview).not.toBeVisible({ timeout: 15_000 })

		// Reopen the card for the rest of the flow.
		await stepsTile.click()
		await page.waitForSelector('.card-modal', { timeout: 15_000 })
		await expect(item).toBeVisible()

		// Complete the step → done_at stamps server-side and the overdue accent
		// is suppressed on the done row.
		await toggleChecklistItem(page, 'Send contract')
		await expect(item.locator('.card-modal__step-due--overdue')).toHaveCount(0, { timeout: 10_000 })

		// The tile drops the tint with it - a DONE step is never late work, and the
		// count it is derived from only ever sees open steps. Loaded fresh again, so
		// this too is the server's answer.
		await page.goto(state.boardUrl)
		await page.reload()
		await page.waitForSelector('.card-tile', { timeout: 15_000 })
		await expect(stepsTile.locator('.card-tile__checklist')).toBeVisible({ timeout: 15_000 })
		await expect(stepsTile.locator('.card-tile__checklist--overdue')).toHaveCount(0, { timeout: 15_000 })

		// The preview drops it with the tile - still the same one state, not two.
		await stepsTile.hover()
		await expect(page.locator('.card-tile:hover')).toHaveCount(1)
		await page.keyboard.press('Space')
		await expect(preview).toBeVisible({ timeout: 15_000 })
		await expect(preview.locator('.card-preview__checklist')).toBeVisible({ timeout: 15_000 })
		await expect(preview.locator('.card-preview__checklist--overdue')).toHaveCount(0, { timeout: 15_000 })
		await page.keyboard.press('Escape')
		await expect(preview).not.toBeVisible({ timeout: 15_000 })

		await stepsTile.click()
		await page.waitForSelector('.card-modal', { timeout: 15_000 })
		await expect(item).toBeVisible()

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

	// A just-added step renders from the optimistic create carrying a NEGATIVE
	// placeholder id and is not addressable on the server yet. Acting on it in that
	// window used to fire requests at that placeholder — `POST /api/checklist/
	// -1788…/assign` → 404 → a bare "Not found" under the checklist and a silently
	// dropped assignment; `DELETE /api/checklist/-1788…` → 404 → the optimistic
	// removal rolled back WHILE the create was still in flight, so the step the user
	// just deleted CAME BACK; `PATCH /api/checklist/-1788…` → 404 "Failed to rename
	// item.", and an id swap landing mid-edit tore the input down without a blur and
	// threw the typed title away; `POST /api/checklist/-1788…/move` → 404 dragging
	// the row, or 400 "afterItemId is not an item of this card" dropping onto it.
	// On a slow connection the window is wide enough for a real user to hit. The
	// create POST is delayed here so the window is a deterministic 3s to act in.
	//
	// EVERY assertion below is on the control's DISABLED STATE, never on "driving
	// the UI works out". Playwright's actionability wait absorbs the latency: a
	// spec that simply clicks passes whether or not the guard exists, because the
	// click lands after the real id arrives. Removing any one guard must turn this
	// test red — the forced clicks and the dispatched drag are what prove that.
	test('the step controls stay inert until the just-added step has its server id', async ({ page }) => {
		// Anything addressed to a negative id can only 404 — nothing may be sent.
		// A move addressed to a real id may still carry a placeholder `afterItemId`,
		// which the server rejects with a 400, so reorders are tracked too.
		const placeholderCalls = []
		const moveCalls = []
		page.on('request', (req) => {
			if (/\/api\/checklist\/-\d+/.test(req.url())) placeholderCalls.push(`${req.method()} ${req.url()}`)
			if (/\/api\/checklist\/-?\d+\/move/.test(req.url())) moveCalls.push(`${req.method()} ${req.url()}`)
		})

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })
		await page.locator('.card-tile').filter({ hasText: 'Card With Steps' }).click()
		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		// A settled row to drag against — added BEFORE the latency is injected.
		const addInput = page.locator('.card-modal__checklist-add-input')
		await addInput.fill('Anchor step')
		await addInput.press('Enter')
		// `.last()`: a retry re-adds the row on the same card, and the newest row is
		// always appended last, so the locator still resolves to exactly one row.
		const anchor = page.locator('.card-modal__checklist-item').filter({ hasText: 'Anchor step' }).last()
		await expect(anchor).toHaveAttribute('data-item-id', /^\d+$/, { timeout: 15_000 })
		const anchorId = await anchor.getAttribute('data-item-id')

		// The create is held on a GATE, not a timer: the window has to stay open
		// across every control below, and a fixed delay races the CI runner (~4-5×
		// slower than a dev box, see playwright.config.js) — a control exercised
		// after the id landed proves nothing. The test decides when it closes.
		let releaseCreate
		const createGate = new Promise((resolve) => { releaseCreate = resolve })
		await page.route('**/api/cards/*/checklist', async (route) => {
			if (route.request().method() === 'POST') await createGate
			await route.continue()
		})

		await addInput.fill('Deferred step')
		await addInput.press('Enter')

		const item = page.locator('.card-modal__checklist-item').filter({ hasText: 'Deferred step' }).last()
		await expect(item).toBeVisible()
		await expect(item).toHaveAttribute('data-item-id', /^-\d+$/)
		const placeholderId = await item.getAttribute('data-item-id')

		const assignBtn = item.locator('.card-modal__step-btn[title="Assign step"]')
		const dueBtn = item.locator('.card-modal__step-btn[title="Set step due date"]')
		const deleteBtn = item.locator('.card-modal__checklist-item-delete')
		const itemTitle = item.locator('.card-modal__checklist-item-title')
		const dragHandle = item.locator('.card-modal__checklist-drag')

		await expect(assignBtn).toBeDisabled()
		await expect(dueBtn).toBeDisabled()
		await expect(deleteBtn).toBeDisabled()
		// The title is a role=button span, so it carries aria-disabled rather than
		// the disabled property — toBeDisabled() honours both.
		await expect(itemTitle).toBeDisabled()
		await expect(dragHandle).toHaveAttribute('draggable', 'false')
		// …and the row says so, rather than just going quietly dead under the cursor.
		await expect(item).toHaveAttribute('aria-busy', 'true')

		// Every forced interaction below is followed by this. It is the assertion
		// that actually bites: the *symptoms* are unreliable detectors here — a
		// local server 404s the placeholder in milliseconds, so the rolled-back
		// optimistic delete puts the row back before any DOM assertion can see it
		// gone. What is deterministic is that the request was sent at all.
		const assertNoPlaceholderTraffic = async (label) => {
			await page.waitForTimeout(300)
			const sent = [...placeholderCalls, ...moveCalls]
			if (sent.length > 0) {
				throw new Error(`${label}: request(s) addressed the optimistic placeholder id: ${sent.join(', ')}`)
			}
		}

		// Chromium never delivers a click to a NATIVELY disabled button, so
		// `click({ force: true })` on one exercises nothing — it would leave the
		// handler-side guard (and this assertion) unfalsifiable. A programmatically
		// dispatched click IS delivered to the listener, so both are used: the
		// forced click for the elements that carry aria-disabled (where the click
		// does land), the dispatch for the ones with the disabled property.
		const dispatchClick = (selector) => page.evaluate(([rowId, sel]) => {
			const el = document.querySelector(`li[data-item-id="${rowId}"] ${sel}`)
			if (!el) throw new Error(`no element for ${sel}`)
			el.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
		}, [placeholderId, selector])

		// Forced past the disabled state, the picker still must not open — no
		// popover means no request can be addressed to the placeholder id.
		await assignBtn.click({ force: true })
		await dispatchClick('.card-modal__step-btn[title="Assign step"]')
		await assertNoPlaceholderTraffic('assign')
		await expect(item.locator('.card-modal__step-popover')).toHaveCount(0)

		// Forced past it, delete must not fire — a 404'd delete rolls its optimistic
		// removal back while the create is still in flight, so the step the user
		// deleted comes back. The row-still-present check backs the traffic check up
		// for a slow server, where the gap is actually visible.
		await deleteBtn.click({ force: true })
		await dispatchClick('.card-modal__checklist-item-delete')
		await assertNoPlaceholderTraffic('delete')
		await expect(item).toHaveCount(1)

		// Forced past it, the inline title editor must not open — an editor here
		// both PATCHes the placeholder id and loses the typed draft when the id
		// swaps out from under it. Scoped to the page, not to `item`: an open editor
		// replaces the row's title text, so the hasText filter would stop matching
		// and a row-scoped locator would be vacuously empty.
		await itemTitle.click({ force: true })
		await assertNoPlaceholderTraffic('rename')
		await expect(page.locator('.card-modal__checklist-item-input')).toHaveCount(0)

		// Reorder, both roles. Playwright's dragTo drives mouse events, which native
		// HTML5 DnD ignores, so the drag events are dispatched directly — that also
		// forces the handle's draggable=false, exactly like the forced clicks above.
		//
		// `dragover` and `drop` are TWO calls on purpose (#10292). onItemDrop clears
		// `dragOverItemId` on its early-return branch as well as its success branch,
		// so a `data-drag-over` assertion taken after the drop reads 'false' whether
		// onItemDragOver's guard exists or not — the previous single-shot helper made
		// that assertion unfalsifiable. The drop indicator is only observable in the
		// window BETWEEN the two events, so the DataTransfer (and the pointer offsets
		// derived from the target row) are parked on `window` to survive the gap.
		const dispatchDragOver = (fromId, toId) => page.evaluate(([from, to]) => {
			const dt = new DataTransfer()
			const fromRow = document.querySelector(`li[data-item-id="${from}"]`)
			const toRow = document.querySelector(`li[data-item-id="${to}"]`)
			if (!fromRow || !toRow) throw new Error(`drag rows missing: ${from} → ${to}`)
			fromRow.querySelector('.card-modal__checklist-drag')
				.dispatchEvent(new DragEvent('dragstart', { dataTransfer: dt, bubbles: true, cancelable: true }))
			const rect = toRow.getBoundingClientRect()
			const opts = {
				dataTransfer: dt,
				bubbles: true,
				cancelable: true,
				clientX: rect.left + 5,
				// Bottom half → "insert after the target", i.e. afterItemId = target id.
				clientY: rect.top + rect.height * 0.75,
			}
			// `opts` already carries the DataTransfer, so the drop below needs nothing
			// but this object — see the note there about why it has to be reused.
			window.__kansoDrag = opts
			toRow.dispatchEvent(new DragEvent('dragover', opts))
		}, [fromId, toId])

		// No wait between the two halves on purpose. Nothing clears the indicator in
		// the gap, so the assertion there has all of its own timeout to observe a
		// missing guard — Playwright's toHaveAttribute retries. A requestAnimationFrame
		// await here would buy nothing and could hang the evaluate if the renderer is
		// ever throttled.
		const dispatchDrop = (toId) => page.evaluate((to) => {
			const toRow = document.querySelector(`li[data-item-id="${to}"]`)
			if (!toRow) throw new Error(`drop row missing: ${to}`)
			// The SAME DragEvent init as the dragover, DataTransfer included: a drop
			// carrying a fresh DataTransfer is a different drag as far as the handler is
			// concerned, and the clientY is what selects the closest-edge branch.
			const opts = window.__kansoDrag
			if (!opts) throw new Error('dispatchDrop called without a preceding dispatchDragOver')
			toRow.dispatchEvent(new DragEvent('drop', opts))
		}, toId)

		// The unsaved row DRAGGED: dragstart is preventDefault()ed, so nothing is ever
		// dragging — asserted, not just claimed, in the same gap: with no drag in
		// progress the ANCHOR must not light up as a drop target either.
		await dispatchDragOver(placeholderId, anchorId)
		await expect(anchor).toHaveAttribute('data-drag-over', 'false')
		await dispatchDrop(anchorId)
		await assertNoPlaceholderTraffic('reorder (unsaved row dragged)')

		// The unsaved row as the DROP TARGET — a real row is dragging, so this is the
		// case where onItemDragOver actually has to refuse. Asserted in the gap: it
		// must never advertise a drop it could not send.
		await dispatchDragOver(anchorId, placeholderId)
		await expect(item).toHaveAttribute('data-drag-over', 'false')
		await dispatchDrop(placeholderId)
		await assertNoPlaceholderTraffic('reorder (unsaved row as drop target)')

		// Once the create resolves and the real row swaps in, every control works.
		releaseCreate()
		await expect(item).toHaveAttribute('data-item-id', /^\d+$/, { timeout: 15_000 })
		await expect(assignBtn).toBeEnabled()
		await expect(dueBtn).toBeEnabled()
		await expect(deleteBtn).toBeEnabled()
		await expect(itemTitle).toBeEnabled()
		await expect(dragHandle).toHaveAttribute('draggable', 'true')
		await expect(item).not.toHaveAttribute('aria-busy', 'true')
		await assignBtn.click()
		await expect(item.locator('.card-modal__step-popover')).toBeVisible()

		await expect(page.locator('.card-modal__save-error')).toHaveCount(0)
		if (placeholderCalls.length > 0) {
			throw new Error(`request(s) sent against the optimistic placeholder id: ${placeholderCalls.join(', ')}`)
		}
	})
})

// List view is the third per-card consumer of the summary's overdue-step count
// (#10733), and the one the previous two patches could not be copied onto: the
// row renders ONE merged counter — checklist progress, falling back to sub-card
// progress — so it had no source label and no tint at all.
//
// A SEPARATE describe on purpose. The tile (#10696) and hover-preview (#10708)
// assertions both live inside the single "assign a step…" test above, and an
// assertion appended there would ride along on that test's fixture and prove
// nothing about the row. Everything here is seeded over the API before the page
// is ever opened, so the tint can only come from the server's board summary —
// there is no optimistic cache patch in the picture at all.
test.describe('Checklist steps - list view counter (#10733)', () => {
	const stamp = Math.floor(Date.now() / 1000)
	const state = { boardId: 0, boardUrl: '', lateId: 0, onTrackId: 0, doneId: 0, noChecklistBoardId: 0, noChecklistUrl: '', hiddenId: 0 }

	// A step due in the far past is overdue for good; one due in 2099 never is.
	const PAST = '2020-01-01T09:00:00+00:00'
	const FUTURE = '2099-01-01T09:00:00+00:00'

	// Board rows render in whichever view mode the user last chose - BoardView
	// persists it in localStorage under this key. Seeding it opens the board
	// straight in List view, which keeps the test off the display-mode popover
	// (and survives the reload) without any test-only hook in the app.
	async function openList(page, boardId, url) {
		await page.addInitScript(([key]) => {
			try { localStorage.setItem(key, 'list') } catch (e) { /* private mode */ }
		}, [`kanso.viewMode.${boardId}`])
		await ncLogin(page)
		await page.goto(url)
		await page.waitForSelector('.board-list-row', { timeout: 15_000 })
	}

	// The row's single progress counter. The fixture cards carry no comments, so
	// the shared `__count` class resolves to exactly one element - asserted, so a
	// second badge appearing here can never silently absorb the assertions below.
	// The count also assumes every fixture row is inside the virtual window: at
	// three or four rows against an overscan of 10 that holds, but a describe
	// grown past that would need the row scrolled into view first.
	async function counterOf(page, rowText) {
		const counter = page.locator('.board-list-row', { hasText: rowText }).locator('.board-list-row__count')
		await expect(counter).toHaveCount(1, { timeout: 15_000 })
		return counter
	}

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Checklist List Overdue ' + stamp })
		state.boardId = board.id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })

		// 1. Two open steps, one of them past due → checklist counter 0/2, tinted.
		const late = await api.post('/cards', { stackId: stack.id, title: 'Late step row' })
		state.lateId = late.id
		const lateStep = await api.post(`/cards/${late.id}/checklist`, { title: 'Send contract' })
		await api.put(`/checklist/${lateStep.id}/due`, { due: PAST })
		await api.post(`/cards/${late.id}/checklist`, { title: 'Countersign' })

		// 2. The control: a checklist whose only step is due years from now.
		const onTrack = await api.post('/cards', { stackId: stack.id, title: 'On track step row' })
		state.onTrackId = onTrack.id
		const soon = await api.post(`/cards/${onTrack.id}/checklist`, { title: 'Renew licence' })
		await api.put(`/checklist/${soon.id}/due`, { due: FUTURE })

		// 3. The same late step on a card that is DONE. The server counts open
		//    past-due steps regardless of the card's own state, so the summary
		//    still reports overdue ≥ 1 here (asserted below) - suppressing the
		//    tint is the client's job, exactly as on the tile. The title shares no
		//    substring with the row above it: Playwright's `hasText` is a
		//    case-insensitive SUBSTRING match, so "Done late step row" would have
		//    made every `Late step row` locator resolve to two rows.
		const doneCard = await api.post('/cards', { stackId: stack.id, title: 'Finished card row' })
		state.doneId = doneCard.id
		const doneStep = await api.post(`/cards/${doneCard.id}/checklist`, { title: 'File receipt' })
		await api.put(`/checklist/${doneStep.id}/due`, { due: PAST })
		await api.patch(`/cards/${doneCard.id}`, { done: true })

		// A SECOND board with the checklist section switched off (#5894). There the
		// merged counter falls through to sub-card progress while the summary still
		// carries the overdue count - the only shape in which the row can be asked
		// to tint something that is not checklist progress.
		const other = await api.post('/boards', { title: 'Checklist List Hidden ' + stamp })
		state.noChecklistBoardId = other.id
		state.noChecklistUrl = `${BASE}/index.php/apps/kanso#/board/${other.id}`
		await api.patch(`/boards/${other.id}`, { cardFeatures: { checklist: false } })
		const otherStack = await api.post('/stacks', { boardId: other.id, title: 'To do' })
		const hidden = await api.post('/cards', { stackId: otherStack.id, title: 'Steps hidden row' })
		state.hiddenId = hidden.id
		const hiddenStep = await api.post(`/cards/${hidden.id}/checklist`, { title: 'Send contract' })
		await api.put(`/checklist/${hiddenStep.id}/due`, { due: PAST })
		// Two sub-cards, one done → child progress reads 1/2, a different number
		// from the hidden checklist's 0/1, so the counter's SOURCE is legible from
		// its text alone.
		for (const title of ['Hidden sub one', 'Hidden sub two']) {
			const child = await api.post('/cards', { stackId: otherStack.id, title })
			await api.put(`/cards/${child.id}/parent`, { parentCardId: hidden.id })
		}
		const children = (await api.get(`/boards/${other.id}`)).cards.filter((c) => c.parentCardId === hidden.id)
		await api.patch(`/cards/${children[0].id}`, { done: true })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
		if (state.noChecklistBoardId) await api.delete(`/boards/${state.noChecklistBoardId}`).catch(() => {})
	})

	test('the list row tints its checklist counter for an overdue step and names the count', async ({ page }) => {
		// Preconditions straight from the server summary the row reads: without
		// overdue ≥ 1 on the late AND the done card, the assertions below could
		// pass on a row that simply has nothing to tint.
		const summary = (await api.get(`/boards/${state.boardId}`)).cards
		const byId = (id) => summary.find((c) => c.id === id)
		expect(byId(state.lateId).checklist).toEqual({ total: 2, done: 0, overdue: 1 })
		expect(byId(state.onTrackId).checklist).toEqual({ total: 1, done: 0, overdue: 0 })
		expect(byId(state.doneId).checklist).toEqual({ total: 1, done: 0, overdue: 1 })
		expect(Number(byId(state.doneId).doneAt)).toBeGreaterThan(0)

		await openList(page, state.boardId, state.boardUrl)

		// The late row tints, and says why: the tint alone would be a colour-only
		// cue, so the count travels in the accessible name the tile already uses.
		const lateCounter = await counterOf(page, 'Late step row')
		await expect(lateCounter).toHaveText(/0\/2/)
		await expect(lateCounter).toHaveClass(/board-list-row__count--overdue/)
		await expect(lateCounter).toHaveAttribute('aria-label', /1 overdue step/)
		await expect(lateCounter).toHaveAttribute('title', /1 overdue step/)

		// The on-track row keeps the neutral counter - the tint is conditional on
		// the overdue count, not on there being a checklist.
		const onTrackCounter = await counterOf(page, 'On track step row')
		await expect(onTrackCounter).toHaveText(/0\/1/)
		await expect(onTrackCounter).not.toHaveClass(/board-list-row__count--overdue/)
		await expect(onTrackCounter).toHaveAttribute('aria-label', 'Checklist progress')

		// A DONE card does not nag, even though its step is still open and past due
		// (asserted above) - same suppression the tile and the preview apply.
		const doneCounter = await counterOf(page, 'Finished card row')
		await expect(doneCounter).toHaveText(/0\/1/)
		await expect(doneCounter).not.toHaveClass(/board-list-row__count--overdue/)
		await expect(doneCounter).toHaveAttribute('aria-label', 'Checklist progress')
	})

	test('a sub-card counter is never tinted by an overdue checklist step', async ({ page }) => {
		// The row is showing SUB-CARD progress while the card underneath still has
		// an overdue step - so a tint here would be labelling the wrong feature.
		const summary = (await api.get(`/boards/${state.noChecklistBoardId}`)).cards
		const hidden = summary.find((c) => c.id === state.hiddenId)
		expect(hidden.checklist).toEqual({ total: 1, done: 0, overdue: 1 })
		expect(hidden.childProgress).toEqual({ total: 2, done: 1 })

		await openList(page, state.noChecklistBoardId, state.noChecklistUrl)

		const counter = await counterOf(page, 'Steps hidden row')
		// 1/2 is the sub-card count; the hidden checklist reads 0/1, so the text
		// alone proves which source the counter came from.
		await expect(counter).toHaveText(/1\/2/)
		await expect(counter).not.toHaveClass(/board-list-row__count--overdue/)
		await expect(counter).toHaveAttribute('aria-label', 'Sub-card progress')
	})
})
