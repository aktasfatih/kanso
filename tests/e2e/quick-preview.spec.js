// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Quick-look preview: hover a card (or keyboard-focus it) and press Space for a
// floating, read-only peek. Space again / Escape / click-away dismisses it. The
// Space typing-guard must hold so a space typed in the composer still inserts a
// space (never opens a preview).

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = {
	'OCS-APIREQUEST': 'true',
	'Content-Type': 'application/json',
}
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')

async function apiGet(path) {
	const r = await fetch(API + path, { headers: { ...HEADERS, Authorization: AUTH } })
	if (!r.ok) throw new Error(`GET ${path} → ${r.status}`)
	return r.json()
}

async function apiPost(path, body) {
	const r = await fetch(API + path, {
		method: 'POST',
		headers: { ...HEADERS, Authorization: AUTH },
		body: JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`POST ${path} → ${r.status}: ${await r.text()}`)
	return r.json()
}

async function apiPatch(path, body) {
	const r = await fetch(API + path, {
		method: 'PATCH',
		headers: { ...HEADERS, Authorization: AUTH },
		body: JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`PATCH ${path} → ${r.status}: ${await r.text()}`)
	return r.json()
}

async function apiDelete(path) {
	const r = await fetch(API + path, {
		method: 'DELETE',
		headers: { ...HEADERS, Authorization: AUTH },
	})
	if (!r.ok) throw new Error(`DELETE ${path} → ${r.status}`)
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

const DESC = 'Peekaboo description text for the quick look preview.'

test.describe('Quick-look preview (Space)', () => {
	const state = { boardId: 0, stackId: 0, card1Id: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'Quick Preview Board') {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		const board = await apiPost('/boards', { title: 'Quick Preview Board' })
		state.boardId = board.id
		const s1 = await apiPost('/stacks', { boardId: board.id, title: 'Stack One' })
		state.stackId = s1.id
		const c1 = await apiPost('/cards', { stackId: s1.id, title: 'Preview Alpha' })
		await apiPost('/cards', { stackId: s1.id, title: 'Preview Beta' })
		state.card1Id = c1.id
		// Give the first card a description so the preview has body content to show.
		await apiPatch(`/cards/${c1.id}`, { description: DESC })

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test('hover + Space opens a preview showing title + description; Space closes it', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		const firstTile = page.locator('.card-tile').first()
		await firstTile.hover()
		await page.waitForTimeout(100)

		// Space peeks the hovered card.
		await page.keyboard.press('Space')

		const preview = page.locator('.card-preview')
		await expect(preview).toBeVisible({ timeout: 3000 })
		await expect(preview.locator('.card-preview__title')).toHaveText('Preview Alpha')
		// Description is lazily fetched via useCard - poll until it renders.
		await expect(preview.locator('.card-preview__desc-rendered')).toContainText(DESC, { timeout: 5000 })

		// Space again closes it.
		await page.keyboard.press('Space')
		await expect(preview).not.toBeVisible({ timeout: 3000 })

		// The board must not have scrolled from the Space presses (preventDefault).
		const scrolled = await page.evaluate(() => window.scrollY)
		expect(scrolled).toBe(0)
	})

	test('keyboard-focused card + Space opens the preview; Escape closes it', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		// Seed keyboard focus to the first card (no mouse hover).
		await page.keyboard.press('ArrowDown')
		await page.waitForTimeout(200)
		const firstTile = page.locator('.card-tile').first()
		await expect(firstTile).toBeFocused({ timeout: 3000 })

		await page.keyboard.press('Space')
		const preview = page.locator('.card-preview')
		await expect(preview).toBeVisible({ timeout: 3000 })
		await expect(preview.locator('.card-preview__title')).toHaveText('Preview Alpha')

		// Escape closes it.
		await page.keyboard.press('Escape')
		await expect(preview).not.toBeVisible({ timeout: 3000 })
	})

	test('Enter from an open preview opens the full card modal', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		await page.keyboard.press('ArrowDown')
		await page.waitForTimeout(200)
		await page.keyboard.press('Space')
		await expect(page.locator('.card-preview')).toBeVisible({ timeout: 3000 })

		await page.keyboard.press('Enter')
		await page.waitForTimeout(400)
		expect(page.url()).toContain('/card/')
		// Preview is dismissed when the modal opens.
		await expect(page.locator('.card-preview')).not.toBeVisible({ timeout: 3000 })
	})

	test('click-away on the backdrop dismisses the preview', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		await page.keyboard.press('ArrowDown')
		await page.waitForTimeout(200)
		await page.keyboard.press('Space')
		const preview = page.locator('.card-preview')
		await expect(preview).toBeVisible({ timeout: 3000 })

		// Click empty board whitespace (viewport coords) - covered only by the
		// transparent full-screen backdrop, clear of the floating panel (anchored
		// near the top-left card) and the fixed Nextcloud header at the very top.
		await page.mouse.click(900, 500)
		await expect(preview).not.toBeVisible({ timeout: 3000 })
		// Click-away must not have opened the card modal.
		expect(page.url()).not.toContain('/card/')
	})

	test('typing space in the composer inserts a space (guard holds, no preview)', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		const s1 = page.locator('.stack-column').nth(0)
		const composer = s1.locator('.card-composer__input')
		await composer.click()
		await expect(composer).toBeFocused({ timeout: 3000 })

		// A title with an embedded space must insert the space, not open a preview.
		await page.keyboard.type('hello world')
		await page.waitForTimeout(200)
		await expect(composer).toHaveValue('hello world')
		await expect(page.locator('.card-preview')).not.toBeVisible()
	})
})
