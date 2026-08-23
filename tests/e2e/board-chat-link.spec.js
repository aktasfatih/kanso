// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

async function openGeneralSettings(page) {
	// Board settings lives in the consolidated ⋯ More overflow menu.
	await page.getByRole('button', { name: 'More' }).click()
	await page.getByRole('menuitem', { name: /board settings/i }).click()
	await page.getByRole('tab', { name: /general/i }).click()
	await expect(page.locator('#bs-pane-general')).toBeVisible({ timeout: 8_000 })
}

// Project chat link (#3748): a per-board plain URL (typically a Talk room)
// set in board settings (MANAGE) and surfaced to every member as a toolbar
// button that opens in a new tab. Deliberately dumb - no Talk API.
test.describe('Project chat link (#3748)', () => {
	const state = { boardId: 0 }
	const CHAT_URL = 'https://cloud.example.com/call/abc123'

	test.beforeAll(async () => {
		const board = await api.send('POST', '/boards', { title: 'Chat Link E2E ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		await api.send('POST', '/stacks', { boardId: board.id, title: 'To Do' })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.send('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('setting a chat URL persists and shows the toolbar button with the right href', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// No chat button while the board has no chat URL.
		await expect(page.locator('[data-test="board-chat-btn"]')).toHaveCount(0)

		// Set the URL from board settings.
		await openGeneralSettings(page)
		await page.locator('[data-test="board-chat-url-input"]').fill(CHAT_URL)
		await page.locator('[data-test="board-chat-url-save"]').click()

		// The toolbar button appears, points at the URL, and opens a new tab
		// (NcButton adds rel="nofollow noreferrer noopener" for href buttons).
		const chatBtn = page.locator('[data-test="board-chat-btn"]')
		await expect(chatBtn).toBeVisible({ timeout: 8_000 })
		await expect(chatBtn).toHaveAttribute('href', CHAT_URL)
		await expect(chatBtn).toHaveAttribute('target', '_blank')
		await expect(chatBtn).toHaveAttribute('rel', /noopener/)
		await expect(chatBtn).toHaveAttribute('rel', /noreferrer/)

		// It persists across a reload (server is the source of truth), and the
		// settings field is seeded with the stored value.
		await page.reload()
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await expect(page.locator('[data-test="board-chat-btn"]')).toHaveAttribute('href', CHAT_URL, { timeout: 8_000 })
		await openGeneralSettings(page)
		await expect(page.locator('[data-test="board-chat-url-input"]')).toHaveValue(CHAT_URL)
	})

	test('an invalid scheme is rejected inline and clearing removes the button', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		await openGeneralSettings(page)

		// A non-http(s) scheme never reaches the server - inline error instead.
		await page.locator('[data-test="board-chat-url-input"]').fill('javascript:alert(1)')
		await page.locator('[data-test="board-chat-url-save"]').click()
		await expect(page.locator('[data-test="board-chat-url-error"]')).toBeVisible()

		// Clearing the field removes the link and the toolbar button.
		await page.locator('[data-test="board-chat-url-input"]').fill('')
		await page.locator('[data-test="board-chat-url-save"]').click()
		await expect(page.locator('[data-test="board-chat-btn"]')).toHaveCount(0, { timeout: 8_000 })
	})
})
