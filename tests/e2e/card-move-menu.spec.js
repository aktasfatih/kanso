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

// The cards in a stack, top-to-bottom, from the board summary payload.
async function stackOrder(boardId, stackId) {
	const board = await api('GET', `/boards/${boardId}`)
	return board.cards
		.filter((c) => c.stackId === stackId && !c.archived)
		.sort((a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0))
		.map((c) => c.title)
}

// #3516 — "Move to top" / "Move to bottom" from the card ⋯ menu.
test.describe('Move card to top / bottom (card ⋯ menu)', () => {
	const state = { boardId: 0, stackId: 0, midId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Move-Menu E2E' })
		state.boardId = board.id
		state.stackId = (await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })).id
		// Created in order → A (top), B (middle), C (bottom).
		await api('POST', '/cards', { stackId: state.stackId, title: 'Card A' })
		const b = await api('POST', '/cards', { stackId: state.stackId, title: 'Card B' })
		await api('POST', '/cards', { stackId: state.stackId, title: 'Card C' })
		state.midId = b.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${b.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('the middle card moves to the top, then to the bottom', async ({ page }) => {
		expect(await stackOrder(state.boardId, state.stackId)).toEqual(['Card A', 'Card B', 'Card C'])

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Open the ⋯ menu and click "Move to top".
		await page.locator('.card-modal__actions-menu button').first().click()
		await page.getByRole('menuitem', { name: 'Move to top' }).click()
		await expect
			.poll(() => stackOrder(state.boardId, state.stackId), { timeout: 8_000 })
			.toEqual(['Card B', 'Card A', 'Card C'])

		// Now move it to the bottom.
		await page.locator('.card-modal__actions-menu button').first().click()
		await page.getByRole('menuitem', { name: 'Move to bottom' }).click()
		await expect
			.poll(() => stackOrder(state.boardId, state.stackId), { timeout: 8_000 })
			.toEqual(['Card A', 'Card C', 'Card B'])
	})
})
