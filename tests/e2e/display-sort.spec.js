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

test.describe('Display sort (#3442)', () => {
	const state = { boardId: 0 }
	// Created in this order → manual (fractional) order is Charlie, Alpha, Bravo.
	const created = ['Charlie', 'Alpha', 'Bravo']

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Sort ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api('POST', '/stacks', { boardId: board.id, title: 'To do' })
		for (const title of created) {
			await api('POST', '/cards', { stackId: stack.id, title })
		}
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('sorting by Title reorders the rows; Manual restores fractional order', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Switch to List view for a deterministic row order.
		await page.locator('.board-view__view-menu button').first().click()
		await page.getByText('List', { exact: true }).click()
		await expect(page.locator('.board-list-row').first()).toBeVisible({ timeout: 8_000 })

		const titles = () => page.locator('.board-list-row__title').allTextContents()

		// Manual order = creation (fractional) order.
		expect((await titles()).map((s) => s.trim())).toEqual(created)

		// Sort by Title → alphabetical.
		await page.locator('.board-view__sort-menu button').first().click()
		await page.getByText('Title', { exact: true }).click()
		await expect
			.poll(async () => (await titles()).map((s) => s.trim()))
			.toEqual(['Alpha', 'Bravo', 'Charlie'])

		// Back to Manual → fractional order restored (view-only sort, keys intact).
		await page.locator('.board-view__sort-menu button').first().click()
		await page.getByText('Manual', { exact: true }).click()
		await expect
			.poll(async () => (await titles()).map((s) => s.trim()))
			.toEqual(created)
	})
})
