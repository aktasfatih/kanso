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

async function openBoardsPage(page) {
	await page.goto(`${BASE}/index.php/apps/kanso#/`)
	await page.waitForSelector('.board-list-view', { timeout: 15_000 })
}

async function openTileMenu(page, boardId, title) {
	const tile = page.locator('.board-tile', { hasText: title }).first()
	await expect(tile).toBeVisible({ timeout: 10_000 })
	await tile.locator(`[data-test="board-options-menu-${boardId}"] button`).first().click()
}

// #3750 — boards-view tile menu carries the heavyweight board operations
// (duplicate / export / archive / delete) that previously lived only inside
// the board's settings modal.
test.describe('Boards page — tile options menu board actions', () => {
	const stamp = Math.floor(Date.now() / 1000)
	const state = { extraBoards: [] }

	test.afterAll(async () => {
		for (const id of state.extraBoards) {
			await api('DELETE', `/boards/${id}`).catch(() => {})
		}
	})

	test('duplicates a board (with cards) from the tile menu and stays on the boards page', async ({ page }) => {
		const title = 'TileMenu Dup ' + stamp
		const board = await api('POST', '/boards', { title })
		state.extraBoards.push(board.id)
		const todo = await api('POST', '/stacks', { boardId: board.id, title: 'To do' })
		await api('POST', '/cards', { stackId: todo.id, title: 'Clone me' })

		await ncLogin(page)
		await openBoardsPage(page)

		await openTileMenu(page, board.id, title)
		await page.locator(`[data-test="tile-duplicate-with-cards-${board.id}"]`).first().click()

		// The copy appears in the grid — the tile menu does NOT navigate away
		// from the boards page (unlike the settings-modal duplicate).
		const copyTile = page.locator('.board-tile', { hasText: `${title} (copy)` }).first()
		await expect(copyTile).toBeVisible({ timeout: 15_000 })
		await expect(page.locator('.board-list-view')).toBeVisible()

		// The copy carries the cards (withCards=true), verified via the API.
		const boards = await api('GET', '/boards')
		const copy = boards.find((b) => b.title === `${title} (copy)`)
		expect(copy).toBeTruthy()
		state.extraBoards.push(copy.id)
		const doc = await api('GET', `/boards/${copy.id}/export`)
		expect(doc.board.cards.map((c) => c.title)).toEqual(['Clone me'])
	})

	test('deletes a board from the tile menu after an explicit confirm', async ({ page }) => {
		const title = 'TileMenu Del ' + stamp
		const board = await api('POST', '/boards', { title })
		state.extraBoards.push(board.id)

		await ncLogin(page)
		await openBoardsPage(page)

		await openTileMenu(page, board.id, title)
		await page.locator(`[data-test="tile-delete-${board.id}"]`).first().click()

		// The confirm dialog opens; Cancel keeps the board.
		const dialog = page.locator('[data-test="tile-delete-confirm"]')
		await expect(dialog).toBeVisible({ timeout: 6_000 })
		await dialog.getByRole('button', { name: 'Cancel' }).click()
		await expect(dialog).toHaveCount(0, { timeout: 6_000 })
		await expect(page.locator('.board-tile', { hasText: title }).first()).toBeVisible()

		// Delete again and confirm — the tile disappears from the grid.
		await openTileMenu(page, board.id, title)
		await page.locator(`[data-test="tile-delete-${board.id}"]`).first().click()
		await expect(dialog).toBeVisible({ timeout: 6_000 })
		await page.locator('[data-test="tile-delete-confirm-yes"]').click()
		await expect(page.locator('.board-tile', { hasText: title })).toHaveCount(0, { timeout: 15_000 })

		// Server truth: the board is gone from the boards list.
		const boards = await api('GET', '/boards')
		expect(boards.find((b) => b.id === board.id)).toBeFalsy()
	})

	test('archives from the tile menu, then unarchives from the archived tile menu', async ({ page }) => {
		const title = 'TileMenu Arch ' + stamp
		const board = await api('POST', '/boards', { title })
		state.extraBoards.push(board.id)

		await ncLogin(page)
		await openBoardsPage(page)

		// Archive from the active tile's menu — the tile leaves the active grid.
		await openTileMenu(page, board.id, title)
		await page.locator(`[data-test="tile-archive-${board.id}"]`).first().click()
		await expect(page.locator('.board-tile', { hasText: title })).toHaveCount(0, { timeout: 15_000 })

		// The archived section shows it, with the same options menu (the old
		// standalone Unarchive button is folded into it).
		await page.getByRole('button', { name: /Archived/ }).click()
		await openTileMenu(page, board.id, title)
		await page.locator(`[data-test="tile-unarchive-${board.id}"]`).first().click()
		await expect(page.locator('.board-tile', { hasText: title })).toHaveCount(0, { timeout: 15_000 })

		// Back on Active it is restored.
		await page.getByRole('button', { name: 'Active' }).click()
		await expect(page.locator('.board-tile', { hasText: title }).first()).toBeVisible({ timeout: 10_000 })
	})
})
