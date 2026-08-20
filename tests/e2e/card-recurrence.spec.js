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
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	if (!(await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false))) return
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

// The card's own recurrence rule (its templateCardId points back at itself), if any.
async function cardRule(boardId, cardId) {
	const rules = await api('GET', `/boards/${boardId}/recur-rules`)
	return rules.find((r) => Number(r.templateCardId) === Number(cardId)) ?? null
}

test.describe('Repeat from the card due-date menu (#55)', () => {
	const state = { boardId: 0, stackId: 0, cardId: 0 }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Repeat ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api('POST', '/stacks', { boardId: board.id, title: 'Tasks' })).id
		state.cardId = (await api('POST', '/cards', { stackId: state.stackId, title: 'Water the plants' })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('setting Repeat creates, updates, then clears a recurring rule for the card', async ({ page }) => {
		// No rule to begin with.
		expect(await cardRule(state.boardId, state.cardId)).toBeNull()

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.cardId}`)
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 15_000 })

		// Open the due-date popover, where the Repeat control lives.
		await page.locator('[data-pill="due"]').click()
		const freq = page.locator('select[data-recur="freq"]')
		await expect(freq).toBeVisible({ timeout: 6_000 })

		// Choose "Weekly" → a rule is created with this card as its source and its
		// own column as the target, cloning on a FREQ=WEEKLY schedule.
		await freq.selectOption('WEEKLY')
		await expect.poll(
			async () => (await cardRule(state.boardId, state.cardId))?.rrule ?? null,
			{ timeout: 8_000 },
		).toContain('FREQ=WEEKLY')
		let rule = await cardRule(state.boardId, state.cardId)
		expect(Number(rule.templateCardId)).toBe(Number(state.cardId))
		expect(Number(rule.targetStackId)).toBe(Number(state.stackId))
		expect(Number(rule.mode)).toBe(0) // clone

		// Bump the interval to 2 → the same rule updates to every 2 weeks.
		const interval = page.locator('input[data-recur="interval"]')
		await interval.fill('2')
		await interval.blur()
		await expect.poll(
			async () => (await cardRule(state.boardId, state.cardId))?.rrule ?? '',
			{ timeout: 8_000 },
		).toContain('INTERVAL=2')

		// Turn Repeat off → the rule is deleted.
		await freq.selectOption('OFF')
		await expect.poll(
			async () => await cardRule(state.boardId, state.cardId),
			{ timeout: 8_000 },
		).toBeNull()
	})
})

test.describe('Recurring indicator on the board tile (#61)', () => {
	const state = { boardId: 0, stackId: 0, recurCardId: 0, plainCardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Recur Tile ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api('POST', '/stacks', { boardId: board.id, title: 'Chores' })).id
		state.recurCardId = (await api('POST', '/cards', { stackId: state.stackId, title: 'Recurring chore' })).id
		state.plainCardId = (await api('POST', '/cards', { stackId: state.stackId, title: 'One-off chore' })).id

		// Give the first card a live weekly rule (its own column is the target,
		// clone mode) - exactly what the due-date Repeat control creates.
		await api('POST', `/boards/${state.boardId}/recur-rules`, {
			templateCardId: state.recurCardId,
			targetStackId: state.stackId,
			mode: 0,
			rrule: 'FREQ=WEEKLY',
		})

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${state.boardId}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('board summary carries the recurring boolean derived from the rule', async () => {
		const payload = await api('GET', `/boards/${state.boardId}`)
		const byId = Object.fromEntries(payload.cards.map((c) => [c.id, c]))
		expect(byId[state.recurCardId].recurring).toBe(true)
		expect(byId[state.plainCardId].recurring).toBe(false)
	})

	test('repeat icon shows on the recurring tile and is absent on a normal tile', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		const recurTile = page.locator('.card-tile').filter({ hasText: 'Recurring chore' })
		const plainTile = page.locator('.card-tile').filter({ hasText: 'One-off chore' })

		await expect(recurTile.locator('.card-tile__recurring')).toBeVisible({ timeout: 10_000 })
		await expect(plainTile.locator('.card-tile__recurring')).toHaveCount(0)
	})
})
