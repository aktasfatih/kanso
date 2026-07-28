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

const ROLE_IN_PROGRESS = 3

test.describe('Card status (#3481)', () => {
	const state = { boardId: 0, todoStackId: 0, progStackId: 0, autoCardId: 0, manualCardId: 0 }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Status ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.todoStackId = (await api('POST', '/stacks', { boardId: board.id, title: 'To do' })).id
		state.progStackId = (await api('POST', '/stacks', { boardId: board.id, title: 'Doing' })).id
		await api('PATCH', `/stacks/${state.progStackId}`, { role: ROLE_IN_PROGRESS })
		state.autoCardId = (await api('POST', '/cards', { stackId: state.todoStackId, title: 'Auto-started card' })).id
		state.manualCardId = (await api('POST', '/cards', { stackId: state.todoStackId, title: 'Manual status card' })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('moving a card into an in-progress-role column auto-starts it', async () => {
		let card = await api('GET', `/cards/${state.autoCardId}`)
		expect(Number(card.startedAt)).toBe(0)

		await api('POST', `/cards/${state.autoCardId}/move`, { targetStackId: state.progStackId, afterCardId: null })

		card = await api('GET', `/cards/${state.autoCardId}`)
		expect(Number(card.startedAt)).toBeGreaterThan(0)
		expect(Number(card.doneAt)).toBe(0)
	})

	test('status can be set from the card view and shows on the tile', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.manualCardId}`)
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 15_000 })

		// Set "In progress" from the card's status control (breadcrumb chip → dropdown).
		await page.locator('.card-modal__status-chip--btn').click()
		await page.locator('.card-modal__status-wrap .card-modal__popover-opt', { hasText: 'In progress' }).click()
		await expect(page.locator('.card-modal__status-chip--in_progress')).toBeVisible({ timeout: 6_000 })

		// Close the modal → the board tile shows the In-progress chip.
		await page.keyboard.press('Escape')
		const tile = page.locator('.card-tile', { hasText: 'Manual status card' })
		await expect(tile.locator('.card-tile__inprogress')).toBeVisible({ timeout: 8_000 })
	})
})
