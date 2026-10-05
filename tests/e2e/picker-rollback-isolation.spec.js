// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Card #10923 — a FAILED write in one attribute picker must not revert what
// ANOTHER picker already committed.
//
// The browser end of crossPickerRollback.test.mjs. The three pickers in the
// attribute bar have a FIFO queue each (#10799/#10920/#10922), so a label write
// and an assignee write genuinely overlap. Both optimistic mutations used to
// snapshot the whole ['card', id] object in onMutate and re-set it wholesale in
// onError, so a refused label write restored a card from before the person the
// user added in between — a person the server had accepted and kept.
//
// Measuring that needs care, because it heals: the failed write's own settle
// invalidation refetches the card, so the erasure lasts one round trip, and a
// plain `toBeVisible` would simply poll until the refetch repainted it and pass
// on broken code. So this test SAMPLES the DOM every 20ms across the failure
// instead, and asserts the assignee pill is present in every sample.
//
// The label chip's own disappearance is asserted from the same samples: the fix
// is "roll back only the field you own", not "stop rolling back", and without
// that second assertion deleting the rollback altogether would pass.
//
// EVERY hold here is released at a point in the test, not after a duration.
// The first cut of this spec timed them — the label write slept 1500ms before
// its 403, the card read 1200ms — and that made it flaky on CI (run
// 37338943106 failed it twice and passed on the third attempt). The mechanism,
// measured locally: the 403 has to arrive AFTER `waitForResponse` is
// registered, and between the label click and that line the test picks an
// assignee and waits for the server to confirm it — 724ms on an idle box
// against 778ms of slack, i.e. a 2.07x slowdown inverts the order. The
// saturated runner pool is measured at 1.8-3x (playwright.config.js:23-32).
// Reproduced by shortening the sleep to 400ms: the 403 landed 179ms BEFORE the
// wait was registered, and the wait then sat there until the 240s test cap —
// exactly the CI failure. The same inversion is also silently VACUOUS: the
// rollback then runs before the sampler is installed, so the erasure it is
// supposed to catch happens off-camera.
//
// So the ordering is stated instead of timed:
//   * the label write's 403 cannot come back until `releaseLabelWrite()`, which
//     is called only once the assignee is committed and the sampler is running;
//   * no card READ can land until `openCardReads()`, called only once the
//     refusal has arrived — otherwise a refetch could repaint the card (the
//     server never saw the refused label) and take the chip off before the
//     rollback does, which is the one thing that would make the second
//     assertion meaningless;
//   * `refusalArrived` is asserted false at the moment of the pick, so a
//     future edit back to a timed hold fails loudly instead of going vacuous.
//
// Stays the storageState admin throughout, so it needs no `test.use` opt-out.

import { test, expect, api, ncLogin, BASE, me } from './helpers.js'

// How often the DOM is sampled, and how many samples must fall inside the
// erasure window before the test is willing to judge it. The two numbers below
// are an inequality within this file rather than a bet on the runner:
// SAMPLES_AFTER_ROLLBACK * SAMPLE_EVERY_MS (500ms) has to fit inside the
// erasure window, and that window is SLOW_READ_MS (1200ms) wide because the
// test itself delays the healing read by that much.
const SAMPLE_EVERY_MS = 20
const SAMPLES_AFTER_ROLLBACK = 25
// The "slow link" half of the premise — the condition under which two pickers
// overlap at all. Applied to the card read only AFTER the refusal, so it widens
// the erasure from a flicker to something dozens of samples wide without being
// ordered against anything.
const SLOW_READ_MS = 1200

test.describe('A failed picker write does not revert another picker (#10923)', () => {
	const state = { boardId: 0, cardId: 0, labelId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Rollback isolation ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Two pickers at once' })
		state.cardId = card.id
		const label = await api.post('/labels', { boardId: board.id, title: 'RefusedLabel' })
		state.labelId = label.id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('a refused label write keeps the assignee added while it was in flight', async ({ page }) => {
		// A clean start, so a retry does not inherit the previous attempt.
		await api.delete(`/cards/${state.cardId}/assignees/${me}`).catch(() => {})
		await api.delete(`/cards/${state.cardId}/labels/${state.labelId}`).catch(() => {})

		// The label write: held, then refused. Held by a PROMISE, so it is still
		// on the wire for exactly as long as the test needs it to be — see the
		// header for what the timed version did on a loaded runner.
		let releaseLabelWrite = () => {}
		const labelWriteHeld = new Promise((resolve) => { releaseLabelWrite = resolve })
		await page.route(/\/cards\/\d+\/labels\/\d+$/, async (route) => {
			await labelWriteHeld
			await route.fulfill({
				status: 403,
				contentType: 'application/json',
				body: JSON.stringify({ error: 'Refused on purpose' }),
			})
		})

		// Registered before the click that triggers it, so the response cannot be
		// missed however the rest of the test is paced. The flag is what lets the
		// ordering be asserted rather than assumed.
		let refusalArrived = false
		const refusal = page.waitForResponse(
			(r) => /\/cards\/\d+\/labels\/\d+$/.test(r.url()) && r.status() === 403,
		).then((response) => {
			refusalArrived = true
			return response
		})

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })
		await page.locator('.card-tile').filter({ hasText: 'Two pickers at once' }).click()
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		// Only NOW gate the card read - before the modal is open it would just
		// stall the setup. Every read is held until the refusal has landed and
		// then served slowly; the gate is what stops a refetch from taking the
		// refused chip off the screen BEFORE the rollback does.
		let openCardReads = () => {}
		const cardReadsHeld = new Promise((resolve) => { openCardReads = resolve })
		await page.route(/\/api\/cards\/\d+(\?.*)?$/, async (route) => {
			if (route.request().method() !== 'GET') return route.continue()
			await cardReadsHeld
			await new Promise((resolve) => setTimeout(resolve, SLOW_READ_MS))
			await route.continue()
		})

		// Pick the label. Its write is now on the wire until this test says so.
		await page.locator('.card-modal__attrbar button[data-pill="label"]').first().click()
		const popover = page.locator('.card-modal__attrbar .card-modal__popover')
		await expect(popover).toBeVisible()
		await popover.locator('.card-modal__label-toggle', { hasText: 'RefusedLabel' }).click()
		await expect(page.locator('.card-modal__label-chip', { hasText: 'RefusedLabel' })).toBeVisible()

		// ...and while it is, assign somebody. Different picker, different queue,
		// so this one is NOT held behind the label write.
		await page.keyboard.press('Escape')
		await page.locator('.card-modal__attrbar button[data-pill="assign"]').click()
		await expect(popover).toBeVisible()
		await popover.locator('.card-modal__assign-option', { hasText: me }).first().click()
		await expect(page.locator('.card-modal__attrbar .card-modal__assignee-pill')).toHaveCount(1)
		// The server took it - so anything that removes it client-side is a lie.
		await expect.poll(async () => (await api.get(`/cards/${state.cardId}`)).assigneeIds).toContain(me)
		await page.keyboard.press('Escape')

		// `finally` so a failing assertion in here cannot leave the label write
		// stuck inside the route handler.
		try {
			// The overlap this whole test rests on, asserted instead of assumed: the
			// label write has NOT been answered yet, so its rollback is still ahead
			// of us and the person above was added squarely inside its window.
			expect(refusalArrived, 'the label write must still be in flight').toBe(false)

			// Sample both pickers' output across the refusal. The sampler also counts
			// the samples taken once the refused chip is off, which is how the test
			// waits out the erasure window by OBSERVATION rather than by clock.
			//
			// The FIRST sample is taken synchronously, before the interval is even
			// armed. A refusal fulfilled by a route handler reaches the page in a
			// couple of milliseconds, so waiting for the first interval tick would
			// leave a 20ms hole at the start - measured: the rollback landed 3ms
			// after this call, i.e. inside the hole - and the pre-rollback state
			// would never be on record.
			await page.evaluate(({ every }) => {
				window.__kanso10923 = []
				window.__kanso10923After = 0
				const sample = () => {
					const assignees = document.querySelectorAll('.card-modal__attrbar .card-modal__assignee-pill').length
					const labels = document.querySelectorAll('.card-modal__label-chip').length
					window.__kanso10923.push([assignees, labels])
					if (labels === 0) { window.__kanso10923After++ }
				}
				sample()
				window.__kanso10923Sampler = setInterval(sample, every)
			}, { every: SAMPLE_EVERY_MS })
		} finally {
			// Everything the measurement needs is in place, so let the refusal
			// through and let the rollback run.
			releaseLabelWrite()
		}
		await refusal

		// The refusal is in the browser, so the healing read may now start - and
		// it takes SLOW_READ_MS, which is the width of the window being sampled.
		openCardReads()

		// The rollback took the refused chip off (a clean failure here is what
		// catches "the rollback was deleted outright")...
		await expect(page.locator('.card-modal__label-chip')).toHaveCount(0)
		// ...and the sampler keeps going until it has SAMPLES_AFTER_ROLLBACK
		// samples from inside the erasure window, so the window is covered by
		// observation and not by a sleep that a slow box could outrun.
		await page.waitForFunction(
			(wanted) => window.__kanso10923After >= wanted,
			SAMPLES_AFTER_ROLLBACK,
		)

		const samples = await page.evaluate(() => {
			clearInterval(window.__kanso10923Sampler)
			return window.__kanso10923
		})
		expect(samples[0]?.[1],
			'the sampler must have started BEFORE the rollback - with the refused '
			+ 'chip still on screen - or it is measuring the wrong window').toBe(1)
		expect(samples.filter((s) => s[1] === 0).length,
			'and must have kept running across the erasure window').toBeGreaterThanOrEqual(SAMPLES_AFTER_ROLLBACK)
		expect(Math.min(...samples.map((s) => s[0])),
			'the assignee pill must be there in EVERY sample - the failed label '
			+ 'rollback must not take the committed assignee with it').toBe(1)
		expect(Math.min(...samples.map((s) => s[1])),
			'...while the refused label DOES come back off, which is the rollback '
			+ 'this fix narrows rather than removes').toBe(0)

		// And the client ends up agreeing with the server, not merely looking right.
		await expect(page.locator('.card-modal__attrbar .card-modal__assignee-pill')).toHaveCount(1)
		await expect(page.locator('.card-modal__label-chip')).toHaveCount(0)
		const server = await api.get(`/cards/${state.cardId}`)
		expect(server.assigneeIds).toContain(me)
		expect(server.labelIds).toEqual([])
	})
})
