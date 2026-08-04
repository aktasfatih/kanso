// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')

async function apiSend(method, path, body) {
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

test.describe('Board grouping / folders (#3529)', () => {
	const state = {
		boardId: 0,
		title: 'Grouped Board ' + Math.floor(Date.now() / 1000),
		folderName: 'Folder ' + Math.floor(Date.now() / 1000),
		createdGroupIds: [],
	}

	test.beforeAll(async () => {
		const board = await apiSend('POST', '/boards', { title: state.title, color: '2ecc71' })
		state.boardId = board.id
	})

	test.afterAll(async () => {
		for (const id of state.createdGroupIds) {
			await apiSend('DELETE', `/board-groups/${id}`).catch(() => {})
		}
		if (state.boardId) {
			await apiSend('DELETE', `/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('create a folder, move a board in, reload, assert it is grouped', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.waitForSelector('.board-list-view', { timeout: 15_000 })

		// Create a folder via the inline form.
		await page.getByRole('button', { name: 'New folder' }).click()
		const folderInput = page.locator('[data-test="new-folder-input"]')
		await expect(folderInput).toBeVisible({ timeout: 10_000 })
		await folderInput.fill(state.folderName)
		await page.getByRole('button', { name: 'Create folder' }).click()

		// The folder section appears.
		const folderSection = page.locator('.board-section', { hasText: state.folderName })
		await expect(folderSection.first()).toBeVisible({ timeout: 10_000 })

		// The new board starts Ungrouped: open its folder menu and move it in.
		const ungrouped = page.locator('[data-test="folder-section-ungrouped"]')
		const tile = ungrouped.locator('.board-list__tile-wrap', { hasText: state.title })
		await expect(tile.first()).toBeVisible({ timeout: 10_000 })
		await tile.first().locator('[data-test^="board-options-menu-"] button').first().click()

		// Pick the folder from the move menu.
		await page.locator('[data-test^="move-to-folder-"]', { hasText: state.folderName })
			.first()
			.click()

		// The board now sits inside the folder section.
		await expect(
			folderSection.first().locator('.board-list__tile-wrap', { hasText: state.title }).first(),
		).toBeVisible({ timeout: 10_000 })

		// Capture the created folder id for cleanup, then reload and re-assert.
		const groups = await apiSend('GET', '/board-groups')
		const created = groups.find((g) => g.name === state.folderName)
		expect(created).toBeTruthy()
		expect(created.boardIds.map(Number)).toContain(Number(state.boardId))
		state.createdGroupIds.push(created.id)

		await page.reload()
		await page.waitForSelector('.board-list-view', { timeout: 15_000 })
		const folderAfter = page.locator('.board-section', { hasText: state.folderName })
		await expect(
			folderAfter.first().locator('.board-list__tile-wrap', { hasText: state.title }).first(),
		).toBeVisible({ timeout: 10_000 })
	})
})
