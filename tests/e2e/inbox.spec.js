// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Inbox e2e: the acting user creates a board+stack+card, shares it with the peer
// identity (READ|EDIT), subscribes to the card, and the peer posts a comment and
// requests a review.  The owner visits #/inbox and should see feed items for
// both.  Clicking an item navigates to the card modal.
//
// Setup is DELIBERATELY unguarded. It used to be wrapped in swallowing
// try/catch blocks behind a `state.setupOk` flag, with a vacuous "the page at
// least rendered" fallback and four conditional test.skip()s — so a real ACL,
// subscription or comment regression degraded to a silent pass. The peer
// identity is always available (the `peer` fixture provisions a per-worker user
// under E2E_ISOLATE, and resolves to the dev stack's `tester` otherwise), so a
// setup failure IS a product failure and must abort the suite loudly.

import { test, expect, api, me, ncLogin, BASE } from './helpers.js'

// ---------------------------------------------------------------------------
// Suite
// ---------------------------------------------------------------------------

test.describe('Inbox feed', () => {
	const state = {
		boardId: 0,
		stackId: 0,
		cardId: 0,
		commentBody: '',
		inboxUrl: `${BASE}/index.php/apps/kanso#/inbox`,
	}

	test.beforeAll(async ({ peer }) => {
		// Clean up any leftover board from a prior run
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Inbox E2E Board') {
				await api.delete(`/boards/${b.id}`).catch(() => {})
			}
		}

		// Create board + stack + card
		const board = await api.post('/boards', { title: 'Inbox E2E Board' })
		state.boardId = board.id

		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id

		const card = await api.post('/cards', { stackId: stack.id, title: 'Inbox Test Card' })
		state.cardId = card.id

		// Share the board with the peer (READ|EDIT = permission 3).
		await api.post(`/boards/${board.id}/acl`, {
			participant: peer.user,
			participantType: 'user',
			permission: 3,
		})

		// Follow the card, so the peer's activity on it reaches our inbox.
		await api.put(`/cards/${card.id}/subscription`)

		// The peer comments on it.
		state.commentBody = 'Hello from the peer - inbox smoke test'
		await peer.api.post(`/cards/${card.id}/comments`, { body: state.commentBody })

		// …and requests a review from us — a card-status event (#3457) on a card we
		// follow, by an actor other than us, so it should surface in the inbox feed
		// alongside the comment.
		const review = await peer.api.raw('PUT', `/cards/${card.id}/reviews/${me}`)
		if (!review.ok) {
			throw new Error(`peer review request → ${review.status}: ${await review.text()}`)
		}
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await api.delete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('inbox page loads and shows the peer comment as a feed item', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.inboxUrl)
		await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {})

		// Wait for the inbox view to mount (either items or empty state)
		await page.waitForSelector('.inbox-view', { timeout: 15_000 })

		// The page must not be in error state
		const errorEl = page.locator('.inbox-view__error')
		await expect(errorEl).toBeHidden({ timeout: 5000 })

		const itemList = page.locator('.inbox-view__list')
		await expect(itemList).toBeVisible({ timeout: 10_000 })

		// At least one item referencing the card title
		const cardTitleEl = itemList.locator('.inbox-view__item-card', { hasText: 'Inbox Test Card' }).first()
		await expect(cardTitleEl).toBeVisible({ timeout: 8000 })

		// Comment snippet must appear somewhere in the item
		const bodyEl = itemList.locator('.inbox-view__item-body', { hasText: state.commentBody.slice(0, 20) }).first()
		await expect(bodyEl).toBeVisible({ timeout: 5000 })
	})

	test('clicking an inbox item navigates to the card modal', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.inboxUrl)
		await page.waitForSelector('.inbox-view__list', { timeout: 15_000 })

		// Click the first item that mentions our card
		const item = page.locator('.inbox-view__item').filter({ has: page.locator('.inbox-view__item-card', { hasText: 'Inbox Test Card' }) }).first()
		await expect(item).toBeVisible({ timeout: 8000 })
		await item.click()

		// Hash URL should now include the board and card segments
		await page.waitForURL(
			(url) => url.hash.includes(`/board/${state.boardId}`) && url.hash.includes(`/card/${state.cardId}`),
			{ timeout: 10_000 },
		)

		// Card modal must open
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 10_000 })
	})

	test('pressing Enter on a focused inbox item opens the card (#3511)', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.inboxUrl)
		await page.waitForSelector('.inbox-view__list', { timeout: 15_000 })

		const item = page.locator('.inbox-view__item').filter({ has: page.locator('.inbox-view__item-card', { hasText: 'Inbox Test Card' }) }).first()
		await expect(item).toBeVisible({ timeout: 8000 })

		// Focus the row and activate it by keyboard (was broken: the enter+space
		// modifier chain required both keys at once, so Enter alone did nothing).
		await item.focus()
		await item.press('Enter')

		await page.waitForURL(
			(url) => url.hash.includes(`/board/${state.boardId}`) && url.hash.includes(`/card/${state.cardId}`),
			{ timeout: 10_000 },
		)
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 10_000 })
	})

	test('the feed surfaces card-status events, not only comments (#3457)', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.inboxUrl)
		await page.waitForSelector('.inbox-view__list', { timeout: 15_000 })

		// The review-request event (by tester) shows with its verb phrase, on our
		// followed card — proving the feed reads the kanso_changes verb log, not
		// just comments.
		const list = page.locator('.inbox-view__list')
		const statusItem = list.locator('.inbox-view__item', { hasText: 'requested a review on' })
			.filter({ has: page.locator('.inbox-view__item-card', { hasText: 'Inbox Test Card' }) })
			.first()
		await expect(statusItem).toBeVisible({ timeout: 8000 })
	})

	test('the feed and the empty state are mutually exclusive', async ({ page }) => {
		// Setup guarantees this user's inbox is NOT empty, so the list must be the
		// branch that renders and the empty state must be absent. (This used to
		// accept "list OR empty-content" behind a swallowed isVisible(), which no
		// inbox state could fail. `.empty-content` is also a shared @nextcloud/vue
		// class — Nextcloud's own header widgets render it — so it must be scoped
		// to the inbox view or it matches chrome that has nothing to do with us.)
		await ncLogin(page)
		await page.goto(state.inboxUrl)
		await page.waitForSelector('.inbox-view', { timeout: 15_000 })

		await expect(page.locator('.inbox-view__list')).toBeVisible({ timeout: 10_000 })
		await expect(page.locator('.inbox-view .empty-content')).toHaveCount(0, { timeout: 5000 })
	})
})
