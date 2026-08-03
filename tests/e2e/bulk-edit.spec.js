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

// Titles of the cards currently in a stack (from the board summary payload).
async function stackTitles(boardId, stackId) {
	const board = await api('GET', `/boards/${boardId}`)
	return board.cards
		.filter((c) => c.stackId === stackId && !c.archived)
		.map((c) => c.title)
		.sort()
}

// #3523 — multi-select cards, then bulk-move them to another stack.
test.describe('Bulk edit cards (multi-select)', () => {
	const state = { boardId: 0, todoId: 0, doneId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Bulk-Edit E2E' })
		state.boardId = board.id
		state.todoId = (await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })).id
		state.doneId = (await api('POST', '/stacks', { boardId: board.id, title: 'Done' })).id
		await api('POST', '/cards', { stackId: state.todoId, title: 'Alpha' })
		await api('POST', '/cards', { stackId: state.todoId, title: 'Bravo' })
		await api('POST', '/cards', { stackId: state.todoId, title: 'Charlie' })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('selecting two cards and bulk-moving lands them in the target stack', async ({ page }) => {
		expect(await stackTitles(state.boardId, state.todoId)).toEqual(['Alpha', 'Bravo', 'Charlie'])
		expect(await stackTitles(state.boardId, state.doneId)).toEqual([])

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 15_000 })
		await expect(page.locator('.card-tile', { hasText: 'Alpha' })).toBeVisible({ timeout: 10_000 })

		// Enter multi-select mode via the toolbar toggle.
		await page.getByRole('button', { name: 'Select multiple cards' }).click()

		// Selection checkboxes now appear on the tiles; select Alpha and Bravo.
		await page.locator('.card-tile', { hasText: 'Alpha' }).click()
		await page.locator('.card-tile', { hasText: 'Bravo' }).click()

		// The bulk action bar reports the selection count.
		await expect(page.locator('.bulk-action-bar')).toContainText('2')

		// Open the "Move to…" menu and pick the Done stack.
		await page.getByRole('button', { name: 'Move to…' }).click()
		await page.getByRole('menuitem', { name: 'Done' }).click()

		// Both selected cards land in Done; Charlie stays in To Do.
		await expect
			.poll(() => stackTitles(state.boardId, state.doneId), { timeout: 10_000 })
			.toEqual(['Alpha', 'Bravo'])
		await expect
			.poll(() => stackTitles(state.boardId, state.todoId), { timeout: 10_000 })
			.toEqual(['Charlie'])
	})
})
