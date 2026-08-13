// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #3767 — open-card-modal realtime freshness. The board delta only patched the
// board SUMMARY cache, so a card modal open in another tab/browser went stale:
// title, description and comment changes never appeared until close/reopen.
// The fix invalidates the open card's DETAIL queries when a delta change row
// for that card arrives. This suite drives two browser contexts (admin mutates
// via API, tester watches an open modal) and works with notify_push (near-
// instant) or the 5s delta-poll fallback — both under the 15s budgets used
// here, matching realtime.spec.js.
//
// Draft safety is asserted too: the modal's editors copy into local draft refs
// on edit-start, so a remote refresh must never clobber a dirty editor.

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = {
	'OCS-APIREQUEST': 'true',
	'Content-Type': 'application/json',
}
const ADMIN_AUTH = 'Basic ' + Buffer.from('admin:admin').toString('base64')

const TESTER = { user: 'tester', pass: 'kanso-dev-tester!1' }

async function apiGet(path) {
	const r = await fetch(API + path, { headers: { ...HEADERS, Authorization: ADMIN_AUTH } })
	if (!r.ok) throw new Error(`GET ${path} → ${r.status}`)
	return r.json()
}

async function apiPost(path, body) {
	const r = await fetch(API + path, {
		method: 'POST',
		headers: { ...HEADERS, Authorization: ADMIN_AUTH },
		body: JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`POST ${path} → ${r.status}: ${await r.text()}`)
	return r.json()
}

async function apiPatch(path, body) {
	const r = await fetch(API + path, {
		method: 'PATCH',
		headers: { ...HEADERS, Authorization: ADMIN_AUTH },
		body: JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`PATCH ${path} → ${r.status}: ${await r.text()}`)
	return r.json()
}

async function apiDelete(path) {
	const r = await fetch(API + path, {
		method: 'DELETE',
		headers: { ...HEADERS, Authorization: ADMIN_AUTH },
	})
	if (!r.ok) throw new Error(`DELETE ${path} → ${r.status}`)
}

async function ncLogin(page, user, pass) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})

	const userInput = page.locator('#user')
	const isLoginPage = await userInput.isVisible({ timeout: 3000 }).catch(() => false)
	if (!isLoginPage) return // Already logged in

	await page.fill('#user', user)
	await page.fill('#password', pass)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

test.describe('Realtime card modal freshness', () => {
	// Drives two distinct users (admin + tester) and logs each in explicitly — so
	// it must NOT inherit the shared authenticated storageState, or every context
	// would start as admin and ncLogin would no-op.
	test.use({ storageState: { cookies: [], origins: [] } })

	const state = { boardId: 0, cardId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'Realtime Modal Board') {
				await apiDelete(`/boards/${b.id}`)
			}
		}
		const board = await apiPost('/boards', { title: 'Realtime Modal Board' })
		state.boardId = board.id
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'S1' })
		const card = await apiPost('/cards', { stackId: stack.id, title: 'Modal card v1' })
		state.cardId = card.id
		// Share with tester (READ|EDIT = 3)
		await apiPost(`/boards/${board.id}/acl`, {
			participant: TESTER.user,
			participantType: 'user',
			permission: 3,
		})
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiDelete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('open modal picks up remote title, description and comment without reload', async ({ browser }) => {
		const testerCtx = await browser.newContext()
		try {
			const page = await testerCtx.newPage()
			await ncLogin(page, TESTER.user, TESTER.pass)

			// Tester opens the card modal and keeps it open — no further navigation.
			await page.goto(state.cardUrl)
			await expect(page.locator('.card-modal__title')).toHaveText('Modal card v1', { timeout: 15_000 })

			// Admin (other "tab") edits title + description and adds a comment.
			await apiPatch(`/cards/${state.cardId}`, { title: 'Modal card v2' })
			await apiPatch(`/cards/${state.cardId}`, { description: 'remote description v1' })
			await apiPost(`/cards/${state.cardId}/comments`, { body: 'remote comment v1' })

			// All three must land in the OPEN modal: push is near-instant, the
			// delta-poll fallback fires every 5s — 15s is generous for either.
			await expect(page.locator('.card-modal__title')).toHaveText('Modal card v2', { timeout: 15_000 })
			await expect(page.locator('.card-modal__desc-rendered')).toContainText('remote description v1', { timeout: 15_000 })
			await expect(
				page.locator('.card-modal__comment-body').filter({ hasText: 'remote comment v1' }),
			).toBeVisible({ timeout: 15_000 })
		} finally {
			await testerCtx.close()
		}
	})

	test('a remote change never clobbers a dirty description draft', async ({ browser }) => {
		const testerCtx = await browser.newContext()
		try {
			const page = await testerCtx.newPage()
			await ncLogin(page, TESTER.user, TESTER.pass)

			await page.goto(state.cardUrl)
			await expect(page.locator('.card-modal__desc-rendered')).toContainText('remote description v1', { timeout: 15_000 })

			// Tester starts editing the description — the editor seeds a LOCAL
			// draft from the current text; from here on it must be untouchable.
			await page.locator('.card-modal__desc-view').click()
			const textarea = page.locator('.card-modal__desc-textarea')
			await expect(textarea).toBeVisible()
			await textarea.fill('my precious local draft')

			// Admin edits BOTH the title and the description remotely.
			await apiPatch(`/cards/${state.cardId}`, { title: 'Modal card v3' })
			await apiPatch(`/cards/${state.cardId}`, { description: 'remote description v2' })

			// The title updating proves the remote change reached the open modal
			// (card detail refetched) while the editor was dirty...
			await expect(page.locator('.card-modal__title')).toHaveText('Modal card v3', { timeout: 15_000 })

			// ...and the dirty draft survived it, byte for byte.
			await expect(textarea).toBeVisible()
			await expect(textarea).toHaveValue('my precious local draft')

			// The tester's save then wins (deliberate last-writer-wins, same as any
			// two-user edit): the draft is what gets persisted and rendered.
			await page.locator('.card-modal__desc-actions button', { hasText: 'Save' }).click()
			await expect(page.locator('.card-modal__desc-rendered')).toContainText('my precious local draft', { timeout: 15_000 })
		} finally {
			await testerCtx.close()
		}
	})
})
