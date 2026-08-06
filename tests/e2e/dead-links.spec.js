// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Dead-link UX (#3662): a deep-link/inbox/notification pointing at a card or
// board that no longer exists must land on a friendly, actionable message - not
// a raw "failed to load" error. This asserts:
//   1. Opening a card route for a non-existent card id under a live board shows
//      "This card no longer exists" + a "Go to boards" way out (not a raw error).
//   2. Opening a board route for a deleted board shows "This board no longer
//      exists" + a "Go to boards" link (not the generic error box).

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const ADMIN = 'admin'
const ADMIN_PASS = 'admin'

const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const ADMIN_AUTH = 'Basic ' + Buffer.from(`${ADMIN}:${ADMIN_PASS}`).toString('base64')

async function apiRequest(path, { method = 'GET', body } = {}) {
	const opts = { method, headers: { ...HEADERS, Authorization: ADMIN_AUTH } }
	if (body !== undefined) opts.body = JSON.stringify(body)
	return fetch(API + path, opts)
}

async function apiGet(path) {
	const r = await apiRequest(path)
	if (!r.ok) throw new Error(`GET ${path} → ${r.status}`)
	return r.json()
}

async function apiPost(path, body) {
	const r = await apiRequest(path, { method: 'POST', body })
	if (!r.ok) throw new Error(`POST ${path} → ${r.status}: ${await r.text()}`)
	return r.json()
}

async function apiDelete(path) {
	await apiRequest(path, { method: 'DELETE' })
}

async function ncLogin(page) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	const isLoginPage = await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false)
	if (!isLoginPage) return
	await page.fill('#user', ADMIN)
	await page.fill('#password', ADMIN_PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

test.describe('Dead card/board links (#3662)', () => {
	const state = { liveBoardId: 0, deadBoardId: 0 }
	const TITLE = 'Dead Links E2E Board'

	test.beforeAll(async () => {
		// Clean any leftovers from a prior run.
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === TITLE) await apiDelete(`/boards/${b.id}`)
		}

		// A live board to host a bogus card deep-link.
		const live = await apiPost('/boards', { title: TITLE })
		state.liveBoardId = live.id
		await apiPost('/stacks', { boardId: live.id, title: 'To Do' })

		// A second board we delete, to exercise the gone-board path.
		const dead = await apiPost('/boards', { title: TITLE })
		state.deadBoardId = dead.id
		await apiDelete(`/boards/${dead.id}`)
	})

	test.afterAll(async () => {
		if (state.liveBoardId) await apiDelete(`/boards/${state.liveBoardId}`)
	})

	test.beforeEach(async ({ page }) => {
		await ncLogin(page)
	})

	test('a card that no longer exists shows a friendly message + a way out', async ({ page }) => {
		// Deep-link to a non-existent card id under the LIVE board (the card fetch
		// 404s while the board loads fine).
		const bogusCardId = 2_000_000_000
		await page.goto(
			`${BASE}/index.php/apps/kanso#/board/${state.liveBoardId}/card/${bogusCardId}`,
		)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		const err = page.locator('.card-modal__error')
		await expect(err).toBeVisible({ timeout: 15_000 })
		await expect(err).toContainText('no longer exists')
		// Not the old generic copy.
		await expect(err).not.toContainText('Failed to load card details')
		// A dead card is a dead end - no Retry, but a way out to the boards list.
		await expect(err.getByRole('button', { name: 'Go to boards' })).toBeVisible()
		await expect(err.getByRole('button', { name: 'Retry' })).toHaveCount(0)

		// The way out actually leaves the (broken) card.
		await err.getByRole('button', { name: 'Go to boards' }).click()
		await expect(page).toHaveURL(/#\/$/, { timeout: 10_000 })
	})

	test('a board that no longer exists explains itself + links to the boards list', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.deadBoardId}`)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		const err = page.locator('.board-view__error')
		await expect(err).toBeVisible({ timeout: 15_000 })
		await expect(err).toContainText('no longer exists')
		await expect(err).not.toContainText('Failed to load board.')
		await expect(err.getByRole('button', { name: 'Go to boards' })).toBeVisible()
	})
})
