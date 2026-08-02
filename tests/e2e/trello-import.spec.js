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

// A small but representative Trello board export: two lists (one out of pos
// order), cards with labels + a checklist, plus a `dueComplete` card.
function trelloFixture(stamp) {
	return {
		name: 'E2E Trello ' + stamp,
		labels: [
			{ id: 'lbl_bug', name: 'Bug', color: 'red' },
			{ id: 'lbl_feat', name: 'Feature', color: 'green' },
		],
		lists: [
			{ id: 'list_doing', name: 'Doing', pos: 200, closed: false },
			{ id: 'list_todo', name: 'Todo', pos: 100, closed: false },
		],
		cards: [
			{
				id: 'card_alpha', idList: 'list_todo', name: 'Alpha', pos: 100,
				desc: 'the alpha card', closed: false, idLabels: ['lbl_bug'],
				due: '2026-02-01T09:00:00.000Z', dueComplete: true,
			},
			{ id: 'card_beta', idList: 'list_todo', name: 'Beta', pos: 200, closed: false },
			{ id: 'card_gamma', idList: 'list_doing', name: 'Gamma', pos: 100, closed: false },
		],
		checklists: [
			{
				id: 'cl_alpha', idCard: 'card_alpha', pos: 100,
				checkItems: [
					{ name: 'design', state: 'complete', pos: 100 },
					{ name: 'ship', state: 'incomplete', pos: 200 },
				],
			},
		],
	}
}

// #3547 — Import a Trello board JSON export through the board-list UI and assert
// the new board appears with the expected stacks/cards.
test.describe('Import from Trello', () => {
	const stamp = Math.floor(Date.now() / 1000)
	let importedBoardId = 0

	test.afterAll(async () => {
		if (importedBoardId) await api('DELETE', `/boards/${importedBoardId}`).catch(() => {})
	})

	test('uploads a Trello export and opens the mirrored Kanso board', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.waitForSelector('.board-list-view', { timeout: 15_000 })

		// Upload the Trello fixture straight into the hidden file input backing the
		// "Trello (.json)" import action.
		const doc = JSON.stringify(trelloFixture(stamp))
		await page.setInputFiles('[data-test="trello-import-file"]', {
			name: 'trello.json',
			mimeType: 'application/json',
			buffer: Buffer.from(doc),
		})

		// The frontend navigates to the freshly-imported board.
		await page.waitForURL(/#\/board\/\d+/, { timeout: 20_000 })
		const m = page.url().match(/board\/(\d+)/)
		expect(m).toBeTruthy()
		importedBoardId = Number(m[1])

		// Assert the imported board mirrors the fixture via the API payload.
		const payload = await api('GET', `/boards/${importedBoardId}`)
		expect(payload.board.title).toBe('E2E Trello ' + stamp)

		const stacks = payload.stacks.slice().sort((a, b) => (a.sortKey < b.sortKey ? -1 : 1))
		// Lists imported in Trello `pos` order (Todo before Doing).
		expect(stacks.map((s) => s.title)).toEqual(['Todo', 'Doing'])

		const cards = payload.cards.slice().sort((a, b) => (a.sortKey < b.sortKey ? -1 : 1))
		const byTitle = Object.fromEntries(cards.map((c) => [c.title, c]))
		expect(Object.keys(byTitle).sort()).toEqual(['Alpha', 'Beta', 'Gamma'])

		// Alpha + Beta on Todo (pos order), Gamma on Doing.
		const todo = stacks.find((s) => s.title === 'Todo')
		const doing = stacks.find((s) => s.title === 'Doing')
		expect(byTitle.Alpha.stackId).toBe(todo.id)
		expect(byTitle.Beta.stackId).toBe(todo.id)
		expect(byTitle.Gamma.stackId).toBe(doing.id)
		expect(byTitle.Alpha.sortKey < byTitle.Beta.sortKey).toBe(true)

		// The Bug label carried onto Alpha, and dueComplete made it done.
		expect(byTitle.Alpha.labelIds.length).toBeGreaterThan(0)
		expect(payload.labels.map((l) => l.title).sort()).toEqual(['Bug', 'Feature'])
		expect(byTitle.Alpha.doneAt).toBeGreaterThan(0)

		// The card's checklist came across (open the card and check its items).
		const detail = await api('GET', `/cards/${byTitle.Alpha.id}`)
		const items = detail.checklistItems || []
		expect(items.map((i) => i.title).sort()).toEqual(['design', 'ship'])
		// state:complete → done.
		const design = items.find((i) => i.title === 'design')
		expect(design.done).toBe(true)
	})
})
