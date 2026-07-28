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

async function rawPatch(path, body) {
	return fetch(API + path, { method: 'PATCH', headers: { ...HEADERS, Authorization: AUTH }, body: JSON.stringify(body) })
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

test.describe('Card estimations (#3443)', () => {
	const state = { boardId: 0, stackId: 0, cardId: 0, title: '' }

	test.beforeAll(async () => {
		state.title = 'Estimate me ' + Math.floor(Date.now() / 1000)
		const board = await api('POST', '/boards', { title: 'Estimates ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api('POST', '/stacks', { boardId: board.id, title: 'To do' })).id
		state.cardId = (await api('POST', '/cards', { stackId: state.stackId, title: state.title })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('a board defaults to the none scale and can switch scales', async () => {
		let board = await api('GET', `/boards/${state.boardId}`)
		expect(board.board.estimateScale).toBe('none')

		await api('PATCH', `/boards/${state.boardId}`, { estimateScale: 'fibonacci' })
		board = await api('GET', `/boards/${state.boardId}`)
		expect(board.board.estimateScale).toBe('fibonacci')
	})

	test('an unknown scale is rejected', async () => {
		const r = await rawPatch(`/boards/${state.boardId}`, { estimateScale: 'made-up' })
		expect(r.ok).toBe(false)
	})

	test('a card estimate must belong to the board scale', async () => {
		// Board is fibonacci from the earlier test. A valid token sticks…
		await api('PATCH', `/cards/${state.cardId}`, { estimate: '8' })
		expect((await api('GET', `/cards/${state.cardId}`)).estimate).toBe('8')

		// …an off-scale token is rejected…
		expect((await rawPatch(`/cards/${state.cardId}`, { estimate: '4' })).ok).toBe(false)

		// …and '' clears it.
		await api('PATCH', `/cards/${state.cardId}`, { estimate: '' })
		expect((await api('GET', `/cards/${state.cardId}`)).estimate).toBeNull()
	})

	test('set an estimate from the card modal → chip shows on the tile', async ({ page }) => {
		await api('PATCH', `/boards/${state.boardId}`, { estimateScale: 'fibonacci' })
		await api('PATCH', `/cards/${state.cardId}`, { estimate: '' })

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.cardId}`)
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 15_000 })

		// The estimate pill renders because the board scale is not 'none'.
		const estimatePill = page.locator('.card-modal__attrbar button.card-modal__pill', { hasText: 'Estimate' })
		await expect(estimatePill).toBeVisible({ timeout: 8_000 })

		// Open the estimate popover and click the "8" token (exact text so it
		// doesn't match "13"/"21").
		await estimatePill.click()
		const btn8 = page.locator('.card-modal__popover .card-modal__popover-opt', { hasText: /^8$/ })
		await btn8.click()

		// The pill now reflects the chosen estimate.
		await expect(page.locator('.card-modal__attrbar button.card-modal__pill', { hasText: 'Estimate: 8' }))
			.toBeVisible({ timeout: 6_000 })

		// Close the modal → the tile shows the estimate chip.
		await page.keyboard.press('Escape')
		const tile = page.locator('.card-tile', { hasText: state.title })
		await expect(tile.locator('.card-tile__estimate')).toHaveText('8', { timeout: 8_000 })
	})

	test('the board settings Workflow tab switches the scale', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.getByRole('button', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /workflow/i }).click()

		const select = page.locator(`#estimate-scale-${state.boardId}`)
		await expect(select).toBeVisible({ timeout: 8_000 })
		await select.selectOption('tshirt')

		await expect.poll(async () => (await api('GET', `/boards/${state.boardId}`)).board.estimateScale, { timeout: 8_000 })
			.toBe('tshirt')
	})
})
