// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #3610 — "My Work" is split into three separate left-nav items (My Tasks /
// My Reviews / Inbox), each routing to its standalone view. My Reviews shows a
// badge counting pending review requests. Closing a card opened from a
// standalone view returns to that view (#3597 close-to-origin intact).

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
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
	// Session is preloaded via storageState (see playwright.config.js) for the
	// default admin context — skip the UI login round-trip entirely. Specs that
	// opt out of storageState start with no cookies and fall through to log in.
	if ((await page.context().cookies()).some((c) => c.name === 'nc_username' || c.name === 'nc_session_id')) return
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	if (!(await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false))) return
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

function navItem(page, name) {
	return page.locator('.app-navigation .app-navigation-entry-link', { hasText: name })
}

test.describe('My Work split into three nav items with badges', () => {
	const BOARD_TITLE = 'My Work Nav E2E ' + Date.now()
	const state = { boardId: 0, stackId: 0, cardId: 0 }

	test.beforeAll(async () => {
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === BOARD_TITLE) await apiSend('DELETE', `/boards/${b.id}`)
		}
		const board = await apiSend('POST', '/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		const stack = await apiSend('POST', '/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id
		const card = await apiSend('POST', '/cards', { stackId: stack.id, title: 'Nav Split Target Card' })
		state.cardId = card.id
		// Assign to admin → surfaces in My Tasks. Request review from admin →
		// surfaces a pending review (drives the My Reviews badge).
		await apiSend('PUT', `/cards/${card.id}/assignees/${USER}`)
		await apiSend('PUT', `/cards/${card.id}/reviews/${USER}`)
	})

	test.afterAll(async () => {
		if (state.boardId) await apiSend('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('three separate nav items render and navigate to their views', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.waitForSelector('.app-navigation', { timeout: 15_000 })

		// All three entries are present as distinct nav items.
		await expect(navItem(page, 'My Tasks').first()).toBeVisible({ timeout: 10_000 })
		await expect(navItem(page, 'My Reviews').first()).toBeVisible()
		await expect(navItem(page, 'Inbox').first()).toBeVisible()

		// My Tasks → standalone view with its own header (not the hub).
		await navItem(page, 'My Tasks').first().click()
		await expect(page).toHaveURL(/#\/my-tasks/, { timeout: 10_000 })
		await expect(page.locator('.my-cards-view__header')).toBeVisible({ timeout: 8_000 })

		// My Reviews → standalone reviews view.
		await navItem(page, 'My Reviews').first().click()
		await expect(page).toHaveURL(/#\/reviews/, { timeout: 10_000 })
		await expect(page.locator('.my-reviews-view')).toBeVisible({ timeout: 8_000 })

		// Inbox → standalone inbox view.
		await navItem(page, 'Inbox').first().click()
		await expect(page).toHaveURL(/#\/inbox/, { timeout: 10_000 })
		await expect(page.locator('.inbox-view')).toBeVisible({ timeout: 8_000 })
	})

	test('My Reviews nav item shows a pending-review counter badge', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.waitForSelector('.app-navigation', { timeout: 15_000 })

		// The counter bubble lives inside the My Reviews entry's counter slot.
		const reviewsEntry = page.locator('.app-navigation-entry-wrapper', {
			has: page.locator('.app-navigation-entry-link', { hasText: 'My Reviews' }),
		})
		const counter = reviewsEntry.locator('.app-navigation-entry__counter-wrapper')
		await expect(counter).toBeVisible({ timeout: 10_000 })
		// At least our one pending review is counted.
		await expect(counter).toHaveText(/[1-9]\d*/, { timeout: 8_000 })
	})

	test('close-to-origin: card opened from standalone My Reviews returns there (#3597)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)

		// Standalone reviews route (reached via the new nav item).
		await page.goto(`${BASE}/index.php/apps/kanso#/reviews`)
		await page.waitForSelector('.my-reviews-view', { timeout: 15_000 })

		const row = page.locator('.review-row', { hasText: 'Nav Split Target Card' })
		await expect(row).toBeVisible({ timeout: 8_000 })
		await row.click()

		// Opens with from=my-reviews threaded in the query.
		await expect(page).toHaveURL(/#\/board\/\d+\/card\/\d+\?from=my-reviews/, { timeout: 10_000 })
		await page.waitForSelector('.card-modal__content', { timeout: 15_000 })

		// Close → back to the standalone reviews view, not the board.
		await page.keyboard.press('Escape')
		await expect(page).toHaveURL(/#\/reviews/, { timeout: 10_000 })
		await expect(page).not.toHaveURL(/#\/board\//)
		await expect(page.locator('.my-reviews-view')).toBeVisible({ timeout: 10_000 })
	})
})
