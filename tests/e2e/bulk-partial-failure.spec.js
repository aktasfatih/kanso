// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10473 — what happens when a bulk action fails PART-WAY THROUGH.
//
// A selection larger than BulkCardService::MAX_CARDS (100) is posted as several
// sequential /api/cards/bulk requests (#10435). If chunk N rejects, everything
// chunks 1..N-1 wrote is already committed server-side, and useBulkSelect
// deliberately does not throw that away: it attaches the merged summary to the
// rejected promise as `err.partial`. Both callers depend on it —
//
//   • runBulkAction (BoardView) turns it into the toast
//     "{ok} cards updated before the action failed", so "failed" does not read
//     as "nothing happened" over a selection that half moved.
//   • handleArchiveAllInStack RETURNS it, so StackColumn's undo toast still
//     covers the cards that really were archived. That undo is the entire
//     reason "Archive N cards" ships with no confirm dialog, and the Archived
//     page only restores one card at a time — so a partial failure that lost
//     the landed ids would strand them.
//
// Neither is reachable from the UI without fault injection (the server does not
// fail a valid chunk), hence page.route: chunk 1 goes through to the real
// backend and commits for real, chunk 2 is answered with a 500. The assertions
// are therefore on the COUNT and on the ids the undo actually carries — a test
// that only looked for "a toast appeared" would pass against a handler that
// discarded the partial progress, which is the exact regression this pins.

import { test, expect, api, ncLogin, toast, BASE } from './helpers.js'

/** One chunk boundary plus a remainder: 100 + 1 → exactly two requests. */
const CARD_COUNT = 101

/** Cards the first (committed) chunk covers. */
const FIRST_CHUNK = 100

/** The message the injected 500 carries, so the banner is provably ours. */
const INJECTED = 'Injected chunk failure'

/**
 * Let every /api/cards/bulk POST through except the nth, which is answered with
 * a 500. Counting is per-page and starts at the moment this is installed, so a
 * later request (e.g. the undo's unarchive) reaches the real backend.
 *
 * @param {import('@playwright/test').Page} page - the page under test
 * @param {number} n - 1-based index of the POST to reject
 * @return {Promise<void>}
 */
async function failNthBulkChunk(page, n) {
	let seen = 0
	await page.route('**/apps/kanso/api/cards/bulk', async (route) => {
		if (route.request().method() !== 'POST') {
			await route.continue()
			return
		}
		seen++
		if (seen === n) {
			await route.fulfill({
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify({ error: INJECTED }),
			})
			return
		}
		await route.continue()
	})
}

/**
 * Open the ⋯ NcActions menu of the first column and return the teleported panel
 * (NcActions renders its panel at <body>, not inside the column).
 *
 * @param {import('@playwright/test').Page} page - the page under test
 * @return {Promise<import('@playwright/test').Locator>} the menu dialog
 */
async function openColumnMenu(page) {
	await page.locator('.stack-column__actions button').first().click()
	const dialog = page.locator('[role="dialog"]').first()
	await expect(dialog).toBeVisible({ timeout: 6_000 })
	return dialog
}

/**
 * How many of the board's cards are currently archived.
 *
 * @param {number} boardId - the board to read
 * @return {Promise<number>} archived card count
 */
async function archivedCount(boardId) {
	const board = await api.get(`/boards/${boardId}`)
	return board.cards.filter((c) => c.archived).length
}

test.describe('A bulk action that fails mid-sequence keeps what already landed (#10473)', () => {
	const state = { boardId: 0, stackId: 0, boardUrl: '' }

	// A fresh board per test: both tests archive cards for real (only chunk 2 is
	// faked), so neither can be handed the other's leftovers.
	test.beforeEach(async () => {
		const board = await api.post('/boards', { title: `Bulk Partial E2E ${Date.now()}` })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Sprint' })
		state.stackId = stack.id
		// Sequential, like bulk-select-chunking.spec.js: each create appends after
		// the current last card, so this is the order the board renders them in and
		// the order the chunks are cut in.
		for (let i = 1; i <= CARD_COUNT; i++) {
			await api.post('/cards', { stackId: stack.id, title: `Partial Card ${String(i).padStart(3, '0')}` })
		}
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterEach(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
		state.boardId = 0
	})

	test('the selection bar reports how many landed before the failure', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 20_000 })

		// Select the whole column in one action (#10485) — the 101 cards include
		// ~90 the virtualizer has not mounted, which a shift-range would have to
		// scroll to first.
		const menu = await openColumnMenu(page)
		await menu.getByRole('button', { name: `Select ${CARD_COUNT} cards` }).click()
		await expect(page.locator('.bulk-action-bar'))
			.toContainText(`${CARD_COUNT} selected`, { timeout: 10_000 })

		await failNthBulkChunk(page, 2)

		await page.getByRole('button', { name: 'More actions' }).click()
		await page.getByRole('dialog', { name: 'More actions' })
			.getByRole('button', { name: 'Archive selected' }).click()

		// THE assertion: the partial count, not merely "a toast". 100 is what chunk
		// 1 committed; a handler that dropped `err.partial` would show no toast at
		// all, and one that reported the whole selection would say 101.
		await expect(toast(page, `${FIRST_CHUNK} cards updated before the action failed`))
			.toBeVisible({ timeout: 30_000 })
		await expect(toast(page, `${CARD_COUNT} cards updated before the action failed`))
			.toHaveCount(0)

		// The failure is still surfaced as a failure — the partial toast softens the
		// wording, it does not swallow the error.
		await expect(page.locator('.board-view__move-error')).toContainText(INJECTED, { timeout: 10_000 })

		// And the count is true of the server, not just of the toast: exactly the
		// first chunk is archived.
		await expect.poll(() => archivedCount(state.boardId), { timeout: 30_000 }).toBe(FIRST_CHUNK)

		// The selection survives a failed apply (apply() only clear()s on success),
		// so the user can retry without re-selecting 101 cards.
		await expect(page.locator('.bulk-action-bar')).toContainText(`${CARD_COUNT} selected`)
	})

	test('the archive-all undo carries exactly the ids that were archived', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 20_000 })

		await failNthBulkChunk(page, 2)

		const menu = await openColumnMenu(page)
		await menu.getByRole('button', { name: `Archive ${CARD_COUNT} cards` }).click()

		// The undo toast is offered even though the action FAILED, and its count is
		// the partial one. If handleArchiveAllInStack returned an empty summary on
		// failure, StackColumn would bail at `archivedIds.length === 0` and there
		// would be no undo at all — the 100 archived cards would then only be
		// recoverable one at a time from the Archived page.
		// @nextcloud/dialogs gives an undo toast a 10s life, so keep the budget
		// under that (see column-archive-all.spec.js).
		const undoToast = toast(page, `${FIRST_CHUNK} cards archived`)
		await expect(undoToast).toBeVisible({ timeout: 8_000 })

		// Sanity on the fault itself: 100 really are archived server-side, 1 is not.
		await expect.poll(() => archivedCount(state.boardId), { timeout: 30_000 }).toBe(FIRST_CHUNK)

		const undoBtn = undoToast.getByRole('button', { name: 'Undo' })
		await expect(undoBtn).toBeVisible({ timeout: 5_000 })
		await undoBtn.click()

		// THE assertion: the undo restores every card that landed. It can only get
		// to zero if the ids it was handed were the 100 from the committed chunk —
		// an empty or truncated `err.partial` leaves cards archived forever.
		await expect.poll(() => archivedCount(state.boardId), { timeout: 30_000 }).toBe(0)
		await expect
			.poll(async () => {
				const board = await api.get(`/boards/${state.boardId}`)
				return board.cards.filter((c) => c.stackId === state.stackId && !c.archived).length
			}, { timeout: 15_000 })
			.toBe(CARD_COUNT)
	})
})
