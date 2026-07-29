// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')

async function api(method, path, body) {
	const r = await fetch(API + path, {
		method,
		headers: { ...HEADERS, Authorization: AUTH },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`${method} ${path} → ${r.status}: ${await r.text()}`)
	return method === 'DELETE' ? null : r.json()
}

async function ncLogin(page) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	if (!(await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false))) return
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

async function boardArchived(id) {
	const boards = await api('GET', '/boards')
	return boards.find((b) => b.id === id)?.archived
}

// #3514 — board archiving.
test.describe('Board archiving', () => {
	const state = { boardId: 0, title: '' }

	test.beforeAll(async () => {
		state.title = 'Archive-Me ' + Math.floor(Date.now() / 1000)
		const board = await api('POST', '/boards', { title: state.title })
		state.boardId = board.id
		await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('archive from settings hides the board, then unarchive restores it', async ({ page }) => {
		expect(await boardArchived(state.boardId)).toBe(false)

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Board settings → General → Archive board.
		await page.getByRole('button', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: 'General' }).click()
		await page.getByRole('button', { name: 'Archive board' }).click()

		// Persisted archived + landed on the board list.
		await expect.poll(() => boardArchived(state.boardId), { timeout: 8_000 }).toBe(true)
		await page.waitForSelector('.board-grid, .board-list__archived', { timeout: 10_000 })

		// The board is no longer an active tile …
		await expect(page.locator('.board-tile__title', { hasText: state.title })).toHaveCount(0)
		// … but shows under the Archived section.
		await page.locator('.board-list__archived-toggle').click()
		const row = page.locator('.board-list__archived-row', { hasText: state.title })
		await expect(row).toBeVisible({ timeout: 6_000 })

		// Unarchive restores it to active.
		await row.getByRole('button', { name: 'Unarchive' }).click()
		await expect.poll(() => boardArchived(state.boardId), { timeout: 8_000 }).toBe(false)
		await expect(page.locator('.board-tile__title', { hasText: state.title })).toBeVisible({ timeout: 6_000 })
	})
})
