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
	// Session is preloaded via storageState (see playwright.config.js) for the
	// default admin context — skip the UI login round-trip entirely. Specs that
	// opt out of storageState start with no cookies and fall through to log in.
	if ((await page.context().cookies()).some((c) => c.name === 'nc_username' || c.name === 'nc_session_id')) return
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	if (!(await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false))) return
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

test.describe('My tasks (#3441)', () => {
	const state = { boardId: 0, stackId: 0, assignedCardId: 0, unassignedCardId: 0, title: '' }

	test.beforeAll(async () => {
		state.title = 'MyTask ' + Math.floor(Date.now() / 1000)
		const board = await api('POST', '/boards', { title: 'MyTasks ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api('POST', '/stacks', { boardId: board.id, title: 'To do' })).id
		state.assignedCardId = (await api('POST', '/cards', { stackId: state.stackId, title: state.title })).id
		state.unassignedCardId = (await api('POST', '/cards', { stackId: state.stackId, title: 'Not mine ' + Math.floor(Date.now() / 1000) })).id
		// Assign only the first card to admin (the current user).
		await api('PUT', `/cards/${state.assignedCardId}/assignees/${USER}`)
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('my-cards returns only cards assigned to me', async () => {
		const cards = await api('GET', '/my-cards')
		const ids = cards.map((c) => c.id)
		expect(ids).toContain(state.assignedCardId)
		expect(ids).not.toContain(state.unassignedCardId)
		const mine = cards.find((c) => c.id === state.assignedCardId)
		expect(mine.boardId).toBe(state.boardId)
		expect(mine.boardTitle).toBeTruthy()
	})

	test('a done card drops out of my tasks', async () => {
		const doneCardId = (await api('POST', '/cards', { stackId: state.stackId, title: 'Finish me ' + Math.floor(Date.now() / 1000) })).id
		await api('PUT', `/cards/${doneCardId}/assignees/${USER}`)
		expect((await api('GET', '/my-cards')).map((c) => c.id)).toContain(doneCardId)

		await api('PATCH', `/cards/${doneCardId}`, { done: true })
		expect((await api('GET', '/my-cards')).map((c) => c.id)).not.toContain(doneCardId)
	})

	test('My tasks panel lists the card and deep-links to it', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)

		const row = page.locator('.my-cards-view__row', { hasText: state.title })
		await expect(row).toBeVisible({ timeout: 15_000 })

		await row.click()
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 10_000 })
		await expect(page).toHaveURL(new RegExp(`/board/${state.boardId}/card/${state.assignedCardId}`))
	})
})
