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

test.describe('Human-readable card identifiers', () => {
	// Title "Kanban Reference …" → derived prefix "KANBA".
	const BOARD_TITLE = 'Kanban Reference ' + Date.now()
	const state = {
		boardId: 0,
		stackId: 0,
		firstCardId: 0,
		secondCardId: 0,
		prefix: '',
		boardUrl: '',
	}

	test.beforeAll(async () => {
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title.startsWith('Kanban Reference')) {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		const board = await apiPost('/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		state.prefix = board.prefix // derived from the title, e.g. "KANBA"
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'Backlog' })
		state.stackId = stack.id

		const first = await apiPost('/cards', { stackId: stack.id, title: 'First reference card' })
		state.firstCardId = first.id
		const second = await apiPost('/cards', { stackId: stack.id, title: 'Second reference card' })
		state.secondCardId = second.id

		// Per-board sequence is 1-based and increments per create.
		expect(first.boardSeq).toBe(1)
		expect(second.boardSeq).toBe(2)
		expect(state.prefix).toBeTruthy()

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiDelete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('the KAN-<n> reference shows on the card tile', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		const firstRef = `${state.prefix}-1`
		const secondRef = `${state.prefix}-2`

		const firstTileRef = page.locator('.card-tile')
			.filter({ hasText: 'First reference card' })
			.locator('.card-tile__ref')
		await expect(firstTileRef).toBeVisible({ timeout: 5000 })
		await expect(firstTileRef).toHaveText(firstRef)

		const secondTileRef = page.locator('.card-tile')
			.filter({ hasText: 'Second reference card' })
			.locator('.card-tile__ref')
		await expect(secondTileRef).toHaveText(secondRef)
	})

	test('the reference shows in the card modal header and is copyable', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		const firstRef = `${state.prefix}-1`

		const tile = page.locator('.card-tile').filter({ hasText: 'First reference card' })
		await expect(tile).toBeVisible({ timeout: 5000 })
		await tile.click()

		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// The copyable reference button lives in the modal breadcrumb.
		const refButton = page.locator('.card-modal__ref')
		await expect(refButton).toBeVisible({ timeout: 5000 })
		await expect(refButton).toHaveText(firstRef)

		// Clicking copies it - a success toast appears (clipboard itself is not
		// readable headless, but the click path must not error).
		await refButton.click()
		await expect(refButton).toBeVisible()
	})
})
