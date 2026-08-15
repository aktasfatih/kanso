// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Card types (#3402): exactly one built-in type per card (bug/feature/task/
// chore), icon-first on the tile, pickable in the modal, filterable in the bar.

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

test.describe('Card types', () => {
	const BOARD_TITLE = 'Type Test Board ' + Date.now()
	const state = {
		boardId: 0,
		stackId: 0,
		bugCardId: 0,
		featureCardId: 0,
		boardUrl: '',
	}

	test.beforeAll(async () => {
		// Tear down any prior board with the same title prefix for hermeticity
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title.startsWith('Type Test Board')) {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		const board = await apiPost('/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'Backlog' })
		state.stackId = stack.id

		const bugCard = await apiPost('/cards', { stackId: stack.id, title: 'Bug Type Card' })
		state.bugCardId = bugCard.id
		const featureCard = await apiPost('/cards', { stackId: stack.id, title: 'Feature Type Card' })
		state.featureCardId = featureCard.id

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiDelete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	// The Type pill is identified by its text; the popover exposes the built-in
	// options. Locates the pill by the "Type" label (dashed placeholder state).
	function typePill(page) {
		return page.locator('.card-modal__attrbar button.card-modal__pill', { hasText: 'Type' })
	}

	test('set type to Bug via the card modal UI; assert tile shows the type icon', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		const cardTile = page.locator('.card-tile').filter({ hasText: 'Bug Type Card' })
		await expect(cardTile).toBeVisible({ timeout: 5000 })
		await cardTile.click()

		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const pill = typePill(page)
		await expect(pill).toBeVisible({ timeout: 5000 })
		await pill.click()

		// Pick "Bug" from the type popover
		await page.locator('.card-modal__popover .card-modal__popover-opt', { hasText: 'Bug' }).click()

		// The pill should pick up the --type-bug modifier
		await expect(page.locator('.card-modal__attrbar .card-modal__pill--type-bug'))
			.toBeVisible({ timeout: 5000 })

		await page.keyboard.press('Escape')
		await page.waitForSelector('.card-modal', { state: 'hidden', timeout: 5000 }).catch(() => {})

		// The tile should now show the bug type icon
		const typeIcon = page.locator('.card-tile')
			.filter({ hasText: 'Bug Type Card' })
			.locator('.card-tile__type--bug')
		await expect(typeIcon).toBeVisible({ timeout: 5000 })
	})

	test('set type to Feature on the second card; assert its tile icon', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		const featureTile = page.locator('.card-tile').filter({ hasText: 'Feature Type Card' })
		await expect(featureTile).toBeVisible({ timeout: 5000 })
		await featureTile.click()

		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const pill = typePill(page)
		await expect(pill).toBeVisible({ timeout: 5000 })
		await pill.click()
		await page.locator('.card-modal__popover .card-modal__popover-opt', { hasText: 'Feature' }).click()
		await expect(page.locator('.card-modal__attrbar .card-modal__pill--type-feature'))
			.toBeVisible({ timeout: 5000 })

		await page.keyboard.press('Escape')
		await page.waitForSelector('.card-modal', { state: 'hidden', timeout: 5000 }).catch(() => {})

		const featureIcon = page.locator('.card-tile')
			.filter({ hasText: 'Feature Type Card' })
			.locator('.card-tile__type--feature')
		await expect(featureIcon).toBeVisible({ timeout: 5000 })
	})

	test('filter to Bug only - Feature card is hidden; clear filter restores it', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// Both cards visible initially
		await expect(page.locator('.card-tile').filter({ hasText: 'Bug Type Card' }))
			.toBeVisible({ timeout: 5000 })
		await expect(page.locator('.card-tile').filter({ hasText: 'Feature Type Card' }))
			.toBeVisible({ timeout: 5000 })

		// Open the filter popover and drill into the Type dimension (#3785).
		const filterMenu = page.locator('.board-filter-bar__filter button').first()
		await expect(filterMenu).toBeVisible({ timeout: 5000 })
		await filterMenu.click()
		await page.locator('.board-filter-bar__dim-row[data-dim="types"]').click()

		// Check the "Bug" type filter
		const bugFilter = page.locator('.board-filter-bar__type-item--bug')
		await expect(bugFilter).toBeVisible({ timeout: 5000 })
		await bugFilter.click()

		await page.keyboard.press('Escape')
		await page.waitForTimeout(300)

		// Bug card visible; Feature card hidden
		await expect(page.locator('.card-tile').filter({ hasText: 'Bug Type Card' }))
			.toBeVisible({ timeout: 5000 })
		await expect(page.locator('.card-tile').filter({ hasText: 'Feature Type Card' }))
			.not.toBeVisible({ timeout: 5000 })

		// Clear the filter by re-opening, drilling into Type, and unchecking Bug
		await filterMenu.click()
		await page.locator('.board-filter-bar__dim-row[data-dim="types"]').click()
		const bugAgain = page.locator('.board-filter-bar__type-item--bug')
		await expect(bugAgain).toBeVisible({ timeout: 5000 })
		await bugAgain.click()
		await page.keyboard.press('Escape')
		await page.waitForTimeout(300)

		// Both visible again
		await expect(page.locator('.card-tile').filter({ hasText: 'Bug Type Card' }))
			.toBeVisible({ timeout: 5000 })
		await expect(page.locator('.card-tile').filter({ hasText: 'Feature Type Card' }))
			.toBeVisible({ timeout: 5000 })
	})

	test('type persists after page reload', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// The Bug Type Card tile should still carry the bug type icon
		const bugIcon = page.locator('.card-tile')
			.filter({ hasText: 'Bug Type Card' })
			.locator('.card-tile__type--bug')
		await expect(bugIcon).toBeVisible({ timeout: 8000 })

		// The modal pill should still carry the --type-bug modifier
		const bugTile = page.locator('.card-tile').filter({ hasText: 'Bug Type Card' })
		await bugTile.click()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })
		await expect(page.locator('.card-modal__attrbar .card-modal__pill--type-bug'))
			.toBeVisible({ timeout: 5000 })
	})
})
