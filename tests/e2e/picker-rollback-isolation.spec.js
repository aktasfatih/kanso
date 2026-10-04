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
// instead, and asserts the assignee pill is present in every sample. The label
// write is held 1500ms (the in-flight window the person is added in) and the
// card read 1200ms (a slow link, which is the condition under which two pickers
// overlap at all) — the latter is what makes the erasure dozens of samples wide
// rather than a flicker, and the measurement independent of how fast the box is.
//
// The label chip's own disappearance is asserted from the same samples: the fix
// is "roll back only the field you own", not "stop rolling back", and without
// that second assertion deleting the rollback altogether would pass.
//
// Stays the storageState admin throughout, so it needs no `test.use` opt-out.

import { test, expect, api, ncLogin, BASE, me } from './helpers.js'

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

		// The label write: slow, then refused. Long enough that the assignee pick
		// below is made while it is still on the wire.
		await page.route(/\/cards\/\d+\/labels\/\d+$/, async (route) => {
			await new Promise((resolve) => setTimeout(resolve, 1500))
			await route.fulfill({
				status: 403,
				contentType: 'application/json',
				body: JSON.stringify({ error: 'Refused on purpose' }),
			})
		})

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })
		await page.locator('.card-tile').filter({ hasText: 'Two pickers at once' }).click()
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		// Only NOW slow the card read down - before the modal is open it would
		// just slow the setup. This is the "slow link" half of the premise, and
		// what widens the erasure from a flicker to something measurable.
		await page.route(/\/api\/cards\/\d+(\?.*)?$/, async (route) => {
			if (route.request().method() !== 'GET') return route.continue()
			await new Promise((resolve) => setTimeout(resolve, 1200))
			await route.continue()
		})

		// Pick the label. Its write is now on the wire for 1500ms.
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

		// Sample both pickers' output across the refusal. 20ms is well under the
		// ~400ms the erasure lasts here, so it cannot slip between samples.
		await page.evaluate(() => {
			window.__kanso10923 = []
			window.__kanso10923Sampler = setInterval(() => {
				window.__kanso10923.push([
					document.querySelectorAll('.card-modal__attrbar .card-modal__assignee-pill').length,
					document.querySelectorAll('.card-modal__label-chip').length,
				])
			}, 20)
		})

		// The label write is refused here, and its rollback runs.
		await page.waitForResponse((r) => /\/cards\/\d+\/labels\/\d+$/.test(r.url()) && r.status() === 403)
		// Past the failure AND past the refetch that would heal it, so the samples
		// span the whole window in which the client could have been lying.
		await page.waitForTimeout(2500)

		const samples = await page.evaluate(() => {
			clearInterval(window.__kanso10923Sampler)
			return window.__kanso10923
		})
		expect(samples.length, 'the sampler must actually have run').toBeGreaterThan(40)
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
