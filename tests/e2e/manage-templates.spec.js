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

// The board's template cards (isTemplate=true), by title.
async function templateTitles(boardId) {
	const tpls = await api('GET', `/boards/${boardId}/cards/templates`)
	return tpls.map((c) => c.title)
}

// Open the column's "＋ From template" menu, then click "Manage templates…".
async function openManager(page) {
	await page.locator('.card-composer__templates button').first().click()
	await page.getByRole('menuitem', { name: 'Manage templates…' }).click()
	await page.waitForSelector('.manage-templates', { timeout: 8_000 })
}

// #3634 — manage card templates: view / edit / delete / unmark / create the
// board's templates from a board-scoped modal (templates are hidden from the
// live board, so this is how they are found and managed).
test.describe('Manage card templates (#3634)', () => {
	const state = { boardId: 0, todoId: 0, tplId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Manage-Templates E2E' })
		state.boardId = board.id
		state.todoId = (await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })).id

		// Seed a template card via the API (mark an ordinary card as a template).
		const tpl = await api('POST', '/cards', { stackId: state.todoId, title: 'Seed template' })
		state.tplId = tpl.id
		await api('PUT', `/cards/${tpl.id}/template`, { isTemplate: true })

		state.boardUrl = `${BASE}/index.php/apps/kanso/#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('lists the board templates, edits one, creates a new one, deletes one', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		// ── List ────────────────────────────────────────────────────────────────
		await openManager(page)
		await expect(page.locator('.manage-templates__row-title')).toHaveText(['Seed template'])

		// ── Edit: open the template in the card modal, rename, save ───────────────
		await page.locator('.manage-templates__row', { hasText: 'Seed template' })
			.getByRole('button', { name: 'Edit template' }).click()
		await page.waitForSelector('.card-modal__title', { timeout: 8_000 })
		await page.locator('.card-modal__title').click()
		const titleInput = page.locator('.card-modal__title-input')
		await titleInput.fill('Renamed template')
		await titleInput.press('Enter')

		// The rename persisted server-side (database-first).
		await expect
			.poll(() => templateTitles(state.boardId), { timeout: 8_000 })
			.toEqual(['Renamed template'])

		// Close the card modal (Escape) and reopen the manager — the new title shows.
		await page.keyboard.press('Escape')
		await page.waitForSelector('.card-modal', { state: 'detached', timeout: 8_000 }).catch(() => {})
		await openManager(page)
		await expect(page.locator('.manage-templates__row-title')).toHaveText(['Renamed template'])

		// ── Create a new template from the modal ──────────────────────────────────
		await page.getByRole('button', { name: 'New template' }).click()
		// It opens the fresh template in the card modal for editing…
		await page.waitForSelector('.card-modal__title', { timeout: 8_000 })
		// …and the board now has two templates, both hidden from the live board.
		await expect
			.poll(() => templateTitles(state.boardId).then((t) => t.length), { timeout: 8_000 })
			.toBe(2)
		const board = await api('GET', `/boards/${state.boardId}`)
		expect(board.cards.some((c) => c.isTemplate === false)).toBe(false)

		// Close the card modal, reopen the manager — both templates listed.
		await page.keyboard.press('Escape')
		await page.waitForSelector('.card-modal', { state: 'detached', timeout: 8_000 }).catch(() => {})
		await openManager(page)
		await expect(page.locator('.manage-templates__row')).toHaveCount(2)

		// ── Delete a template ─────────────────────────────────────────────────────
		const row = page.locator('.manage-templates__row', { hasText: 'Renamed template' })
		await row.getByRole('button', { name: 'Delete template' }).click()
		// Per-row confirm strip appears; confirm the delete.
		await row.getByRole('button', { name: 'Delete', exact: true }).click()

		// It's gone from the list and from the board's templates.
		await expect
			.poll(() => templateTitles(state.boardId), { timeout: 8_000 })
			.not.toContain('Renamed template')
		await expect(page.locator('.manage-templates__row')).toHaveCount(1)
	})
})
