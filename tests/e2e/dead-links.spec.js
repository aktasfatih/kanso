// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Dead-link UX (#3662): a deep-link/inbox/notification pointing at a card or
// board that no longer exists must land on a friendly, actionable message - not
// a raw "failed to load" error. This asserts:
//   1. Opening a card route for a non-existent card id under a live board shows
//      "This card no longer exists" + a "Go to boards" way out (not a raw error).
//   2. Opening a board route for a deleted board shows "This board no longer
//      exists" + a "Go to boards" link (not the generic error box).
//
// And the line that keeps those two honest (#155): the copy above belongs to
// KANSO's 404, the JSON one ApiErrorTrait sends. A 404 from a Nextcloud that
// briefly stopped routing /apps/kanso/api/* is an HTML error page and means the
// opposite — nothing is deleted, the server just failed to answer — so it gets
// the retryable branch instead. Same status, opposite verdicts.

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Dead card/board links (#3662)', () => {
	const state = { liveBoardId: 0, deadBoardId: 0, liveCardId: 0 }
	const TITLE = 'Dead Links E2E Board'

	test.beforeAll(async () => {
		// Clean any leftovers from a prior run.
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === TITLE) await api.delete(`/boards/${b.id}`).catch(() => {})
		}

		// A live board to host a bogus card deep-link.
		const live = await api.post('/boards', { title: TITLE })
		state.liveBoardId = live.id
		const stack = await api.post('/stacks', { boardId: live.id, title: 'To Do' })
		// A card that really exists, for the #155 test: the point there is that the
		// 404 is a lie, so the card behind it has to be real.
		const card = await api.post('/cards', { stackId: stack.id, title: 'Live card' })
		state.liveCardId = card.id

		// A second board we delete, to exercise the gone-board path.
		const dead = await api.post('/boards', { title: TITLE })
		state.deadBoardId = dead.id
		await api.delete(`/boards/${dead.id}`).catch(() => {})
	})

	test.afterAll(async () => {
		if (state.liveBoardId) await api.delete(`/boards/${state.liveBoardId}`).catch(() => {})
	})

	test.beforeEach(async ({ page }) => {
		await ncLogin(page)
	})

	test('a card that no longer exists shows a friendly message + a way out', async ({ page }) => {
		// Deep-link to a non-existent card id under the LIVE board (the card fetch
		// 404s while the board loads fine).
		const bogusCardId = 2_000_000_000
		await page.goto(
			`${BASE}/index.php/apps/kanso#/board/${state.liveBoardId}/card/${bogusCardId}`,
		)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		const err = page.locator('.card-modal__error')
		await expect(err).toBeVisible({ timeout: 15_000 })
		await expect(err).toContainText('no longer exists')
		// Not the old generic copy.
		await expect(err).not.toContainText('Failed to load card details')
		// A dead card is a dead end - no Retry, but a way out to the boards list.
		await expect(err.getByRole('button', { name: 'Go to boards' })).toBeVisible()
		await expect(err.getByRole('button', { name: 'Retry' })).toHaveCount(0)

		// The way out actually leaves the (broken) card.
		await err.getByRole('button', { name: 'Go to boards' }).click()
		await expect(page).toHaveURL(/#\/$/, { timeout: 10_000 })
	})

	test('a card 404 that Kanso did not send is retryable, not deleted (#155)', async ({ page }) => {
		// The counterpart to the test above, and the pair is the point: the same
		// status, opposite verdicts, decided by who sent it. Kanso's own 404 is
		// `{"error":"Not found"}` out of ApiErrorTrait — that card really is gone.
		// A Nextcloud that briefly stops routing /apps/kanso/api/* sends its HTML
		// error page for every endpoint at once, and the card open at the time was
		// being declared deleted on the strength of it.
		//
		// Only the card read itself is faulted, so the board around it stays
		// healthy and the assertion is about the card branch rather than about a
		// blank page.
		const cardRead = /\/apps\/kanso\/api\/cards\/\d+(?:\?|$)/
		let faults = 0
		await page.route(cardRead, async (route) => {
			faults++
			await route.fulfill({
				status: 404,
				contentType: 'text/html; charset=UTF-8',
				body: '<!DOCTYPE html><html><head><title>Nextcloud</title></head>'
					+ '<body><p>The page could not be found on the server.</p></body></html>',
			})
		})

		await page.goto(
			`${BASE}/index.php/apps/kanso#/board/${state.liveBoardId}/card/${state.liveCardId}`,
		)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		const err = page.locator('.card-modal__error')
		await expect(err).toBeVisible({ timeout: 15_000 })
		expect(faults, 'the injected 404 must actually have answered the card read').toBeGreaterThan(0)
		await expect(err).toContainText('Please try again')
		await expect(err,
			'this card exists; the server just failed to say so')
			.not.toContainText('no longer exists')
		// A dead card is a dead end and offers no Retry (the test above asserts
		// that). This one is a server that stumbled, so the retry is the whole
		// point — it is what the user does when routing comes back.
		await expect(err.getByRole('button', { name: 'Retry' })).toBeVisible()

		// And retrying, once the route is live again, actually opens the card.
		await page.unroute(cardRead)
		await err.getByRole('button', { name: 'Retry' }).click()
		await expect(page.locator('.card-modal__error')).toHaveCount(0, { timeout: 15_000 })
	})

	test('the boards list keeps its tiles when the feed 404s under it (#155)', async ({ page }) => {
		// The third surface the reporter named: "Failed to load boards." with the
		// grid replaced by it. The list is not board-scoped, which is half of how
		// we know nothing was deleted — and TanStack keeps `data` on a failed
		// re-read, so the tiles were there the whole time and the view was simply
		// choosing not to render them. Now the message sits above the list instead
		// of instead of it.
		test.setTimeout(120_000)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.waitForSelector('.board-tile', { timeout: 20_000 })
		const before = await page.locator('.board-tile').count()
		expect(before, 'the list must start out non-empty or this asserts nothing').toBeGreaterThan(0)

		await page.route(/\/apps\/kanso\/api\/boards(?:\?|$)/, (route) => route.fulfill({
			status: 404,
			contentType: 'text/html; charset=UTF-8',
			body: '<!DOCTYPE html><html><head><title>Nextcloud</title></head>'
				+ '<body><p>The page could not be found on the server.</p></body></html>',
		}))

		// Provoke the re-read the user's own tab would eventually make. The global
		// staleTime is 30s and refetchOnWindowFocus only fires on a STALE query, so
		// the wait is what makes the focus event mean anything.
		await page.waitForTimeout(32_000)
		await page.evaluate(() => {
			window.dispatchEvent(new Event('visibilitychange'))
			document.dispatchEvent(new Event('visibilitychange'))
		})

		await expect(page.locator('[data-test="boards-stale-error"]'),
			'the failure is still reported').toBeVisible({ timeout: 30_000 })
		expect(await page.locator('.board-tile').count(),
			'but it must not take the boards away — a transient 404 on this feed '
			+ 'emptied the page the user was looking at')
			.toBe(before)
	})

	test('a board that no longer exists explains itself + links to the boards list', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.deadBoardId}`)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		const err = page.locator('.board-view__error')
		await expect(err).toBeVisible({ timeout: 15_000 })
		await expect(err).toContainText('no longer exists')
		await expect(err).not.toContainText('Failed to load board.')
		await expect(err.getByRole('button', { name: 'Go to boards' })).toBeVisible()
	})
})
