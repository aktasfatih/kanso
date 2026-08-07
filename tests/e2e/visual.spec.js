// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')

async function apiGet(path) {
	const r = await fetch(API + path, { headers: { ...HEADERS, Authorization: AUTH } })
	if (!r.ok) throw new Error(`GET ${path} → ${r.status}`)
	return r.json()
}
async function apiPost(path, body) {
	const r = await fetch(API + path, { method: 'POST', headers: { ...HEADERS, Authorization: AUTH }, body: JSON.stringify(body) })
	if (!r.ok) throw new Error(`POST ${path} → ${r.status}: ${await r.text()}`)
	return r.json()
}
async function apiDelete(path) {
	const r = await fetch(API + path, { method: 'DELETE', headers: { ...HEADERS, Authorization: AUTH } })
	if (!r.ok) throw new Error(`DELETE ${path} → ${r.status}`)
}
async function ncLogin(page) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	const isLoginPage = await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false)
	if (!isLoginPage) return
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

test.describe('Visual proportions', () => {
	const state = { boardUrl: '' }

	test.beforeAll(async () => {
		for (const b of await apiGet('/boards')) {
			if (b.title === 'Visual Test Board') await apiDelete(`/boards/${b.id}`)
		}
		const board = await apiPost('/boards', { title: 'Visual Test Board' })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test('label color-option swatches render as true circles (width === height)', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		// Board settings now lives in the consolidated ⋯ More overflow menu.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()

		// Open the new-label color picker grid
		await page.getByRole('button', { name: /pick color for new label/i }).click()
		const option = page.locator('.label-settings__color-option').first()
		await expect(option).toBeVisible({ timeout: 5000 })

		// A circle must be square: rendered width and height match (within 1px).
		const dims = await option.evaluate((el) => {
			const r = el.getBoundingClientRect()
			const cs = getComputedStyle(el)
			return { w: r.width, h: r.height, br: cs.borderRadius, bs: cs.boxSizing, display: getComputedStyle(el.parentElement).display }
		})
		expect(Math.abs(dims.w - dims.h), `swatch not square: ${JSON.stringify(dims)}`).toBeLessThanOrEqual(1)
		expect(dims.w).toBeGreaterThan(0)
	})
})
