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

test.describe('Labels', () => {
	const state = { boardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'Labels Test Board') {
				await apiDelete(`/boards/${b.id}`)
			}
		}
		const board = await apiPost('/boards', { title: 'Labels Test Board' })
		state.boardId = board.id
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'S1' })
		await apiPost('/cards', { stackId: stack.id, title: 'Card X' })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test('colored label created via the settings panel renders colored everywhere', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.getByRole('button', { name: /board settings/i }).click()

		// Pick the first preset (red e74c3c), name it, create it
		await page.getByRole('button', { name: /pick color for new label/i }).click()
		await page.locator('.label-settings__color-option').first().click()
		await page.getByLabel(/new label name/i).fill('ColoredE2E')
		await page.getByRole('button', { name: /create label/i }).click()

		// Created without error, swatch actually painted red
		const item = page.locator('.label-settings__item', { hasText: 'ColoredE2E' })
		await expect(item).toHaveCount(1)
		await expect(page.locator('.label-settings__error')).toHaveCount(0)
		const swatchBg = await item.locator('.label-settings__swatch')
			.evaluate((el) => getComputedStyle(el).backgroundColor)
		expect(swatchBg).toBe('rgb(231, 76, 60)') // preset e74c3c

		// Server stored the bare-hex color
		const boardPayload = await apiGet(`/boards/${state.boardId}`)
		const label = boardPayload.labels.find((l) => l.title === 'ColoredE2E')
		expect(label?.color).toBe('e74c3c')

		// Filter chip in the header exists for it
		await page.keyboard.press('Escape')
		await expect(page.locator('.board-view__filter-chip', { hasText: 'ColoredE2E' })).toHaveCount(1)
	})
})
