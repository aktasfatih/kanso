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
	const text = await r.text()
	return text ? JSON.parse(text) : null
}

async function ncLogin(page) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	const userInput = page.locator('#user')
	const isLoginPage = await userInput.isVisible({ timeout: 3000 }).catch(() => false)
	if (!isLoginPage) return
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

// #3493 — with 3+ reviews the attribute-bar chips must stay compact (one row).
test.describe('Compact multi-review attribute bar', () => {
	const state = { boardId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Review-Compact E2E' })
		state.boardId = board.id
		const stackId = (await api('POST', '/stacks', { boardId: board.id, title: 'Do' })).id
		const cardId = (await api('POST', '/cards', { stackId, title: 'Review-heavy card' })).id
		// Three distinct reviews from admin (one per type) — same reviewer, three
		// types is a valid multi-review shape and avoids provisioning extra users.
		for (const name of ['QA', 'Security', 'Design']) {
			const typeId = (await api('POST', '/review-types', { boardId: board.id, title: name, color: '31CC7C' })).id
			await api('PUT', `/cards/${cardId}/reviews/${USER}`, { reviewTypeId: typeId })
		}
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${cardId}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('3+ reviews render as compact chips on a single row', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const pills = page.locator('.card-modal__review-pill')
		await expect(pills).toHaveCount(3, { timeout: 8000 })

		// Compact mode engaged: every pill carries the modifier and the reviewer
		// name / state text are hidden (moved to the avatar tooltip + icon).
		await expect(page.locator('.card-modal__review-pill--compact')).toHaveCount(3)
		await expect(page.locator('.card-modal__review-pill .card-modal__review-name').first()).toBeHidden()

		// The review row stays a single line: its height is about one pill tall,
		// not the ~2-3 rows the full pills would wrap into.
		const box = await page.locator('.card-modal__attr-right').boundingBox()
		expect(box).not.toBeNull()
		expect(box.height).toBeLessThan(44)
	})
})
