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

// Cards in a stack, top-to-bottom, from the board summary payload.
async function stackTitles(boardId, stackId) {
	const board = await api('GET', `/boards/${boardId}`)
	return board.cards
		.filter((c) => c.stackId === stackId && !c.archived)
		.sort((a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0))
		.map((c) => c.title)
}

// #3542 — "Copy to…" from the card ⋯ menu: duplicate a card into another column.
test.describe('Copy card to another stack (card ⋯ menu)', () => {
	const state = { boardId: 0, todoId: 0, doneId: 0, labelId: 0, srcId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Card-Copy E2E' })
		state.boardId = board.id
		state.todoId = (await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })).id
		state.doneId = (await api('POST', '/stacks', { boardId: board.id, title: 'Target Column' })).id

		const label = await api('POST', '/labels', { boardId: board.id, title: 'Important', color: 'e01e01' })
		state.labelId = label.id

		const src = await api('POST', '/cards', { stackId: state.todoId, title: 'Original card' })
		state.srcId = src.id
		// Enrich the source: description, priority, a label and a checklist item.
		await api('PATCH', `/cards/${src.id}`, { description: 'The full spec.', priority: 4 })
		await api('PUT', `/cards/${src.id}/labels/${label.id}`)
		await api('POST', `/cards/${src.id}/checklist`, { title: 'Do the thing' })

		state.cardUrl = `${BASE}/index.php/apps/kanso/#/board/${board.id}/card/${src.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('copies the card into the target column with cloned content', async ({ page }) => {
		// Precondition: the target column is empty.
		expect(await stackTitles(state.boardId, state.doneId)).toEqual([])

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Open the ⋯ menu and click "Copy to…".
		await page.locator('.card-modal__actions-menu button').first().click()
		await page.getByRole('menuitem', { name: 'Copy to…' }).click()

		// The copy dialog opens; pick the target column and confirm.
		await page.waitForSelector('.card-modal__copy-dialog', { timeout: 8_000 })
		await page.locator('.card-modal__copy-field select').nth(1)
			.selectOption({ label: 'Target Column' })
		await page.getByRole('button', { name: 'Copy', exact: true }).click()

		// The duplicate appears in the target column titled "… (copy)".
		await expect
			.poll(() => stackTitles(state.boardId, state.doneId), { timeout: 8_000 })
			.toEqual(['Original card (copy)'])

		// Fetch the duplicate and assert content was cloned, but NOT assignees.
		const board = await api('GET', `/boards/${state.boardId}`)
		const copy = board.cards.find((c) => c.stackId === state.doneId && !c.archived)
		expect(copy).toBeTruthy()
		expect(copy.id).not.toBe(state.srcId)

		const detail = await api('GET', `/cards/${copy.id}`)
		expect(detail.description).toBe('The full spec.')
		expect(detail.priority).toBe(4)
		// Same-board copy keeps the label directly.
		expect(detail.labelIds).toContain(state.labelId)
		// Checklist item is cloned.
		expect(detail.checklistItems.map((i) => i.title)).toEqual(['Do the thing'])
		// Assignees are OFF by default.
		expect(detail.assigneeIds).toEqual([])
		// The original is untouched (still the only card in the source column).
		expect(await stackTitles(state.boardId, state.todoId)).toEqual(['Original card'])
	})
})
