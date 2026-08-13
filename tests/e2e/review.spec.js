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
	// Session is preloaded via storageState (see playwright.config.js) for the
	// default admin context — skip the UI login round-trip entirely. Specs that
	// opt out of storageState start with no cookies and fall through to log in.
	if ((await page.context().cookies()).some((c) => c.name === 'nc_username' || c.name === 'nc_session_id')) return
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

test.describe('Card review flow', () => {
	const state = { boardId: 0, stackId: 0, cardId: 0, cardUrl: '', boardUrl: '' }

	test.beforeAll(async () => {
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'Review E2E Board') {
				await apiSend('DELETE', `/boards/${b.id}`)
			}
		}

		const board = await apiSend('POST', '/boards', { title: 'Review E2E Board' })
		state.boardId = board.id
		const stack = await apiSend('POST', '/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id
		const card = await apiSend('POST', '/cards', { stackId: stack.id, title: 'Card Under Review' })
		state.cardId = card.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`

		// The board owner requests a review from themselves - a valid single-user
		// path (owner holds READ). Gives the card one pending review to drive the UI.
		await apiSend('PUT', `/cards/${card.id}/reviews/${USER}`)
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiSend('DELETE', `/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('modal shows the pending review chip and a verdict prompt', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const chip = page.locator('.card-modal__review-pill--pending')
		await expect(chip).toBeVisible({ timeout: 6000 })
		await expect(chip.locator('.card-modal__review-state--pending')).toContainText('Pending')

		// The current user is the pending reviewer, so the verdict banner shows.
		const verdict = page.locator('.card-modal__verdict')
		await expect(verdict).toBeVisible({ timeout: 4000 })
		await expect(verdict.getByRole('button', { name: 'Approve' })).toBeVisible()
		await expect(verdict.getByRole('button', { name: 'Request changes' })).toBeVisible()
	})

	test('approving flips the review chip to approved', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		await page.locator('.card-modal__verdict').getByRole('button', { name: 'Approve' }).click()

		await expect(page.locator('.card-modal__review-pill--approved')).toBeVisible({ timeout: 6000 })
		// Once approved, the "needs verdict" banner is gone.
		await expect(page.locator('.card-modal__verdict')).toHaveCount(0, { timeout: 4000 })
	})

	test('board tile shows the review-state chip', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// After the approval above, the tile carries an approved review chip.
		await expect(page.locator('.card-tile__review--approved').first()).toBeVisible({ timeout: 8000 })
	})
})
