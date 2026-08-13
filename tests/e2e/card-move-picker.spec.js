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

async function stackOrder(boardId, stackId) {
	const board = await api('GET', `/boards/${boardId}`)
	return board.cards
		.filter((c) => c.stackId === stackId && !c.archived)
		.sort((a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0))
		.map((c) => c.title)
}

async function cardStackId(boardId, cardId) {
	const board = await api('GET', `/boards/${boardId}`)
	return board.cards.find((c) => c.id === cardId)?.stackId
}

// #3412 — the keyboard / screen-reader "Move card…" picker: a non-pointer
// alternative to drag-and-drop. It must funnel through the SAME optimistic move
// path (useCardMove) as DnD, and must never produce an invalid position.
test.describe('Move card… picker (keyboard / SR DnD alternative)', () => {
	const state = { boardId: 0, todoId: 0, doingId: 0, aId: 0, bId: 0, cId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Move-Picker E2E' })
		state.boardId = board.id
		state.todoId = (await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })).id
		state.doingId = (await api('POST', '/stacks', { boardId: board.id, title: 'Doing' })).id
		// To Do: A (top), B (middle), C (bottom).
		state.aId = (await api('POST', '/cards', { stackId: state.todoId, title: 'Card A' })).id
		state.bId = (await api('POST', '/cards', { stackId: state.todoId, title: 'Card B' })).id
		state.cId = (await api('POST', '/cards', { stackId: state.todoId, title: 'Card C' })).id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${state.bId}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('moves the card to another column at a chosen position, and to an empty column', async ({ page }) => {
		expect(await stackOrder(state.boardId, state.todoId)).toEqual(['Card A', 'Card B', 'Card C'])

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Open ⋯ → "Move card…" picker.
		await page.locator('.card-modal__actions-menu button').first().click()
		await page.getByRole('menuitem', { name: 'Move card…' }).click()
		await page.waitForSelector('.card-modal__move-position', { timeout: 5_000 })

		// Pick target column "Doing" and "After a specific card" is disabled there
		// (empty stack) — the picker must degrade to top, never an invalid move.
		// Move Card B to the empty "Doing" column at the top.
		await page.locator('.card-modal__copy-dialog select').first().selectOption({ label: 'Doing' })
		await page.getByText('Top of the column').click()
		await page.getByRole('button', { name: 'Move', exact: true }).click()

		// Card B landed in Doing (moved via the shared queue → server reflects it).
		await expect
			.poll(() => cardStackId(state.boardId, state.bId), { timeout: 8_000 })
			.toBe(state.doingId)
		expect(await stackOrder(state.boardId, state.todoId)).toEqual(['Card A', 'Card C'])
		expect(await stackOrder(state.boardId, state.doingId)).toEqual(['Card B'])
	})

	test('positions the card after a specific card in the same/other column', async ({ page }) => {
		// Fresh state: Card C in To Do; move it to Doing AFTER Card B.
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.cId}`)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		await page.locator('.card-modal__actions-menu button').first().click()
		await page.getByRole('menuitem', { name: 'Move card…' }).click()
		await page.waitForSelector('.card-modal__move-position', { timeout: 5_000 })

		await page.locator('.card-modal__copy-dialog select').first().selectOption({ label: 'Doing' })
		await page.getByText('After a specific card').click()
		// The "after" card select now lists Card B (the only card in Doing).
		await page.locator('.card-modal__copy-dialog select').nth(1).selectOption({ label: 'Card B' })
		await page.getByRole('button', { name: 'Move', exact: true }).click()

		await expect
			.poll(() => stackOrder(state.boardId, state.doingId), { timeout: 8_000 })
			.toEqual(['Card B', 'Card C'])
	})
})
