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

async function apiSend(method, path, body) {
	const r = await fetch(API + path, {
		method,
		headers: { ...HEADERS, Authorization: AUTH },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`${method} ${path} → ${r.status}: ${await r.text()}`)
	return method === 'DELETE' || method === 'PUT' ? null : r.json()
}

async function ncLogin(page) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	const userInput = page.locator('#user')
	const isLoginPage = await userInput.isVisible({ timeout: 3000 }).catch(() => false)
	if (!isLoginPage) return
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

test.describe('My Reviews page', () => {
	const state = { boardId: 0, stackId: 0, cardId: 0, reviewsUrl: '' }

	test.beforeAll(async () => {
		// Clean up any stale board from a previous run
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'My Reviews E2E Board') {
				await apiSend('DELETE', `/boards/${b.id}`)
			}
		}

		const board = await apiSend('POST', '/boards', { title: 'My Reviews E2E Board' })
		state.boardId = board.id
		const stack = await apiSend('POST', '/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id
		const card = await apiSend('POST', '/cards', { stackId: stack.id, title: 'Review Me Please' })
		state.cardId = card.id

		// The board owner (admin) requests a review from themselves — produces a
		// pending review row that appears on the My Reviews page.
		await apiSend('PUT', `/cards/${card.id}/reviews/${USER}`)

		state.reviewsUrl = `${BASE}/index.php/apps/kanso#/reviews`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiSend('DELETE', `/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('My Reviews page shows the pending card under "Needs your review"', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.reviewsUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		// Wait for the page to finish loading
		await page.waitForSelector('.my-reviews-view', { timeout: 10_000 })

		// The section heading must be present
		const section = page.locator('.my-reviews-view__section').filter({
			has: page.locator('.my-reviews-view__section-title', { hasText: 'Needs your review' }),
		})
		await expect(section).toBeVisible({ timeout: 8_000 })

		// Scope to OUR card's row (the shared dev instance may hold other pending
		// reviews for admin from earlier suites).
		const row = section.locator('.review-row', { hasText: 'Review Me Please' })
		await expect(row).toBeVisible({ timeout: 6_000 })

		// Approve + Request changes buttons must be visible for the pending row
		await expect(row.getByRole('button', { name: 'Approve' })).toBeVisible({ timeout: 4_000 })
		await expect(row.getByRole('button', { name: 'Request changes' })).toBeVisible({ timeout: 4_000 })
	})

	test('clicking Approve moves the row out of "Needs your review"', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.reviewsUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.my-reviews-view', { timeout: 10_000 })

		const pendingSection = page.locator('.my-reviews-view__section').filter({
			has: page.locator('.my-reviews-view__section-title', { hasText: 'Needs your review' }),
		})
		await expect(pendingSection).toBeVisible({ timeout: 8_000 })

		// Click Approve on OUR card's pending row (scope past any other pending
		// reviews the shared dev instance may hold for admin).
		await pendingSection.locator('.review-row', { hasText: 'Review Me Please' })
			.getByRole('button', { name: 'Approve' }).click()

		// The "Needs your review" section should disappear (or no longer contain our card)
		await expect(
			pendingSection.locator('.review-row__card-title', { hasText: 'Review Me Please' }),
		).toHaveCount(0, { timeout: 8_000 })

		// The "Approved" section should now appear with the card
		const approvedSection = page.locator('.my-reviews-view__section').filter({
			has: page.locator('.my-reviews-view__section-title', { hasText: 'Approved' }),
		})
		await expect(approvedSection).toBeVisible({ timeout: 8_000 })
		await expect(approvedSection.locator('.review-row__card-title', { hasText: 'Review Me Please' })).toBeVisible({ timeout: 6_000 })
	})

	test('clicking "Open card" affordance navigates to the card modal', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.reviewsUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.my-reviews-view', { timeout: 10_000 })

		// After previous test the card is approved — click the approved row
		const approvedSection = page.locator('.my-reviews-view__section').filter({
			has: page.locator('.my-reviews-view__section-title', { hasText: 'Approved' }),
		})
		await expect(approvedSection).toBeVisible({ timeout: 8_000 })

		const row = approvedSection.locator('.review-row', { hasText: 'Review Me Please' })
		await expect(row).toBeVisible({ timeout: 6_000 })
		await row.click()

		// Should navigate to the card modal route: #/board/:id/card/:cardId
		await expect(page).toHaveURL(
			new RegExp(`/board/${state.boardId}/card/${state.cardId}`),
			{ timeout: 8_000 },
		)
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 10_000 })
	})
})
