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

async function apiRaw(path) {
	return fetch(API + path, { headers: { ...HEADERS, Authorization: AUTH } })
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
	await fetch(API + path, { method: 'DELETE', headers: { ...HEADERS, Authorization: AUTH } }).catch(() => {})
}

async function ncLogin(page) {
	// Session is preloaded via storageState (see playwright.config.js) for the
	// default admin context — skip the UI login round-trip entirely. Specs that
	// opt out of storageState start with no cookies and fall through to log in.
	if ((await page.context().cookies()).some((c) => c.name === 'nc_username' || c.name === 'nc_session_id')) return
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

test.describe('Card cross-references (KAN-123 → title link)', () => {
	const BOARD_TITLE = 'Cross Reference ' + Date.now()
	const state = {
		boardId: 0,
		stackId: 0,
		sourceCardId: 0,
		targetCardId: 0,
		targetSeq: 0,
		prefix: '',
		targetRef: '',
		boardUrl: '',
		sourceUrl: '',
	}

	const TARGET_TITLE = 'The referenced target card'

	test.beforeAll(async () => {
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title.startsWith('Cross Reference')) {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		const board = await apiPost('/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		state.prefix = board.prefix // derived from the title
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'Backlog' })
		state.stackId = stack.id

		// The target is created first so its board_seq is stable and known.
		const target = await apiPost('/cards', { stackId: stack.id, title: TARGET_TITLE })
		state.targetCardId = target.id
		state.targetSeq = target.boardSeq
		state.targetRef = `${state.prefix}-${target.boardSeq}`

		// The source card's description references the target by its human id.
		const source = await apiPost('/cards', { stackId: stack.id, title: 'Source card' })
		state.sourceCardId = source.id
		await apiPatch(`/cards/${source.id}`, {
			description: `See ${state.targetRef} for details.`,
		})

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
		state.sourceUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${source.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiDelete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('the by-ref resolver endpoint returns the target id + title', async () => {
		const data = await apiGet(`/boards/${state.boardId}/cards/by-ref/${state.targetRef}`)
		expect(data.cardId).toBe(state.targetCardId)
		expect(data.title).toBe(TARGET_TITLE)

		// An unknown sequence resolves to 404.
		const miss = await apiRaw(`/boards/${state.boardId}/cards/by-ref/${state.prefix}-99999`)
		expect(miss.status).toBe(404)

		// A prefix that is not this board's prefix does not resolve (board-scoped).
		const wrongPrefix = await apiRaw(`/boards/${state.boardId}/cards/by-ref/ZZZ-1`)
		expect(wrongPrefix.status).toBe(404)
	})

	test('a KAN-<n> reference renders as a link showing the target title and opens it', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.sourceUrl)

		await page.waitForSelector('.card-modal__desc-rendered', { timeout: 10_000 })

		// The reference is rendered as a class="kanso-cardref" anchor whose visible
		// text is the TARGET card's title, not the raw KAN-<n> text.
		const refLink = page.locator('.card-modal__desc-rendered a.kanso-cardref')
		await expect(refLink).toBeVisible({ timeout: 5000 })
		await expect(refLink).toHaveText(TARGET_TITLE)
		await expect(refLink).toHaveAttribute('data-kanso-card-id', String(state.targetCardId))
		// It is an internal ref: no external href / new-tab wiring.
		await expect(refLink).not.toHaveAttribute('href', /.+/)

		// Clicking it navigates the modal to the target card.
		await refLink.click()
		await expect(page).toHaveURL(new RegExp(`/card/${state.targetCardId}$`), { timeout: 5000 })
		await expect(page.locator('.card-modal')).toContainText(TARGET_TITLE, { timeout: 5000 })
	})

	test('opening a card by its human id in the URL resolves to the numeric card', async ({ page }) => {
		await ncLogin(page)
		// Navigate straight to the human-id URL; the modal resolves + redirects.
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.targetRef}`)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		// The route is rewritten to the numeric id and the target card is shown.
		await expect(page).toHaveURL(new RegExp(`/card/${state.targetCardId}$`), { timeout: 10_000 })
		await expect(page.locator('.card-modal')).toContainText(TARGET_TITLE, { timeout: 5000 })
	})
})
