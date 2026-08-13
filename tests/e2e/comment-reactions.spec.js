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

test.describe('Comment reactions (#3550)', () => {
	const state = { boardId: 0, cardId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'Reactions E2E Board') {
				await apiDelete(`/boards/${b.id}`)
			}
		}
		const board = await apiPost('/boards', { title: 'Reactions E2E Board' })
		state.boardId = board.id
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'To Do' })
		const card = await apiPost('/cards', { stackId: stack.id, title: 'Card To React On' })
		state.cardId = card.id
		// Seed a top-level comment so the thread has a reaction target.
		await apiPost(`/cards/${card.id}/comments`, { body: 'React to me' })
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiDelete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('react to a comment → chip shows count 1 + highlighted; toggle off → chip gone', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// The seeded comment should be present.
		const topComment = page.locator('.card-modal__comment-group > .card-modal__comment').first()
		await expect(topComment).toBeVisible({ timeout: 8000 })

		// Open the add-reaction picker on the top-level comment and pick 👍.
		const addBtn = topComment.locator('.card-modal__reaction-add')
		await expect(addBtn).toBeVisible({ timeout: 5000 })
		await addBtn.click()

		const picker = topComment.locator('.card-modal__reaction-picker')
		await expect(picker).toBeVisible({ timeout: 4000 })
		// First emoji in the fixed set is 👍.
		await picker.locator('.card-modal__reaction-picker-btn').first().click()

		// A chip appears with count 1 and is highlighted as "mine".
		const chip = topComment.locator('.card-modal__reaction-chip').first()
		await expect(chip).toBeVisible({ timeout: 6000 })
		await expect(chip).toHaveClass(/card-modal__reaction-chip--mine/, { timeout: 4000 })
		await expect(chip.locator('.card-modal__reaction-count')).toHaveText('1', { timeout: 4000 })

		// Clicking the highlighted chip toggles the reaction off → chip disappears.
		await chip.click()
		await expect(topComment.locator('.card-modal__reaction-chip')).toHaveCount(0, { timeout: 6000 })
	})

	test('reaction persists across reload', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const topComment = page.locator('.card-modal__comment-group > .card-modal__comment').first()
		await expect(topComment).toBeVisible({ timeout: 8000 })

		// React with 🎉 (a later emoji in the fixed set).
		await topComment.locator('.card-modal__reaction-add').click()
		const picker = topComment.locator('.card-modal__reaction-picker')
		await expect(picker).toBeVisible({ timeout: 4000 })
		await picker.getByTitle('🎉').click()

		await expect(topComment.locator('.card-modal__reaction-chip')).toHaveCount(1, { timeout: 6000 })

		// Reload: the chip should still be there (server truth), highlighted for me.
		await page.reload()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })
		const reloadedChip = page.locator('.card-modal__comment-group > .card-modal__comment')
			.first().locator('.card-modal__reaction-chip').first()
		await expect(reloadedChip).toBeVisible({ timeout: 8000 })
		await expect(reloadedChip).toHaveClass(/card-modal__reaction-chip--mine/, { timeout: 4000 })
	})
})
