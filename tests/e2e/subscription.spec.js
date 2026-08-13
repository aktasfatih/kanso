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

async function apiDelete(path) {
	const r = await fetch(API + path, {
		method: 'DELETE',
		headers: { ...HEADERS, Authorization: AUTH },
	})
	if (!r.ok) throw new Error(`DELETE ${path} → ${r.status}`)
}

async function apiPut(path) {
	const r = await fetch(API + path, {
		method: 'PUT',
		headers: { ...HEADERS, Authorization: AUTH },
	})
	return r
}

// ---- OCS provisioning + as-other-user helpers (multi-user watcher tests) ----
const OCS = BASE + '/ocs/v2.php/cloud'
const OCS_HEADERS = { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }

async function provisionUser(uid, password) {
	// Hermetic: remove any prior user, then recreate with a known password.
	await fetch(`${OCS}/users/${uid}`, { method: 'DELETE', headers: { ...OCS_HEADERS, Authorization: AUTH } }).catch(() => {})
	const body = new URLSearchParams({ userid: uid, password })
	const r = await fetch(`${OCS}/users`, {
		method: 'POST',
		headers: { ...OCS_HEADERS, Authorization: AUTH, 'Content-Type': 'application/x-www-form-urlencoded' },
		body,
	})
	if (!r.ok) throw new Error(`provision ${uid} → ${r.status}: ${await r.text()}`)
}

async function deleteUser(uid) {
	await fetch(`${OCS}/users/${uid}`, { method: 'DELETE', headers: { ...OCS_HEADERS, Authorization: AUTH } }).catch(() => {})
}

function authHeader(user, pass) {
	return 'Basic ' + Buffer.from(user + ':' + pass).toString('base64')
}

// Share a board with a user at the given permission mask (READ=1, EDIT=2, SHARE=4).
async function shareBoardWith(boardId, uid, permission) {
	const r = await fetch(`${API}/boards/${boardId}/acl`, {
		method: 'POST',
		headers: { ...HEADERS, Authorization: AUTH },
		body: JSON.stringify({ participant: uid, participantType: 'user', permission }),
	})
	if (!r.ok) throw new Error(`share ${boardId}→${uid} → ${r.status}: ${await r.text()}`)
	return r.json()
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

test.describe('Card Subscriptions / Watchers', () => {
	const state = { boardId: 0, stackId: 0, cardId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		// Tear down any prior board with the same name to ensure hermetic setup
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'Subscription E2E Board') {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		// Create fresh board + stack + card
		const board = await apiPost('/boards', { title: 'Subscription E2E Board' })
		state.boardId = board.id
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id
		const card = await apiPost('/cards', { stackId: stack.id, title: 'Card With Watchers' })
		state.cardId = card.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiDelete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('Watch button shows unsubscribed state initially', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const watchBtn = page.locator('.card-modal__watch-btn')
		await expect(watchBtn).toBeVisible({ timeout: 5000 })

		// Should NOT have the active class (not watching)
		await expect(watchBtn).not.toHaveClass(/card-modal__watch-btn--active/, { timeout: 3000 })

		// aria-pressed should be false
		await expect(watchBtn).toHaveAttribute('aria-pressed', 'false', { timeout: 3000 })

		// Label should say "Watch" (not "Watching")
		const label = watchBtn.locator('.card-modal__watch-label')
		await expect(label).toHaveText('Watch', { timeout: 3000 })
	})

	test('clicking Watch subscribes the user and shows count 1', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const watchBtn = page.locator('.card-modal__watch-btn')
		await expect(watchBtn).toBeVisible({ timeout: 5000 })

		// Click Watch
		await watchBtn.click()

		// Should become active. The compact button swaps the "Watch" label for a
		// count badge once there is at least one watcher (no "Watching" text).
		await expect(watchBtn).toHaveClass(/card-modal__watch-btn--active/, { timeout: 6000 })
		await expect(watchBtn).toHaveAttribute('aria-pressed', 'true', { timeout: 3000 })

		// Count badge should show 1
		const countBadge = watchBtn.locator('.card-modal__watch-count')
		await expect(countBadge).toBeVisible({ timeout: 4000 })
		const countText = await countBadge.innerText()
		expect(Number(countText.trim())).toBeGreaterThanOrEqual(1)
	})

	test('Watching state persists after page reload', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const watchBtn = page.locator('.card-modal__watch-btn')
		await expect(watchBtn).toBeVisible({ timeout: 5000 })

		// After prior test subscribed, this should still be active on fresh load,
		// with the count badge (not the "Watch" label) shown.
		await expect(watchBtn).toHaveClass(/card-modal__watch-btn--active/, { timeout: 6000 })
		await expect(watchBtn).toHaveAttribute('aria-pressed', 'true', { timeout: 3000 })
		await expect(watchBtn.locator('.card-modal__watch-count')).toBeVisible({ timeout: 4000 })
	})

	test('clicking Watching again unsubscribes and shows count 0', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const watchBtn = page.locator('.card-modal__watch-btn')
		await expect(watchBtn).toBeVisible({ timeout: 5000 })

		// Should currently be in Watching state
		await expect(watchBtn).toHaveClass(/card-modal__watch-btn--active/, { timeout: 6000 })

		// Click to unwatch
		await watchBtn.click()

		// Should revert to "Watch" (unsubscribed)
		await expect(watchBtn).not.toHaveClass(/card-modal__watch-btn--active/, { timeout: 6000 })
		await expect(watchBtn).toHaveAttribute('aria-pressed', 'false', { timeout: 3000 })

		const label = watchBtn.locator('.card-modal__watch-label')
		await expect(label).toHaveText('Watch', { timeout: 3000 })

		// Count badge should be gone or show 0
		const countBadge = watchBtn.locator('.card-modal__watch-count')
		const countVisible = await countBadge.isVisible({ timeout: 2000 }).catch(() => false)
		if (countVisible) {
			const countText = await countBadge.innerText()
			expect(Number(countText.trim())).toBe(0)
		}
		// else: no badge shown = count is 0, which is also correct
	})

	test('auto-subscribe: commenting via API makes admin a watcher', async ({ page }) => {
		// Use a FRESH card: the earlier unwatch test wrote an opt-out tombstone on
		// state.cardId, which correctly suppresses auto-subscribe there.
		const freshCard = await apiPost('/cards', { stackId: state.stackId, title: 'Fresh Watch Card' })
		const freshUrl = `${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${freshCard.id}`

		// Post a comment as admin via the API - server should auto-subscribe admin
		await apiPost(`/cards/${freshCard.id}/comments`, { body: 'API comment auto-subscribes me' })

		await ncLogin(page)
		await page.goto(freshUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const watchBtn = page.locator('.card-modal__watch-btn')
		await expect(watchBtn).toBeVisible({ timeout: 5000 })

		// Admin should now be auto-subscribed - Watching state expected
		await expect(watchBtn).toHaveClass(/card-modal__watch-btn--active/, { timeout: 8000 })
		await expect(watchBtn).toHaveAttribute('aria-pressed', 'true', { timeout: 3000 })

		// Count should be ≥ 1 (at least admin is watching)
		const countBadge = watchBtn.locator('.card-modal__watch-count')
		await expect(countBadge).toBeVisible({ timeout: 4000 })
		const countText = await countBadge.innerText()
		expect(Number(countText.trim())).toBeGreaterThanOrEqual(1)
	})
})

test.describe('Watchers dropdown UI (caret panel)', () => {
	// #3654: watchers are managed from a dropdown under the top-right Watch button
	// (no standalone body section). Drive the panel through the UI.
	const BOB = 'kanso_watch_ui_bob'
	const BOB_PASS = 'SubUiWatcher#2026'
	const state = { boardId: 0, stackId: 0, cardId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		await provisionUser(BOB, BOB_PASS)
		for (const b of await apiGet('/boards')) {
			if (b.title === 'Watchers UI E2E Board') await apiDelete(`/boards/${b.id}`)
		}
		const board = await apiPost('/boards', { title: 'Watchers UI E2E Board' })
		state.boardId = board.id
		await shareBoardWith(board.id, BOB, 3) // READ | EDIT — becomes a board participant
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id
		const card = await apiPost('/cards', { stackId: stack.id, title: 'Dropdown Watchers' })
		state.cardId = card.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await apiDelete(`/boards/${state.boardId}`).catch(() => {})
		await deleteUser(BOB)
	})

	test('no standalone watchers section remains in the modal body', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// The old "Add watcher" pill lived in the attribute bar; it must be gone.
		await expect(page.locator('.card-modal__attrbar')).not.toContainText('Add watcher', { timeout: 3000 })
		// The dropdown panel is closed until the caret is clicked.
		await expect(page.locator('.card-modal__watch-panel')).toHaveCount(0)
	})

	test('caret opens the panel; add + remove a watcher from it', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const caret = page.locator('.card-modal__watch-caret')
		await expect(caret).toBeVisible({ timeout: 5000 })
		await expect(caret).toHaveAttribute('aria-expanded', 'false', { timeout: 3000 })

		// Open the dropdown.
		await caret.click()
		const panel = page.locator('.card-modal__watch-panel')
		await expect(panel).toBeVisible({ timeout: 4000 })
		await expect(caret).toHaveAttribute('aria-expanded', 'true', { timeout: 3000 })

		// Add BOB via the "Add watcher" picker inside the panel.
		const addOption = panel.locator('.card-modal__assign-option', { hasText: BOB })
		await expect(addOption).toBeVisible({ timeout: 4000 })
		await addOption.click()

		// Reopen (adding closes the popover) and verify BOB is now a listed watcher.
		await caret.click()
		await expect(panel).toBeVisible({ timeout: 4000 })
		const bobRow = panel.locator('.card-modal__watch-row', { hasText: BOB })
		await expect(bobRow).toBeVisible({ timeout: 4000 })

		// Count badge should now reflect at least one watcher.
		await expect(page.locator('.card-modal__watch-count')).toBeVisible({ timeout: 4000 })

		// Remove BOB via the × on his row.
		await bobRow.locator('.card-modal__pill-x').click()
		await expect(panel.locator('.card-modal__watch-row', { hasText: BOB })).toHaveCount(0, { timeout: 4000 })
	})

	test('Escape closes the dropdown before the modal', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const caret = page.locator('.card-modal__watch-caret')
		await caret.click()
		await expect(page.locator('.card-modal__watch-panel')).toBeVisible({ timeout: 4000 })

		// First Escape dismisses the panel but keeps the card open.
		await page.keyboard.press('Escape')
		await expect(page.locator('.card-modal__watch-panel')).toHaveCount(0, { timeout: 3000 })
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 3000 })
	})
})

test.describe('Watcher management — add / remove OTHER users', () => {
	const BOB = 'kanso_watch_bob'
	const BOB_PASS = 'Sub2Watcher#2026'
	const STRANGER = 'kanso_watch_stranger'
	const STRANGER_PASS = 'Sub2Stranger#2026'
	const state = { boardId: 0, stackId: 0, cardId: 0 }

	test.beforeAll(async () => {
		// Second board participant (READ+EDIT) and a non-member.
		await provisionUser(BOB, BOB_PASS)
		await provisionUser(STRANGER, STRANGER_PASS)

		for (const b of await apiGet('/boards')) {
			if (b.title === 'Watcher Mgmt E2E Board') {
				await apiDelete(`/boards/${b.id}`)
			}
		}
		const board = await apiPost('/boards', { title: 'Watcher Mgmt E2E Board' })
		state.boardId = board.id
		await shareBoardWith(board.id, BOB, 3) // READ | EDIT
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id
		const card = await apiPost('/cards', { stackId: stack.id, title: 'Managed Watchers' })
		state.cardId = card.id
	})

	test.afterAll(async () => {
		if (state.boardId) await apiDelete(`/boards/${state.boardId}`).catch(() => {})
		await deleteUser(BOB)
		await deleteUser(STRANGER)
	})

	test('EDIT user adds another board participant as a watcher; target sees it', async () => {
		// Admin (owner, EDIT) subscribes BOB.
		const put = await apiPut(`/cards/${state.cardId}/subscription/${BOB}`)
		expect(put.ok).toBeTruthy()
		const block = await put.json()
		expect(block.subscribers).toContain(BOB)

		// BOB, querying as himself, sees he is now watching the card.
		const asBob = await fetch(`${API}/cards/${state.cardId}/subscription`, {
			headers: { ...HEADERS, Authorization: authHeader(BOB, BOB_PASS) },
		})
		expect(asBob.ok).toBeTruthy()
		const bobBlock = await asBob.json()
		expect(bobBlock.subscribed).toBe(true)
	})

	test('EDIT user removes the watcher again', async () => {
		await apiPut(`/cards/${state.cardId}/subscription/${BOB}`) // ensure present
		const del = await fetch(`${API}/cards/${state.cardId}/subscription/${BOB}`, {
			method: 'DELETE',
			headers: { ...HEADERS, Authorization: AUTH },
		})
		expect(del.ok).toBeTruthy()
		const block = await del.json()
		expect(block.subscribers).not.toContain(BOB)
	})

	test('cannot subscribe a user who cannot read the board (no card leak)', async () => {
		const put = await apiPut(`/cards/${state.cardId}/subscription/${STRANGER}`)
		// Server rejects: STRANGER is not a board participant.
		expect(put.ok).toBeFalsy()
		expect(put.status).toBeGreaterThanOrEqual(400)
	})
})
