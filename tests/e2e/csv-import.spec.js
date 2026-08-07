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

// #3678 — Import cards from a CSV into an EXISTING board's stack via the
// board-list Import menu, then assert the mapped cards landed on that stack.
test.describe('Import cards from CSV', () => {
	const stamp = Math.floor(Date.now() / 1000)
	const state = {}

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'CSV Target ' + stamp })
		state.boardId = board.id
		state.boardTitle = board.title
		const stack = await api('POST', '/stacks', { boardId: board.id, title: 'Inbox' })
		state.stackId = stack.id
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('pastes a CSV, maps columns, and creates the cards in the chosen stack', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.waitForSelector('.board-list-view', { timeout: 15_000 })

		// Open the Import menu and pick "CSV file".
		await page.getByRole('button', { name: 'Import' }).click()
		await page.getByText('CSV file', { exact: true }).click()

		// Step 1: paste a small CSV (header + three data rows) and continue.
		const csv = [
			'title,description,due date,labels',
			'Design login,Wireframe the flow,2026-02-01,ux',
			'Build API,,,backend',
			'Ship it,Final polish,,',
		].join('\n')
		await page.locator('[data-test="csv-import-paste"]').fill(csv)
		await page.locator('[data-test="csv-import-next"]').click()

		// Step 2: choose the target board + its stack. (Auto-detection already
		// mapped title/description/due/labels from the header row.)
		await page.locator('[data-test="csv-import-board"]').selectOption({ label: state.boardTitle })
		await page.locator('[data-test="csv-import-stack"]').selectOption({ label: 'Inbox' })

		await page.locator('[data-test="csv-import-submit"]').click()

		// The FE navigates to the populated board once the import returns.
		await page.waitForURL(new RegExp(`#/board/${state.boardId}\\b`), { timeout: 20_000 })

		// Assert the three cards landed on the Inbox stack, in file order, with the
		// mapped description + match-or-created labels.
		const payload = await api('GET', `/boards/${state.boardId}`)
		const cards = payload.cards
			.filter((c) => c.stackId === state.stackId)
			.slice()
			.sort((a, b) => (a.sortKey < b.sortKey ? -1 : 1))
		expect(cards.map((c) => c.title)).toEqual(['Design login', 'Build API', 'Ship it'])

		const byTitle = Object.fromEntries(cards.map((c) => [c.title, c]))
		// The labels column auto-created "ux" + "backend" on the board.
		const labelByTitle = Object.fromEntries(payload.labels.map((l) => [l.title, l.id]))
		expect(labelByTitle.ux).toBeTruthy()
		expect(labelByTitle.backend).toBeTruthy()
		expect(byTitle['Design login'].labelIds).toContain(labelByTitle.ux)
		expect(byTitle['Build API'].labelIds).toContain(labelByTitle.backend)

		// The mapped description + due date came across for the first card.
		const detail = await api('GET', `/cards/${byTitle['Design login'].id}`)
		expect(detail.description).toBe('Wireframe the flow')
		expect(byTitle['Design login'].duedate).toBeTruthy()
	})
})
