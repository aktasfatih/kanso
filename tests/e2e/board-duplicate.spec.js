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

// Duplicate board (#3543): a READ-authorized board is cloned server-side into a
// FRESH board the caller owns (export→import in-process). "Copy cards too"
// controls whether the card graph rides along; either way stacks/labels/rules
// are cloned and the new copy opens.
test.describe('Duplicate board (#3543)', () => {
	const state = { boardId: 0, copyBoardId: 0 }
	const title = 'Duplicate E2E ' + Math.floor(Date.now() / 1000)

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title })
		state.boardId = board.id
		const todo = await api('POST', '/stacks', { boardId: board.id, title: 'To do' })
		await api('POST', '/stacks', { boardId: board.id, title: 'Done' })
		await api('POST', '/labels', { boardId: board.id, title: 'Priority', color: 'e11d48' })
		await api('POST', '/cards', { stackId: todo.id, title: 'Alpha' })
		await api('POST', '/cards', { stackId: todo.id, title: 'Beta' })
	})

	test.afterAll(async () => {
		if (state.copyBoardId) await api('DELETE', `/boards/${state.copyBoardId}`).catch(() => {})
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('duplicates a populated board with cards and opens the copy', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Board settings now lives in the consolidated ⋯ More overflow menu.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()

		// Board actions moved into the General tab; open it first.
		await page.getByRole('tab', { name: 'General' }).click()

		// The duplicate action lives in the General tab's board-actions block,
		// next to Export.
		const dupBtn = page.locator('[data-test="board-duplicate"]')
		await expect(dupBtn).toBeVisible({ timeout: 8_000 })

		// "Copy cards too" defaults on; assert and keep it on for this run.
		const withCards = page.locator('[data-test="board-duplicate-with-cards"]')
		await expect(withCards).toBeChecked()

		await dupBtn.click()

		// The router navigates to the new copy: URL changes to a different board id.
		await page.waitForURL(
			(url) => /#\/board\/\d+/.test(url.hash) && !url.hash.includes(`/board/${state.boardId}`),
			{ timeout: 15_000 },
		)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		const m = page.url().match(/\/board\/(\d+)/)
		state.copyBoardId = Number(m[1])
		expect(state.copyBoardId).not.toBe(state.boardId)

		// The copy carries the source stacks + cards (verified through the API,
		// which is the source of truth the board view renders from).
		const copy = await api('GET', `/boards/${state.copyBoardId}/export`)
		expect(copy.board.title).toBe(`${title} (copy)`)
		expect(copy.board.stacks.map((s) => s.title).sort()).toEqual(['Done', 'To do'])
		expect(copy.board.labels.map((l) => l.title)).toEqual(['Priority'])
		expect(copy.board.cards.map((c) => c.title).sort()).toEqual(['Alpha', 'Beta'])

		// Fresh board id (owned by the caller), distinct from the source.
		expect(copy.board.title).not.toBe(title)
	})

	test('structural-only clone (no cards) via the API', async () => {
		const res = await api('POST', `/boards/${state.boardId}/duplicate`, { withCards: false })
		try {
			expect(res.boardId).not.toBe(state.boardId)
			expect(res.title).toBe(`${title} (copy)`)
			expect(res.stacks).toBe(2)
			expect(res.labels).toBe(1)
			expect(res.cards).toBe(0)

			const doc = await api('GET', `/boards/${res.boardId}/export`)
			expect(doc.board.cards).toHaveLength(0)
			expect(doc.board.stacks).toHaveLength(2)
		} finally {
			await api('DELETE', `/boards/${res.boardId}`).catch(() => {})
		}
	})
})
