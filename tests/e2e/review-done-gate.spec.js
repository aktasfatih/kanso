// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10070 — the review gate, over the real API and a real database. A card whose
// requested reviews are not all approved must not reach the done state by ANY
// route, and must complete normally the moment they are.
//
// The first describe is API-level on purpose: the point is that four different
// write paths converge on one gate, and each path is one request. The board
// carries Review / Done / role-less columns so the two-hop laundering route is
// reachable exactly as a user would drag it.
//
// The second describe covers what the API level cannot: that the refusal is
// actually SHOWN to the person who pressed the button (#10896).

import { test, expect, api, me, BASE, ncLogin } from './helpers.js'

const ROLE_NONE = 0
const ROLE_REVIEW = 4
const ROLE_DONE = 5

async function doneAt(cardId) {
	return Number((await api.get(`/cards/${cardId}`)).doneAt)
}

test.describe('Review gate — every route into done', () => {
	const state = { boardId: 0, todoId: 0, reviewId: 0, limboId: 0, doneId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: `Review Gate E2E ${Date.now()}` })
		state.boardId = board.id
		const mk = async (title, role) => {
			const s = await api.post('/stacks', { boardId: board.id, title })
			await api.patch(`/stacks/${s.id}`, { role })
			return s.id
		}
		state.todoId = await mk('To Do', ROLE_NONE)
		state.reviewId = await mk('Review', ROLE_REVIEW)
		// The laundering column: role-less, which is the DEFAULT for any column a
		// user creates, so one is nearly always sitting on the board.
		state.limboId = await mk('Notes', ROLE_NONE)
		state.doneId = await mk('Done', ROLE_DONE)
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	// A fresh card sitting in Review with one unapproved review requested on it.
	async function gatedCard(title) {
		const card = await api.post('/cards', { stackId: state.reviewId, title })
		await api.put(`/cards/${card.id}/reviews/${me}`)
		return card
	}

	test('the API refuses done: true while a review is unapproved', async () => {
		const card = await gatedCard('API done alias')
		const r = await api.raw('PATCH', `/cards/${card.id}`, { done: true })
		expect(r.status).toBe(403)
		expect(await doneAt(card.id)).toBe(0)
	})

	test('the API refuses status: done while a review is unapproved', async () => {
		const card = await gatedCard('API status done')
		const r = await api.raw('PATCH', `/cards/${card.id}`, { status: 'done' })
		expect(r.status).toBe(403)
		expect(await doneAt(card.id)).toBe(0)
	})

	test('the bulk action bar skips the gated card and completes the rest', async () => {
		// The bulk bar's "Mark done" and the `d` shortcut both send done: true.
		const gated = await gatedCard('Bulk gated')
		const free = await api.post('/cards', { stackId: state.reviewId, title: 'Bulk free' })

		const result = await api.post('/cards/bulk', {
			cardIds: [gated.id, free.id],
			action: 'set_status',
			params: { status: 'done' },
		})

		expect(result.ok).toEqual([free.id])
		expect(result.skipped).toEqual([{ id: gated.id, reason: 'forbidden' }])
		expect(await doneAt(gated.id)).toBe(0)
		expect(await doneAt(free.id)).toBeGreaterThan(0)
	})

	test('a hop through a role-less column cannot launder the gate', async () => {
		// The two ordinary drags that used to defeat it: Review → Notes → Done.
		// The first hop is legitimate and must still succeed.
		const card = await gatedCard('Laundered by two drags')

		await api.post(`/cards/${card.id}/move`, { targetStackId: state.limboId })
		expect((await api.get(`/cards/${card.id}`)).stackId).toBe(state.limboId)

		const r = await api.raw('POST', `/cards/${card.id}/move`, { targetStackId: state.doneId })
		expect(r.status).toBe(403)

		const after = await api.get(`/cards/${card.id}`)
		expect(Number(after.doneAt)).toBe(0)
		expect(after.stackId).toBe(state.limboId)
	})

	test('completing the last subtask does not auto-complete a gated parent', async () => {
		const parent = await gatedCard('Gated parent')
		const child = await api.post('/cards', { stackId: state.todoId, title: 'Only subtask' })
		await api.put(`/cards/${child.id}/parent`, { parentCardId: parent.id })

		// The child completes on its own - it carries no reviews.
		await api.patch(`/cards/${child.id}`, { done: true })
		expect(await doneAt(child.id)).toBeGreaterThan(0)

		// The parent stays open rather than being stamped done behind the gate.
		expect(await doneAt(parent.id)).toBe(0)
	})

	test('approving the review lets every one of those routes through', async () => {
		// Not over-broad: the same card, the same requests, once approved.
		const card = await gatedCard('Approved and done')
		const detail = await api.get(`/cards/${card.id}`)
		await api.patch(`/cards/${card.id}/reviews/${detail.reviews[0].id}`, { state: 'approved' })

		// The two-hop route now completes...
		await api.post(`/cards/${card.id}/move`, { targetStackId: state.limboId })
		await api.post(`/cards/${card.id}/move`, { targetStackId: state.doneId })
		expect(await doneAt(card.id)).toBeGreaterThan(0)

		// ...and so does done: true on a card that was never reviewed at all, so a
		// board that does not use reviews is untouched by any of this.
		const plain = await api.post('/cards', { stackId: state.todoId, title: 'No reviews here' })
		await api.patch(`/cards/${plain.id}`, { done: true })
		expect(await doneAt(plain.id)).toBeGreaterThan(0)
	})
})

// #10896 — the gate above, through the UI. The 403 was real and the card stayed
// open, but NOTHING was shown: setStatus() wrote the error into the shared
// `saveError`, which is only rendered inside the due-date popover and the
// description editor, so pressing "Mark done" on a card with a pending review
// looked like a dead button. This drives the actual click.
test.describe('Review gate — the card view says why Done did nothing (#10896)', () => {
	const state = { boardId: 0, cardId: 0, reviewId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: `Review Gate UI E2E ${Date.now()}` })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Needs a review first' })
		state.cardId = card.id
		await api.put(`/cards/${card.id}/reviews/${me}`)
		state.reviewId = (await api.get(`/cards/${card.id}`)).reviews[0].id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('Mark done names the reviews as the blocker, then works once approved', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.cardId}`)
		await page.waitForSelector('.card-modal__header', { timeout: 15_000 })

		const header = page.locator('.card-modal__header')
		const statusError = header.locator('[data-status-error]')

		// Nothing is pre-disabled: the server stays the only authority on the gate,
		// so the button is live and the refusal is what informs the user.
		await expect(header.locator('.card-modal__done-btn')).toBeEnabled()
		await expect(statusError).toHaveCount(0)

		await header.locator('.card-modal__done-btn').click()

		// The message names REVIEWS - not the server's flattened "Access denied" -
		// and names the reviewer being waited on, resolved from the reviews already
		// in the card payload (no extra request was made to say any of this).
		await expect(statusError).toBeVisible()
		await expect(statusError).toHaveText(/requested reviews must be approved/i)
		await expect(statusError).toHaveText(new RegExp(`Waiting on .*${me}`, 'i'))
		await expect(statusError).not.toHaveText(/access denied/i)
		// …and the card really did not complete.
		expect(await doneAt(state.cardId)).toBe(0)

		// Approving clears the gate. Pressing Done again - on the SAME page, no
		// reload, so the message really has to be cleared rather than simply never
		// rendered - completes the card and takes the message with it.
		await api.patch(`/cards/${state.cardId}/reviews/${state.reviewId}`, { state: 'approved' })
		await header.locator('.card-modal__done-btn').click()

		await expect.poll(() => doneAt(state.cardId), { timeout: 15_000 }).toBeGreaterThan(0)
		await expect(statusError).toHaveCount(0)
	})
})

// The message from #10896, one card along. The card view is rendered through an
// UNKEYED router-view, so navigating card→card REUSES the component — the reuse
// the props.cardId watcher exists for. That watcher clears `statusError` on the
// switch, which covers a refusal that has ALREADY landed; it cannot cover one
// still on the wire. The request rejects after the switch and setStatus() then
// wrote card A's refusal into card B's header: "all requested reviews must be
// approved" on a card carrying no reviews at all, naming a reviewer who was
// never asked. Precedented twice in this same component — the stale conflict
// panel, and the comment drafts of #10069 (card-unsaved-guard.spec.js's "a draft
// never leaks into the next card opened").
test.describe('Review gate — a refused Done does not follow you to the next card', () => {
	const state = { boardId: 0, gatedId: 0, cleanId: 0, cleanReviewId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: `Review Gate Carryover E2E ${Date.now()}` })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		const gated = await api.post('/cards', { stackId: stack.id, title: 'Carryover source A' })
		await api.put(`/cards/${gated.id}/reviews/${me}`)
		state.gatedId = gated.id
		// B carries NO reviews, so a review message on it is false on its face -
		// there is no reading of the screen under which it could be about B.
		const clean = await api.post('/cards', { stackId: stack.id, title: 'Carryover target B' })
		state.cleanId = clean.id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test("card A's refusal is not shown on card B, and B's own still is", async ({ page }) => {
		// The refusal has to still be in flight when the card switches, and a local
		// 403 is not - so hold the status PATCH open. ONLY the PATCH on the gated
		// card is held; every read card B needs goes through untouched.
		//
		// The hold is released by a promise, NOT by a sleep inside the handler. A
		// sleep is not ordered with the card switch: if it expired while the
		// navigation was still pending, the 403 would reach setStatus() while card A
		// was still current, the props.cardId watcher would then clear the message on
		// the switch anyway, and the assertion below would pass without ever
		// exercising isStillCurrentCard() - the one thing this test exists to pin.
		// That window is exactly what moves on a saturated runner pool (see
		// playwright.config.js), so the ordering is stated rather than timed: the
		// PATCH cannot come back until card B is on screen.
		let releaseGatedPatch = () => {}
		const gatedPatchHeld = new Promise((resolve) => { releaseGatedPatch = resolve })
		await page.route(`**/apps/kanso/api/cards/${state.gatedId}`, async (route) => {
			if (route.request().method() !== 'PATCH') return route.continue()
			await gatedPatchHeld
			await route.continue()
		})

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.gatedId}`)
		await expect(page.locator('.card-modal__title')).toHaveText('Carryover source A', { timeout: 15_000 })

		const statusError = page.locator('.card-modal__header [data-status-error]')
		let refusalArrived = false
		const refusal = page.waitForResponse(
			(r) => r.request().method() === 'PATCH' && r.url().endsWith(`/cards/${state.gatedId}`),
			{ timeout: 20_000 },
		).then((response) => {
			refusalArrived = true
			return response
		})

		// Pressed and deliberately NOT awaited: the refusal is still on the wire.
		await page.locator('.card-modal__header .card-modal__done-btn').click()
		await expect(statusError).toHaveCount(0)

		// …and card B is opened while it is. Same route record, so the component is
		// REUSED rather than remounted - which is the whole hazard. The hold is
		// released only once B is actually on screen, in a `finally` so a failing
		// assertion cannot leave the request stuck in the handler.
		try {
			await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.cleanId}`)
			await expect(page.locator('.card-modal__title')).toHaveText('Carryover target B', { timeout: 15_000 })
			// The ordering this whole test rests on, asserted instead of assumed: the
			// 403 has not come back yet, so when it does, card A is no longer the open
			// card and nothing but isStillCurrentCard() can keep it off B's header.
			expect(refusalArrived).toBe(false)
		} finally {
			releaseGatedPatch()
		}

		// Wait for the 403 to actually reach the browser, so an absent message is
		// one that was SUPPRESSED rather than one that has not been sent yet, plus
		// a settle for the catch block and a render.
		await refusal
		await page.waitForTimeout(800)
		await expect(statusError).toHaveCount(0)
		await expect(page.locator('.card-modal__header')).not.toHaveText(/requested reviews/i)
		// …and card A really did not complete, i.e. the refusal was real.
		expect(await doneAt(state.gatedId)).toBe(0)

		// Not over-broad: the guard drops a LATE error, not every error. Give card B
		// its own blocking review and press its own Done - that refusal must show.
		await api.put(`/cards/${state.cleanId}/reviews/${me}`)
		await page.reload()
		await expect(page.locator('.card-modal__title')).toHaveText('Carryover target B', { timeout: 15_000 })
		await page.locator('.card-modal__header .card-modal__done-btn').click()
		await expect(statusError).toBeVisible({ timeout: 15_000 })
		await expect(statusError).toHaveText(/requested reviews must be approved/i)
		expect(await doneAt(state.cleanId)).toBe(0)
	})
})
