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

// #3548 — quick multi-add: paste/enter multiple lines → one card per non-blank
// line, in order. Reuses the single create path (no bulk endpoint).
test.describe('Quick multi-add', () => {
	const state = { boardId: 0, stackId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Multi-Add E2E' })
		state.boardId = board.id
		state.stackId = (await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })).id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	// Persisted server-side order (source of truth), top-to-bottom by sort key.
	async function stackOrder() {
		const board = await api('GET', `/boards/${state.boardId}`)
		return board.cards
			.filter((c) => c.stackId === state.stackId && !c.archived)
			.sort((a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0))
			.map((c) => c.title)
	}

	// Simulate a real multi-line clipboard paste into the composer input. A text
	// <input> collapses typed newlines, so the composer intercepts `paste`.
	async function pasteIntoComposer(composer, text) {
		await composer.focus()
		await composer.evaluate((el, value) => {
			const dt = new DataTransfer()
			dt.setData('text', value)
			el.dispatchEvent(new ClipboardEvent('paste', {
				clipboardData: dt,
				bubbles: true,
				cancelable: true,
			}))
		}, text)
	}

	test('pasting 3 lines creates 3 cards in order; a single line makes 1', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 15_000 })

		const composer = page.locator('.stack-column').first().locator('.card-composer__input')
		await expect(composer).toBeVisible({ timeout: 10_000 })

		// Multi-line paste (with a blank line + trailing whitespace to prove the
		// trim/skip-blank behaviour) → exactly 3 cards, in submitted order.
		await pasteIntoComposer(composer, 'Alpha\n  Bravo  \n\nCharlie\n')

		await expect.poll(() => stackOrder(), { timeout: 10_000 }).toEqual([
			'Alpha', 'Bravo', 'Charlie',
		])

		// Single-line add via Enter still creates exactly one card, appended in order.
		await composer.fill('Delta')
		await composer.press('Enter')

		await expect.poll(() => stackOrder(), { timeout: 10_000 }).toEqual([
			'Alpha', 'Bravo', 'Charlie', 'Delta',
		])
	})
})
