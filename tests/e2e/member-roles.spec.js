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

// #3742 — board members carry a role: internal (provider side) or external
// (client side). The sharing panel gains a MANAGE-gated per-member selector;
// new shares default to internal; the API persists role flips.
test.describe('Board member roles (internal/external)', () => {
	const state = { boardId: 0, aclId: 0 }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Roles E2E ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })
		// Share with the stock dev-stack test user; no role sent → internal.
		const acl = await api('POST', `/boards/${board.id}/acl`, {
			participant: 'tester',
			participantType: 'user',
			permission: 3, // READ | EDIT
		})
		state.aclId = acl.id
		expect(acl.role).toBe('internal')
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('manager flips a member to external in the sharing panel; the role persists', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Open Board settings → Sharing.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.locator('#bs-rail-tab-sharing').click()

		// The tester entry renders with the role selector, defaulted internal.
		const entry = page.locator('.sharing__entry', { hasText: 'tester' })
		await expect(entry).toBeVisible({ timeout: 8_000 })
		const select = entry.locator('[data-test="acl-role-select"]')
		await expect(select).toBeVisible()
		await expect(select).toHaveValue('internal')

		// Flip to External and wait for the PATCH to land.
		const patched = page.waitForResponse(
			(r) => r.url().includes(`/acl/${state.aclId}`) && r.request().method() === 'PATCH' && r.ok(),
		)
		await select.selectOption('external')
		await patched

		// Server-side truth: the stored role flipped (and survives a re-read).
		const board = await api('GET', `/boards/${state.boardId}`)
		const acl = board.acl.find((a) => a.participant === 'tester')
		expect(acl.role).toBe('external')

		// The selector reflects the persisted value after the cache refresh.
		await expect(select).toHaveValue('external', { timeout: 8_000 })
	})
})
