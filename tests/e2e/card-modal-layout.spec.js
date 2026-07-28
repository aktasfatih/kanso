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

test.describe('Card modal two-column layout', () => {
	// Unique board title to avoid collision with parallel test runs
	const BOARD_TITLE = 'Modal Layout E2E Board ' + Date.now()
	const state = {
		boardId: 0,
		stackId: 0,
		cardId: 0,
		cardUrl: '',
	}

	test.beforeAll(async () => {
		// Tear down any stale board with the same name
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === BOARD_TITLE) {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		// Create board + stack + card via API
		const board = await apiPost('/boards', { title: BOARD_TITLE })
		state.boardId = board.id

		const stack = await apiPost('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id

		const card = await apiPost('/cards', {
			stackId: stack.id,
			title: 'Layout Test Card',
			description: 'This is the card description used to verify left column placement.',
		})
		state.cardId = card.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`

		// Set a due date via API
		await apiPatch(`/cards/${card.id}`, {
			duedate: new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString(),
		})

		// Set priority to High (3) via API
		await apiPatch(`/cards/${card.id}`, { priority: 3 })
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiDelete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('description and composer are in .card-modal__content / .card-modal__discussion', async ({ page }) => {
		// Use a wide viewport so the two-pane body layout is active
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)

		// Wait for the card modal content pane to be visible
		await page.waitForSelector('.card-modal__content', { timeout: 15_000 })

		// Description section lives in the left content pane
		const contentLocator = page.locator('.card-modal__content')
		await expect(contentLocator.locator('.card-modal__desc-view, .card-modal__desc-placeholder').first()).toBeVisible()

		// The new-thread composer textarea lives in the right discussion pane
		const discussion = page.locator('.card-modal__discussion')
		await expect(discussion.locator('.card-modal__composer-textarea').first()).toBeVisible()
	})

	test('due-date pill and priority pill live in the attribute bar', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)

		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		const attrbar = page.locator('.card-modal__attrbar')

		// Priority is set to High (3) via API in beforeAll - the pill carries the modifier
		await expect(attrbar.locator('.card-modal__pill--priority-3')).toBeVisible()

		// The due-date pill is the second pill in the attribute bar (priority, then
		// due). Opening it reveals the padded date popover with the date input.
		await attrbar.locator('button.card-modal__pill').nth(1).click()
		await expect(page.locator('.card-modal__popover--pad .card-modal__date-input').first()).toBeVisible({ timeout: 5_000 })
	})

	test('.card-modal__content is to the LEFT of .card-modal__discussion on wide viewport', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)

		await page.waitForSelector('.card-modal__body', { timeout: 15_000 })

		const contentBox = await page.locator('.card-modal__content').boundingBox()
		const discussionBox = await page.locator('.card-modal__discussion').boundingBox()

		expect(contentBox).not.toBeNull()
		expect(discussionBox).not.toBeNull()

		// The content pane's left edge must be strictly to the left of the discussion pane
		expect(contentBox.x).toBeLessThan(discussionBox.x)
		// And they must not overlap horizontally
		expect(contentBox.x + contentBox.width).toBeLessThanOrEqual(discussionBox.x + 4) // 4px tolerance for rounding
	})

	test('priority pill opens a popover and selecting High marks the option active', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)

		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		const attrbar = page.locator('.card-modal__attrbar')
		// The priority pill is the first pill in the attribute bar (has the flag icon).
		const priorityPill = attrbar.locator('button.card-modal__pill').first()
		await expect(priorityPill).toBeVisible()

		// Open the priority popover
		await priorityPill.click()
		const popover = page.locator('.card-modal__popover')
		await expect(popover.first()).toBeVisible({ timeout: 5_000 })

		// Set None first
		await popover.locator('.card-modal__popover-opt', { hasText: /^None$/ }).click()
		await expect(attrbar.locator('.card-modal__pill--priority-3')).toHaveCount(0, { timeout: 5_000 })

		// Now set High - the pill picks up the --priority-3 modifier
		await priorityPill.click()
		await page.locator('.card-modal__popover .card-modal__popover-opt', { hasText: /^High$/ }).click()
		await expect(attrbar.locator('.card-modal__pill--priority-3')).toBeVisible({ timeout: 5_000 })
	})

	test('body stacks to a single column on narrow viewport', async ({ page }) => {
		await page.setViewportSize({ width: 500, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)

		await page.waitForSelector('.card-modal__body', { timeout: 15_000 })

		// On narrow viewports the modal switches to a tabbed layout: only the
		// active pane is shown. The "Card" tab is active by default → the content
		// pane is visible and the discussion pane is hidden.
		await expect(page.locator('.card-modal__content')).toBeVisible()
		await expect(page.locator('.card-modal__tabbar')).toBeVisible()

		// Switching to the Discussion tab reveals the discussion pane.
		await page.locator('.card-modal__tab', { hasText: 'Discussion' }).click()
		await expect(page.locator('.card-modal__discussion')).toBeVisible({ timeout: 5_000 })
	})

	test('round clear/× buttons are circles, not ovals (#3492)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)

		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		// Open the due-date pill (2nd pill; the card has a due date set in beforeAll)
		// to reveal its round clear (×) button.
		await page.locator('.card-modal__attrbar button.card-modal__pill').nth(1).click()
		const clearBtn = page.locator('.card-modal__field-clear').first()
		await expect(clearBtn).toBeVisible({ timeout: 5_000 })

		// A circle: width and height must be equal (±1px), never squished into an oval.
		const box = await clearBtn.boundingBox()
		expect(box).not.toBeNull()
		expect(Math.abs(box.width - box.height)).toBeLessThanOrEqual(1)
	})
})
