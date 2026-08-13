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

async function cardAllDay(boardId, cardId) {
	const board = await api('GET', `/boards/${boardId}`)
	return board.cards.find((c) => c.id === cardId)?.allDay
}

// #3520 — all-day due dates.
test.describe('All-day due dates', () => {
	const state = { boardId: 0, cardId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'All-Day E2E' })
		state.boardId = board.id
		const stack = await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api('POST', '/cards', { stackId: stack.id, title: 'Timed card' })
		await api('PATCH', `/cards/${card.id}`, { duedate: '2026-08-15T09:30:00+00:00' })
		state.cardId = card.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('toggling "All day" sets the flag and switches the input to a date picker', async ({ page }) => {
		expect(await cardAllDay(state.boardId, state.cardId)).toBe(false)

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.setViewportSize({ width: 1280, height: 800 })
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		// Open the due-date popover (targeted by its stable data-pill hook, so the
		// locator is robust to other attribute pills being added/reordered).
		await page.locator('.card-modal__attrbar button.card-modal__pill[data-pill="due"]').click()
		const dateInput = page.locator('.card-modal__popover .card-modal__date-input').first()
		await expect(dateInput).toHaveAttribute('type', 'datetime-local')

		// Turn on "All day".
		await page.locator('.card-modal__allday input[type=checkbox]').check()

		// Server persists the flag …
		await expect.poll(() => cardAllDay(state.boardId, state.cardId), { timeout: 8_000 }).toBe(true)
		// … and the input becomes a plain date (no time-of-day).
		await expect(dateInput).toHaveAttribute('type', 'date')
	})
})
