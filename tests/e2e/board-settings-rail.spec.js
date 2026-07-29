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

// #3570 — board settings sidebar restructured into a vertical section rail with a
// danger group pinned at the bottom and a collapsible Automation pane.
test.describe('Board settings section rail', () => {
	const state = { boardId: 0 }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Rail E2E ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('rail switches panes and pins Archive/Delete in a danger group at the bottom', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		await page.getByRole('button', { name: /board settings/i }).click()

		// The rail exposes a proper vertical tablist (holding only the section tabs).
		const rail = page.locator('.bs-rail .bs-rail__tabs[role="tablist"]')
		await expect(rail).toBeVisible({ timeout: 8_000 })

		// Click through the rail sections and assert the matching pane shows.
		await page.getByRole('tab', { name: 'General' }).click()
		await expect(page.locator('#bs-pane-general')).toBeVisible()
		await expect(page.getByRole('tab', { name: 'General' })).toHaveAttribute('aria-selected', 'true')

		await page.getByRole('tab', { name: /review types/i }).click()
		await expect(page.locator('#bs-pane-review-types')).toBeVisible()
		await expect(page.locator('#bs-pane-general')).toBeHidden()

		await page.getByRole('tab', { name: /workflow/i }).click()
		await expect(page.locator('#bs-pane-workflow')).toBeVisible()

		await page.getByRole('tab', { name: /automation/i }).click()
		await expect(page.locator('#bs-pane-automation')).toBeVisible()

		// Archive + Delete live in the rail's danger group, after the section tabs.
		const danger = page.locator('.bs-rail__danger')
		await expect(danger.getByRole('button', { name: 'Archive board' })).toBeVisible()
		const deleteBtn = danger.getByRole('button', { name: 'Delete board' })
		await expect(deleteBtn).toBeVisible()

		// Delete asks for confirmation instead of firing immediately.
		await deleteBtn.click()
		const confirm = page.locator('.bs-delete-confirm')
		await expect(confirm).toBeVisible()
		await confirm.getByRole('button', { name: 'Cancel' }).click()
		await expect(confirm).toBeHidden()
	})

	test('an Automation group collapses and expands', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		await page.getByRole('button', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /automation/i }).click()

		// Column automations starts expanded → its rule form is visible.
		const header = page.getByRole('button', { name: /Column automations/i })
		await expect(header).toBeVisible({ timeout: 8_000 })
		await expect(header).toHaveAttribute('aria-expanded', 'true')
		const roleSelect = page.locator(`#auto-role-${state.boardId}`)
		await expect(roleSelect).toBeVisible()

		// Collapsing the group hides its body.
		await header.click()
		await expect(header).toHaveAttribute('aria-expanded', 'false')
		await expect(roleSelect).toBeHidden()

		// Expanding again brings it back.
		await header.click()
		await expect(header).toHaveAttribute('aria-expanded', 'true')
		await expect(roleSelect).toBeVisible()
	})
})
