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

// The all-day ISO the client produces for tomorrow's local calendar day:
// new Date("YYYY-MM-DD").toISOString() → "...T00:00:00.000Z".
function tomorrowAllDayIso() {
	const now = new Date()
	const t = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1)
	const pad = (n) => String(n).padStart(2, '0')
	const ymd = `${t.getFullYear()}-${pad(t.getMonth() + 1)}-${pad(t.getDate())}`
	return new Date(ymd).toISOString()
}

// #3416 — natural due-date tokens: a recognized trailing token in the composer
// sets the card's due date (all-day) and is stripped from the title.
test.describe('Composer due-date tokens', () => {
	const state = { boardId: 0, stackId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Due Tokens E2E' })
		state.boardId = board.id
		state.stackId = (await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })).id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	async function cards() {
		const board = await api('GET', `/boards/${state.boardId}`)
		return board.cards.filter((c) => c.stackId === state.stackId && !c.archived)
	}

	test('"Ship it !tomorrow" strips the token and sets tomorrow as the due date', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 15_000 })

		const composer = page.locator('.stack-column').first().locator('.card-composer__input')
		await expect(composer).toBeVisible({ timeout: 10_000 })

		await composer.fill('Ship it !tomorrow')
		await composer.press('Enter')

		// The persisted card carries the stripped title + tomorrow's all-day due date.
		await expect.poll(() => cards().then((cs) => cs.map((c) => c.title)), {
			timeout: 10_000,
		}).toEqual(['Ship it'])

		const [card] = await cards()
		expect(new Date(card.duedate).toISOString()).toBe(tomorrowAllDayIso())
		expect(card.allDay).toBe(true)
	})

	test('a mid-title "!" is not treated as a token', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 15_000 })

		const composer = page.locator('.stack-column').first().locator('.card-composer__input')
		await expect(composer).toBeVisible({ timeout: 10_000 })

		await composer.fill('Fix the !important bug')
		await composer.press('Enter')

		// The title is left intact and no due date is set.
		await expect.poll(() => cards().then((cs) => cs.map((c) => c.title)), {
			timeout: 10_000,
		}).toContain('Fix the !important bug')

		const created = (await cards()).find((c) => c.title === 'Fix the !important bug')
		expect(created).toBeTruthy()
		expect(created.duedate).toBeFalsy()
	})
})
