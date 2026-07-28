// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

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
	if (!isLoginPage) return // Already logged in

	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

test.describe('Markdown card descriptions - render and XSS safety', () => {
	const state = {
		boardId: 0,
		stackId: 0,
		cardId: 0,
		boardUrl: '',
		cardUrl: '',
	}

	const DESCRIPTION = '# Heading\n\n**bold** and [a link](https://example.com)\n\n<script>alert(1)</script>'

	test.beforeAll(async () => {
		// Clean up any leftover test board
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'Markdown Test Board') {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		// Seed board + stack + card
		const board = await apiPost('/boards', { title: 'Markdown Test Board' })
		state.boardId = board.id

		const stack = await apiPost('/stacks', { boardId: board.id, title: 'Test Stack' })
		state.stackId = stack.id

		const card = await apiPost('/cards', { stackId: stack.id, title: 'MD Card' })
		state.cardId = card.id

		// PATCH the card description with markdown + XSS payload
		await apiPatch(`/cards/${card.id}`, { description: DESCRIPTION })

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
		console.log('Setup complete - cardUrl:', state.cardUrl)
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiDelete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('renders markdown (bold, link) and strips XSS payload', async ({ page }) => {
		// Track any alert dialogs - XSS would fire one
		let alertFired = false
		page.on('dialog', async (dialog) => {
			alertFired = true
			await dialog.dismiss()
		})

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		// Wait for the card modal to appear
		await page.waitForSelector('.card-modal__desc-rendered', { timeout: 10_000 })

		// ── Positive assertions: safe markdown is rendered ────────────────────────

		// <strong> element inside the rendered description
		const strongEl = page.locator('.card-modal__desc-rendered strong')
		await expect(strongEl).toBeVisible({ timeout: 5000 })

		// <a> linking to https://example.com
		const linkEl = page.locator('.card-modal__desc-rendered a[href="https://example.com"]')
		await expect(linkEl).toBeVisible({ timeout: 5000 })

		// Link should have safe rel + target
		await expect(linkEl).toHaveAttribute('rel', 'noopener noreferrer')
		await expect(linkEl).toHaveAttribute('target', '_blank')

		// ── Negative assertions: XSS payload is neutralised ──────────────────────

		// No <script> elements inside the description container
		const scriptCount = await page.locator('.card-modal__desc-rendered script').count()
		expect(scriptCount).toBe(0)

		// No alert dialog was fired by the XSS payload
		expect(alertFired).toBe(false)

		// The raw text "<script>" must not appear as an unescaped tag in the HTML
		const descHtml = await page.locator('.card-modal__desc-rendered').innerHTML()
		expect(descHtml).not.toMatch(/<script[\s>]/i)
	})

	test('markdown is still safe after page reload', async ({ page }) => {
		let alertFired = false
		page.on('dialog', async (dialog) => {
			alertFired = true
			await dialog.dismiss()
		})

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-modal__desc-rendered', { timeout: 10_000 })

		// Reload to verify persistence
		await page.reload()
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-modal__desc-rendered', { timeout: 10_000 })

		// Strong and link still rendered
		await expect(page.locator('.card-modal__desc-rendered strong')).toBeVisible({ timeout: 5000 })
		await expect(page.locator('.card-modal__desc-rendered a[href="https://example.com"]')).toBeVisible({ timeout: 5000 })

		// Still no XSS
		const scriptCount = await page.locator('.card-modal__desc-rendered script').count()
		expect(scriptCount).toBe(0)
		expect(alertFired).toBe(false)

		const descHtml = await page.locator('.card-modal__desc-rendered').innerHTML()
		expect(descHtml).not.toMatch(/<script[\s>]/i)
	})
})
