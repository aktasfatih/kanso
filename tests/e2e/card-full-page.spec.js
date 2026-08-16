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

// The standalone full-page card view (#3817): the modal and the full page share
// one CardDetail component, so both render identical card content. These specs
// cover the two new surfaces — direct navigation to /card/:cardId and the
// expand-from-modal button — plus the page's back-to-board affordance.
test.describe('Full-page card view (#3817)', () => {
	const BOARD_TITLE = 'Full Page Card E2E Board ' + Date.now()
	const DESCRIPTION = 'Full-page view shares CardDetail with the modal shell.'
	const state = {
		boardId: 0,
		stackId: 0,
		cardId: 0,
	}

	test.beforeAll(async () => {
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === BOARD_TITLE) {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		const board = await apiPost('/boards', { title: BOARD_TITLE })
		state.boardId = board.id

		const stack = await apiPost('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id

		const card = await apiPost('/cards', {
			stackId: stack.id,
			title: 'Full Page Test Card',
		})
		state.cardId = card.id
		// The create endpoint sets title only; the description (which loads lazily on
		// card open — the summary payload omits it) is set via PATCH, matching how the
		// app writes descriptions. This is what makes the "description renders on the
		// full page" assertions below meaningful.
		await apiPatch(`/cards/${card.id}`, { description: DESCRIPTION })
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiDelete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('navigating to /card/:cardId renders the card full-page (not as an overlay)', async ({ page }) => {
		const consoleErrors = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') consoleErrors.push(msg.text())
		})

		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/card/${state.cardId}`)

		// The shared CardDetail content renders...
		await page.waitForSelector('.card-modal__content', { timeout: 15_000 })
		// ...in page mode (the full-page shell adds the mode modifier), NOT inside a
		// teleported modal container.
		await expect(page.locator('.card-modal--mode-page')).toBeVisible()
		await expect(page.locator('.modal-container')).toHaveCount(0)

		// The description (loaded on card open) is present in the left content pane.
		await expect(page.locator('.card-modal__content')).toContainText(DESCRIPTION)
		// The card title renders.
		await expect(page.locator('.card-modal__title')).toContainText('Full Page Test Card')

		// A back-to-board affordance exists on the page shell.
		await expect(page.locator('.card-page__back')).toBeVisible()

		// No new console errors while rendering the full page.
		expect(consoleErrors, consoleErrors.join('\n')).toEqual([])
	})

	test('the expand button in the modal navigates to the full-page view', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)

		// Open the card as the board-scoped modal overlay (the unchanged nested route).
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.cardId}`)
		await page.waitForSelector('.card-modal__header-actions', { timeout: 15_000 })
		// It really is the modal overlay: the teleported modal container is present.
		await expect(page.locator('.modal-container').first()).toBeVisible()

		// Click the expand-to-full-page button in the modal header.
		await page.locator('.card-modal__expand-btn').click()

		// The URL switches to the top-level full-page route, and the page shell renders.
		await expect(page).toHaveURL(new RegExp(`#/card/${state.cardId}$`), { timeout: 8_000 })
		await expect(page.locator('.card-modal--mode-page')).toBeVisible()
		await expect(page.locator('.card-modal__content')).toContainText(DESCRIPTION)
		// The expand button is modal-only — it must not appear on the page.
		await expect(page.locator('.card-modal__expand-btn')).toHaveCount(0)
	})

	test('the back-to-board button returns to the card\'s board', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/card/${state.cardId}`)
		await page.waitForSelector('.card-page__back', { timeout: 15_000 })

		// The board id is resolved from the loaded card, then the back button enables.
		const back = page.locator('.card-page__back')
		await expect(back).toBeEnabled({ timeout: 10_000 })
		await back.click()

		await expect(page).toHaveURL(new RegExp(`#/board/${state.boardId}$`), { timeout: 8_000 })
	})
})
