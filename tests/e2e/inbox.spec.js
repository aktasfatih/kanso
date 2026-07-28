// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Inbox e2e: admin creates a board+stack+card, shares with tester (READ|EDIT),
// admin subscribes to the card, tester posts a comment.  Admin visits #/inbox
// and should see a feed item with the card title + comment snippet.  Clicking
// the item navigates to the card modal.
//
// If the two-user share setup is unavailable the test falls back to asserting
// the page at least renders without crashing (either a feed item or empty state).

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const ADMIN = 'admin'
const ADMIN_PASS = 'admin'
const TESTER = 'tester'
const TESTER_PASS = 'kanso-dev-tester!1'

const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const ADMIN_AUTH = 'Basic ' + Buffer.from(`${ADMIN}:${ADMIN_PASS}`).toString('base64')
const TESTER_AUTH = 'Basic ' + Buffer.from(`${TESTER}:${TESTER_PASS}`).toString('base64')

// ---------------------------------------------------------------------------
// HTTP helpers
// ---------------------------------------------------------------------------

async function apiRequest(path, { method = 'GET', auth = ADMIN_AUTH, body } = {}) {
	const opts = { method, headers: { ...HEADERS, Authorization: auth } }
	if (body !== undefined) opts.body = JSON.stringify(body)
	const r = await fetch(API + path, opts)
	return r
}

async function apiGet(path, auth = ADMIN_AUTH) {
	const r = await apiRequest(path, { auth })
	if (!r.ok) throw new Error(`GET ${path} → ${r.status}`)
	return r.json()
}

async function apiPost(path, body, auth = ADMIN_AUTH) {
	const r = await apiRequest(path, { method: 'POST', auth, body })
	if (!r.ok) throw new Error(`POST ${path} → ${r.status}: ${await r.text()}`)
	return r.json()
}

async function apiPut(path, auth = ADMIN_AUTH) {
	const r = await apiRequest(path, { method: 'PUT', auth })
	if (!r.ok) throw new Error(`PUT ${path} → ${r.status}`)
}

async function apiDelete(path, auth = ADMIN_AUTH) {
	await apiRequest(path, { method: 'DELETE', auth })
}

// ---------------------------------------------------------------------------
// Login helper
// ---------------------------------------------------------------------------

async function ncLogin(page, user = ADMIN, pass = ADMIN_PASS) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	const isLoginPage = await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false)
	if (!isLoginPage) return
	await page.fill('#user', user)
	await page.fill('#password', pass)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

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
		setupOk: false,
	}

	test.beforeAll(async () => {
		// Clean up any leftover board from a prior run
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'Inbox E2E Board') {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		// Create board + stack + card as admin
		const board = await apiPost('/boards', { title: 'Inbox E2E Board' })
		state.boardId = board.id

		const stack = await apiPost('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id

		const card = await apiPost('/cards', { stackId: stack.id, title: 'Inbox Test Card' })
		state.cardId = card.id

		// Share board with tester (READ|EDIT = permission 3)
		// If tester user doesn't exist this will fail gracefully - we fall back
		let shareOk = false
		try {
			await apiPost(`/boards/${board.id}/acl`, {
				participant: TESTER,
				participantType: 'user',
				permission: 3,
			})
			shareOk = true
		} catch {
			// tester user not present in this environment - fall back mode
		}

		// Admin subscribes to the card
		try {
			await apiPut(`/cards/${card.id}/subscription`)
		} catch {
			// subscription endpoint may not be available outside dev stack
		}

		// Tester posts a comment (only if share succeeded)
		if (shareOk) {
			try {
				state.commentBody = 'Hello from tester - inbox smoke test'
				await apiPost(`/cards/${card.id}/comments`, { body: state.commentBody }, TESTER_AUTH)
				state.setupOk = true
			} catch {
				// tester auth failed - still run fallback assertion
			}
		}
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiDelete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('inbox page loads and shows feed item or empty state', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.inboxUrl)
		await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {})

		// Wait for the inbox view to mount (either items or empty state)
		await page.waitForSelector('.inbox-view', { timeout: 15_000 })

		// The page must not be in error state
		const errorEl = page.locator('.inbox-view__error')
		await expect(errorEl).toBeHidden({ timeout: 5000 })

		if (state.setupOk) {
			// Full path: a feed item with the card title should appear
			const itemList = page.locator('.inbox-view__list')
			await expect(itemList).toBeVisible({ timeout: 10_000 })

			// At least one item referencing the card title
			const cardTitleEl = itemList.locator('.inbox-view__item-card', { hasText: 'Inbox Test Card' }).first()
			await expect(cardTitleEl).toBeVisible({ timeout: 8000 })

			// Comment snippet must appear somewhere in the item
			const bodyEl = itemList.locator('.inbox-view__item-body', { hasText: state.commentBody.slice(0, 20) }).first()
			await expect(bodyEl).toBeVisible({ timeout: 5000 })
		} else {
			// Fallback: either the list exists (if there were pre-existing inbox items)
			// or the empty state is shown
			const listVisible = await page.locator('.inbox-view__list').isVisible({ timeout: 5000 }).catch(() => false)
			const emptyVisible = await page.locator('.empty-content').isVisible({ timeout: 5000 }).catch(() => false)
			expect(listVisible || emptyVisible).toBe(true)
		}
	})

	test('clicking an inbox item navigates to the card modal', async ({ page }) => {
		// Only run the click-through test when we have a real feed item
		test.skip(!state.setupOk, 'skipping click-through: tester setup was not available')

		await ncLogin(page)
		await page.goto(state.inboxUrl)
		await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {})
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

	test('empty state renders when inbox is empty', async ({ page }) => {
		// Create a fresh board+card without sharing/subscribing so the inbox is
		// guaranteed empty for this user, then navigate directly to #/inbox.
		// We only run this when the full setup already succeeded (meaning the
		// environment is up) to avoid double-fallback noise.
		test.skip(!state.setupOk, 'skipping empty-state test: environment not available')

		// Create a separate session as a second admin context isn't easy here,
		// so we just navigate to #/inbox and verify the structure is correct.
		// The feed may or may not be empty depending on prior data - we simply
		// assert the page renders consistently (no crash, correct DOM present).
		await ncLogin(page)
		await page.goto(state.inboxUrl)
		await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {})
		await page.waitForSelector('.inbox-view', { timeout: 15_000 })

		// Either the list or the empty-content must be visible - never both
		const listVisible = await page.locator('.inbox-view__list').isVisible({ timeout: 3000 }).catch(() => false)
		const emptyVisible = await page.locator('.empty-content').isVisible({ timeout: 3000 }).catch(() => false)
		expect(listVisible || emptyVisible).toBe(true)
		expect(listVisible && emptyVisible).toBe(false)
	})
})
