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

// Live (non-archived) cards in a stack, top-to-bottom, from the board payload.
async function stackTitles(boardId, stackId) {
	const board = await api('GET', `/boards/${boardId}`)
	return board.cards
		.filter((c) => c.stackId === stackId && !c.archived)
		.sort((a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0))
		.map((c) => c.title)
}

// #3679 — "Move to board…" from the card ⋯ menu: relocate a single card to
// another board (created on the target, removed from the source).
test.describe('Move card to another board (card ⋯ menu)', () => {
	const state = { srcBoardId: 0, dstBoardId: 0, srcStackId: 0, dstStackId: 0, labelId: 0, srcId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const src = await api('POST', '/boards', { title: 'Move-Src E2E' })
		state.srcBoardId = src.id
		state.srcStackId = (await api('POST', '/stacks', { boardId: src.id, title: 'Source Column' })).id

		const dst = await api('POST', '/boards', { title: 'Move-Dst E2E' })
		state.dstBoardId = dst.id
		state.dstStackId = (await api('POST', '/stacks', { boardId: dst.id, title: 'Landing Column' })).id
		// A matching label (same name + color) on BOTH boards so the map-over keeps it.
		await api('POST', '/labels', { boardId: src.id, title: 'Important', color: 'e01e01' })
		const dstLabel = await api('POST', '/labels', { boardId: dst.id, title: 'Important', color: 'e01e01' })
		state.labelId = dstLabel.id

		const card = await api('POST', '/cards', { stackId: state.srcStackId, title: 'Relocatable card' })
		state.srcId = card.id
		await api('PATCH', `/cards/${card.id}`, { description: 'travels with me', priority: 3 })
		const srcLabels = await api('GET', `/boards/${src.id}`)
		const srcLabelId = srcLabels.labels.find((l) => l.title === 'Important').id
		await api('PUT', `/cards/${card.id}/labels/${srcLabelId}`)
		await api('POST', `/cards/${card.id}/checklist`, { title: 'carry me too' })

		state.cardUrl = `${BASE}/index.php/apps/kanso/#/board/${src.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.srcBoardId) await api('DELETE', `/boards/${state.srcBoardId}`).catch(() => {})
		if (state.dstBoardId) await api('DELETE', `/boards/${state.dstBoardId}`).catch(() => {})
	})

	test('moves the card off the source board and onto the target', async ({ page }) => {
		// Preconditions: source has the card, target is empty.
		expect(await stackTitles(state.srcBoardId, state.srcStackId)).toEqual(['Relocatable card'])
		expect(await stackTitles(state.dstBoardId, state.dstStackId)).toEqual([])

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Open the ⋯ menu and click "Move to board…".
		await page.locator('.card-modal__actions-menu button').first().click()
		await page.getByRole('menuitem', { name: 'Move to board…' }).click()

		// The move dialog opens (reuses the copy picker); pick the target board+column.
		await page.waitForSelector('.card-modal__copy-dialog', { timeout: 8_000 })
		await page.locator('.card-modal__copy-field select').first()
			.selectOption({ label: 'Move-Dst E2E' })
		await page.locator('.card-modal__copy-field select').nth(1)
			.selectOption({ label: 'Landing Column' })
		await page.getByRole('button', { name: 'Move', exact: true }).click()

		// The card LEFT the source board...
		await expect
			.poll(() => stackTitles(state.srcBoardId, state.srcStackId), { timeout: 8_000 })
			.toEqual([])
		// ...and APPEARS on the target board.
		await expect
			.poll(() => stackTitles(state.dstBoardId, state.dstStackId), { timeout: 8_000 })
			.toEqual(['Relocatable card'])

		// The relocated card is a NEW card (fresh id) carrying the content + mapped label.
		const dstBoard = await api('GET', `/boards/${state.dstBoardId}`)
		const moved = dstBoard.cards.find((c) => c.stackId === state.dstStackId && !c.archived)
		expect(moved).toBeTruthy()
		expect(moved.id).not.toBe(state.srcId)

		const detail = await api('GET', `/cards/${moved.id}`)
		expect(detail.description).toBe('travels with me')
		expect(detail.priority).toBe(3)
		expect(detail.labelIds).toContain(state.labelId)
		expect(detail.checklistItems.map((i) => i.title)).toEqual(['carry me too'])

		// The original id is gone (soft-deleted on the source): its detail 404s.
		const r = await fetch(`${API}/cards/${state.srcId}`, {
			headers: { ...HEADERS, Authorization: AUTH },
		})
		expect(r.status).toBe(404)
	})
})
