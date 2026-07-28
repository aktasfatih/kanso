// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = {
	'OCS-APIREQUEST': 'true',
	'Content-Type': 'application/json',
}
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')

async function apiGet(path) {
	const r = await fetch(API + path, { headers: { ...HEADERS, Authorization: AUTH } })
	if (!r.ok) throw new Error(`GET ${path} → ${r.status}`)
	return r.json()
}

async function apiPost(path, body) {
	const r = await fetch(API + path, {
		method: 'POST',
		headers: { ...HEADERS, Authorization: AUTH },
		body: JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`POST ${path} → ${r.status}: ${await r.text()}`)
	return r.json()
}

async function apiDelete(path) {
	const r = await fetch(API + path, {
		method: 'DELETE',
		headers: { ...HEADERS, Authorization: AUTH },
	})
	if (!r.ok) throw new Error(`DELETE ${path} → ${r.status}`)
}

async function ncLogin(page) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})

	const userInput = page.locator('#user')
	const isLoginPage = await userInput.isVisible({ timeout: 3000 }).catch(() => false)
	if (!isLoginPage) return // Already logged in

	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

test.describe('Card Subscriptions / Watchers', () => {
	const state = { boardId: 0, stackId: 0, cardId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		// Tear down any prior board with the same name to ensure hermetic setup
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'Subscription E2E Board') {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		// Create fresh board + stack + card
		const board = await apiPost('/boards', { title: 'Subscription E2E Board' })
		state.boardId = board.id
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id
		const card = await apiPost('/cards', { stackId: stack.id, title: 'Card With Watchers' })
		state.cardId = card.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiDelete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('Watch button shows unsubscribed state initially', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const watchBtn = page.locator('.card-modal__watch-btn')
		await expect(watchBtn).toBeVisible({ timeout: 5000 })

		// Should NOT have the active class (not watching)
		await expect(watchBtn).not.toHaveClass(/card-modal__watch-btn--active/, { timeout: 3000 })

		// aria-pressed should be false
		await expect(watchBtn).toHaveAttribute('aria-pressed', 'false', { timeout: 3000 })

		// Label should say "Watch" (not "Watching")
		const label = watchBtn.locator('.card-modal__watch-label')
		await expect(label).toHaveText('Watch', { timeout: 3000 })
	})

	test('clicking Watch subscribes the user and shows count 1', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const watchBtn = page.locator('.card-modal__watch-btn')
		await expect(watchBtn).toBeVisible({ timeout: 5000 })

		// Click Watch
		await watchBtn.click()

		// Should become "Watching" with active class
		await expect(watchBtn).toHaveClass(/card-modal__watch-btn--active/, { timeout: 6000 })
		await expect(watchBtn).toHaveAttribute('aria-pressed', 'true', { timeout: 3000 })

		const label = watchBtn.locator('.card-modal__watch-label')
		await expect(label).toHaveText('Watching', { timeout: 3000 })

		// Count badge should show 1
		const countBadge = watchBtn.locator('.card-modal__watch-count')
		await expect(countBadge).toBeVisible({ timeout: 4000 })
		const countText = await countBadge.innerText()
		expect(Number(countText.trim())).toBeGreaterThanOrEqual(1)
	})

	test('Watching state persists after page reload', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const watchBtn = page.locator('.card-modal__watch-btn')
		await expect(watchBtn).toBeVisible({ timeout: 5000 })

		// After prior test subscribed, this should still show Watching on fresh load
		await expect(watchBtn).toHaveClass(/card-modal__watch-btn--active/, { timeout: 6000 })
		await expect(watchBtn).toHaveAttribute('aria-pressed', 'true', { timeout: 3000 })

		const label = watchBtn.locator('.card-modal__watch-label')
		await expect(label).toHaveText('Watching', { timeout: 3000 })
	})

	test('clicking Watching again unsubscribes and shows count 0', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const watchBtn = page.locator('.card-modal__watch-btn')
		await expect(watchBtn).toBeVisible({ timeout: 5000 })

		// Should currently be in Watching state
		await expect(watchBtn).toHaveClass(/card-modal__watch-btn--active/, { timeout: 6000 })

		// Click to unwatch
		await watchBtn.click()

		// Should revert to "Watch" (unsubscribed)
		await expect(watchBtn).not.toHaveClass(/card-modal__watch-btn--active/, { timeout: 6000 })
		await expect(watchBtn).toHaveAttribute('aria-pressed', 'false', { timeout: 3000 })

		const label = watchBtn.locator('.card-modal__watch-label')
		await expect(label).toHaveText('Watch', { timeout: 3000 })

		// Count badge should be gone or show 0
		const countBadge = watchBtn.locator('.card-modal__watch-count')
		const countVisible = await countBadge.isVisible({ timeout: 2000 }).catch(() => false)
		if (countVisible) {
			const countText = await countBadge.innerText()
			expect(Number(countText.trim())).toBe(0)
		}
		// else: no badge shown = count is 0, which is also correct
	})

	test('auto-subscribe: commenting via API makes admin a watcher', async ({ page }) => {
		// Use a FRESH card: the earlier unwatch test wrote an opt-out tombstone on
		// state.cardId, which correctly suppresses auto-subscribe there.
		const freshCard = await apiPost('/cards', { stackId: state.stackId, title: 'Fresh Watch Card' })
		const freshUrl = `${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${freshCard.id}`

		// Post a comment as admin via the API - server should auto-subscribe admin
		await apiPost(`/cards/${freshCard.id}/comments`, { body: 'API comment auto-subscribes me' })

		await ncLogin(page)
		await page.goto(freshUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const watchBtn = page.locator('.card-modal__watch-btn')
		await expect(watchBtn).toBeVisible({ timeout: 5000 })

		// Admin should now be auto-subscribed - Watching state expected
		await expect(watchBtn).toHaveClass(/card-modal__watch-btn--active/, { timeout: 8000 })
		await expect(watchBtn).toHaveAttribute('aria-pressed', 'true', { timeout: 3000 })

		// Count should be ≥ 1 (at least admin is watching)
		const countBadge = watchBtn.locator('.card-modal__watch-count')
		await expect(countBadge).toBeVisible({ timeout: 4000 })
		const countText = await countBadge.innerText()
		expect(Number(countText.trim())).toBeGreaterThanOrEqual(1)
	})
})
