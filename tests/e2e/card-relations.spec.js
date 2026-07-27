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

async function rawPost(path, body) {
	return fetch(API + path, { method: 'POST', headers: { ...HEADERS, Authorization: AUTH }, body: JSON.stringify(body) })
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

const boardCard = (board, id) => board.cards.find((c) => c.id === id)

test.describe('Card relations (#3404)', () => {
	const state = { boardId: 0, stackId: 0, a: 0, b: 0, cTitle: '' }

	test.beforeAll(async () => {
		state.cTitle = 'Rel-B ' + Math.floor(Date.now() / 1000)
		const board = await api('POST', '/boards', { title: 'Relations ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api('POST', '/stacks', { boardId: board.id, title: 'To do' })).id
		state.a = (await api('POST', '/cards', { stackId: state.stackId, title: 'Rel-A ' + Math.floor(Date.now() / 1000) })).id
		state.b = (await api('POST', '/cards', { stackId: state.stackId, title: state.cTitle })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('a blocks relation is directional and drives the blocked badge', async () => {
		await api('POST', `/cards/${state.a}/relations`, { otherCardId: state.b, kind: 'blocks' })

		// A blocks B; B is blocked by A.
		const a = await api('GET', `/cards/${state.a}`)
		const b = await api('GET', `/cards/${state.b}`)
		expect(a.relations.blocks.map((r) => r.cardId)).toContain(state.b)
		expect(b.relations.blockedBy.map((r) => r.cardId)).toContain(state.a)

		// The board payload flags B blocked (its blocker A isn't done), A not.
		let board = await api('GET', `/boards/${state.boardId}`)
		expect(boardCard(board, state.b).blocked).toBe(true)
		expect(boardCard(board, state.a).blocked).toBe(false)

		// Completing the blocker clears the badge.
		await api('PATCH', `/cards/${state.a}`, { done: true })
		board = await api('GET', `/boards/${state.boardId}`)
		expect(boardCard(board, state.b).blocked).toBe(false)
		await api('PATCH', `/cards/${state.a}`, { done: false })
	})

	test('a reverse blocks relation that would cycle is rejected', async () => {
		// A already blocks B, so "B blocks A" would close a cycle.
		const r = await rawPost(`/cards/${state.b}/relations`, { otherCardId: state.a, kind: 'blocks' })
		expect(r.ok).toBe(false)
	})

	test('a self relation is rejected', async () => {
		const r = await rawPost(`/cards/${state.a}/relations`, { otherCardId: state.a, kind: 'relates' })
		expect(r.ok).toBe(false)
	})

	test('a symmetric relation shows on both cards and can be removed', async () => {
		const rel = await api('POST', `/cards/${state.a}/relations`, { otherCardId: state.b, kind: 'duplicates' })

		expect((await api('GET', `/cards/${state.a}`)).relations.duplicates.map((r) => r.cardId)).toContain(state.b)
		expect((await api('GET', `/cards/${state.b}`)).relations.duplicates.map((r) => r.cardId)).toContain(state.a)

		await api('DELETE', `/cards/${state.a}/relations/${rel.id}`)
		expect((await api('GET', `/cards/${state.a}`)).relations.duplicates).toHaveLength(0)
	})

	test('add and remove a relation from the card modal', async ({ page }) => {
		// Start clean: drop the blocks relation from the API tests.
		const a = await api('GET', `/cards/${state.a}`)
		for (const rel of a.relations.blocks) {
			await api('DELETE', `/cards/${state.a}/relations/${rel.id}`)
		}

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.a}`)
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 15_000 })

		// Add "A blocks B" via the relation controls.
		await page.locator('.card-modal__relation-kind').selectOption('blocks')
		await page.locator('.card-modal__relation-target').selectOption(String(state.b))
		await page.locator('.card-modal__relation-add').click()

		const row = page.locator('.card-modal__relation-row', { hasText: state.cTitle })
		await expect(row).toBeVisible({ timeout: 8_000 })

		// Remove it again.
		await row.locator('.card-modal__relation-remove').click()
		await expect(page.locator('.card-modal__relation-row', { hasText: state.cTitle })).toHaveCount(0, { timeout: 8_000 })
	})
})
