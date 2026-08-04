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

// #3632 — per-user board pinning drives BOTH the boards-page Pinned section and
// the left-sidebar nav (curated nav). Zero-pins fallback shows all boards.
test.describe('Board pinning (#3632)', () => {
	const state = { boardId: 0, title: 'Pin Board ' + Math.floor(Date.now() / 1000) }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: state.title, color: 'e67e22' })
		state.boardId = board.id
	})

	test.afterAll(async () => {
		// Drop the pin first (own row), then the board, so no pin lingers for
		// other specs (the nav zero-pins fallback depends on admin having no pins).
		if (state.boardId) {
			await api('DELETE', `/boards/${state.boardId}/pin`).catch(() => {})
			await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('pin a board via its tile options menu → it shows in the Pinned section and the nav; unpin removes it; persists across reload', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.waitForSelector('.board-list-view', { timeout: 15_000 })

		const tile = page.locator('.board-tile', { hasText: state.title }).first()
		await expect(tile).toBeVisible({ timeout: 10_000 })

		// Zero-pins fallback: with no pins, the board is listed in the nav (all
		// boards show).
		const navLink = page.locator('.app-navigation .app-navigation-entry-link', { hasText: state.title })
		await expect(navLink.first()).toBeVisible({ timeout: 10_000 })

		// No Pinned section yet for this board.
		const pinnedSection = page.locator('.board-section', { hasText: 'Pinned' })
		await expect(pinnedSection.locator('.board-tile', { hasText: state.title })).toHaveCount(0)

		// Open the tile's options (⋯) menu and click Pin — must NOT navigate.
		await tile.locator(`[data-test="board-options-menu-${state.boardId}"] button`).first().click()
		await page.locator(`[data-test="toggle-pin-${state.boardId}"]`).first().click()
		await expect(page).toHaveURL(/#\/$|apps\/kanso#\/$|apps\/kanso#\//, { timeout: 3_000 })

		// The board now appears in the Pinned section.
		await expect(page.locator('.board-section', { hasText: 'Pinned' })
			.locator('.board-tile', { hasText: state.title }).first())
			.toBeVisible({ timeout: 10_000 })

		// Reload — the pin persists (server truth) and the board is still pinned.
		await page.reload()
		await page.waitForSelector('.board-list-view', { timeout: 15_000 })
		await expect(page.locator('.board-section', { hasText: 'Pinned' })
			.locator('.board-tile', { hasText: state.title }).first())
			.toBeVisible({ timeout: 10_000 })
		// Still listed in the nav.
		await expect(navLink.first()).toBeVisible({ timeout: 10_000 })

		// Unpin from the Pinned section tile's options menu.
		const pinnedTile = page.locator('.board-section', { hasText: 'Pinned' })
			.locator('.board-tile', { hasText: state.title }).first()
		await pinnedTile.locator(`[data-test="board-options-menu-${state.boardId}"] button`).first().click()
		await page.locator(`[data-test="toggle-pin-${state.boardId}"]`).first().click()

		// It leaves the Pinned section.
		await expect(page.locator('.board-section', { hasText: 'Pinned' })
			.locator('.board-tile', { hasText: state.title })).toHaveCount(0, { timeout: 10_000 })
	})

	// #3643 — pinning is an ADDITIVE shortcut: a board that lives in a folder must
	// stay in its folder AND also appear under Pinned (the folder must NOT empty).
	test('pinning a foldered board keeps it in its folder and also surfaces it under Pinned', async ({ page }) => {
		const stamp = Math.floor(Date.now() / 1000)
		const folder = await api('POST', '/board-groups', { name: 'Pin Folder ' + stamp })
		const board = await api('POST', '/boards', { title: 'Foldered Pin ' + stamp, color: '3498db' })
		await api('PUT', `/board-groups/${folder.id}/boards/${board.id}`)
		try {
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/`)
			await page.waitForSelector('.board-list-view', { timeout: 15_000 })

			const folderSection = page.locator(`[data-test="folder-section-${folder.id}"]`)
			// The board sits in its folder and is not pinned yet.
			await expect(folderSection.locator('.board-tile', { hasText: board.title }).first())
				.toBeVisible({ timeout: 10_000 })
			await expect(page.locator('.board-section', { hasText: 'Pinned' })
				.locator('.board-tile', { hasText: board.title })).toHaveCount(0)

			// Pin it from the folder tile's options menu.
			await folderSection.locator(`[data-test="board-options-menu-${board.id}"] button`).first().click()
			await page.locator(`[data-test="toggle-pin-${board.id}"]`).first().click()

			// It now appears under Pinned AND remains in its folder — folder not emptied.
			await expect(page.locator('.board-section', { hasText: 'Pinned' })
				.locator('.board-tile', { hasText: board.title }).first())
				.toBeVisible({ timeout: 10_000 })
			await expect(folderSection.locator('.board-tile', { hasText: board.title }).first())
				.toBeVisible()
			await expect(folderSection.locator('.board-section__empty')).toHaveCount(0)
		} finally {
			await api('DELETE', `/boards/${board.id}/pin`).catch(() => {})
			await api('DELETE', `/boards/${board.id}`).catch(() => {})
			await api('DELETE', `/board-groups/${folder.id}`).catch(() => {})
		}
	})
})
