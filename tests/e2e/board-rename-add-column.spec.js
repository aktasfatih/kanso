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

test.describe('Board rename + Add column', () => {
	// A roomy header so the (ellipsised) board title isn't squeezed to zero width
	// behind the toolbar when the app-navigation sidebar is open.
	test.use({ viewport: { width: 1600, height: 900 } })

	const state = { boardId: 0, stackId: 0 }

	test.beforeAll(async () => {
		const stamp = Math.floor(Date.now() / 1000)
		const board = await api('POST', '/boards', { title: 'Rename me ' + stamp })
		state.boardId = board.id
		state.stackId = (await api('POST', '/stacks', { boardId: board.id, title: 'To do' })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('rename the board from Board settings → General', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Open Board settings (in the ⋯ More menu) and the General tab.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /general/i }).click()

		const nameInput = page.locator('#bs-board-name')
		await expect(nameInput).toBeVisible({ timeout: 8_000 })

		const newTitle = 'Renamed board ' + Math.floor(Date.now() / 1000)
		await nameInput.fill(newTitle)
		await nameInput.press('Enter')

		// Server reflects the rename…
		await expect.poll(async () => (await api('GET', `/boards/${state.boardId}`)).board.title, { timeout: 8_000 })
			.toBe(newTitle)
		// …and so does the header once the modal is dismissed.
		await page.keyboard.press('Escape')
		await expect(page.locator('.board-view__title-text')).toContainText(newTitle, { timeout: 8_000 })
	})

	test('add a column from the ⋯ More menu; no persistent trailing input remains', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// The board has a column, so there is NO always-on "add stack" input.
		await expect(page.locator('.add-stack')).toHaveCount(0)

		// "Add column" lives in the ⋯ More menu; clicking it reveals + focuses an
		// on-demand composer at the end of the board.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: 'Add column' }).click()
		const colInput = page.locator('.add-stack__input')
		await expect(colInput).toBeVisible({ timeout: 8_000 })
		await expect(colInput).toBeFocused({ timeout: 5_000 })
		await colInput.fill('In review')
		await colInput.press('Enter')

		// The new column appears on the board.
		await expect(page.locator('.stack-column__title', { hasText: 'In review' }))
			.toBeVisible({ timeout: 8_000 })
		await expect.poll(async () => {
			const { stacks } = await api('GET', `/boards/${state.boardId}`)
			return (stacks ?? []).some((s) => s.title === 'In review')
		}, { timeout: 8_000 }).toBe(true)
	})

	test('renaming a board updates the app-navigation sidebar live', async ({ page }) => {
		// Pin the board so it is guaranteed to appear in the sidebar regardless of
		// the account's other pins (the nav shows pinned boards, or all when none).
		await api('PUT', `/boards/${state.boardId}/pin`).catch(() => {})
		const before = (await api('GET', `/boards/${state.boardId}`)).board.title

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		const navLink = (title) => page.locator('.app-navigation .app-navigation-entry-link', { hasText: title })
		await expect(navLink(before)).toBeVisible({ timeout: 8_000 })

		// Rename via Board settings → General.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /general/i }).click()
		const after = 'Sidebar rename ' + Math.floor(Date.now() / 1000)
		const nameInput = page.locator('#bs-board-name')
		await expect(nameInput).toBeVisible({ timeout: 8_000 })
		await nameInput.fill(after)
		await nameInput.press('Enter')
		await expect.poll(async () => (await api('GET', `/boards/${state.boardId}`)).board.title, { timeout: 8_000 })
			.toBe(after)

		// The sidebar reflects the new name and drops the old one — no manual reload.
		await expect(navLink(after)).toBeVisible({ timeout: 8_000 })
		await expect(navLink(before)).toHaveCount(0, { timeout: 8_000 })
	})
})

test.describe('Empty board onboarding composer', () => {
	test.use({ viewport: { width: 1600, height: 900 } })

	const state = { boardId: 0 }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Empty ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id // no stacks on purpose
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('a board with no columns shows the inline first-column composer', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// The onboarding composer is present precisely because the board is empty.
		await expect(page.locator('[data-test="empty-board-hint"]')).toBeVisible({ timeout: 8_000 })
		const firstInput = page.locator('.add-stack__input')
		await firstInput.fill('Backlog')
		await firstInput.press('Enter')

		// The first column is created and the onboarding composer disappears.
		await expect(page.locator('.stack-column__title', { hasText: 'Backlog' }))
			.toBeVisible({ timeout: 8_000 })
		await expect(page.locator('[data-test="empty-board-hint"]')).toHaveCount(0, { timeout: 8_000 })
	})
})
