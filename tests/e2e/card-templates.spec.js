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
	// Session is preloaded via storageState (see playwright.config.js) for the
	// default admin context — skip the UI login round-trip entirely. Specs that
	// opt out of storageState start with no cookies and fall through to log in.
	if ((await page.context().cookies()).some((c) => c.name === 'nc_username' || c.name === 'nc_session_id')) return
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	if (!(await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false))) return
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

// Live (non-template, non-archived) cards in a stack, top-to-bottom.
async function stackTitles(boardId, stackId) {
	const board = await api('GET', `/boards/${boardId}`)
	return board.cards
		.filter((c) => c.stackId === stackId && !c.archived)
		.sort((a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0))
		.map((c) => c.title)
}

// #3409 — per-board card templates: flag a card as a template, and create a new
// card pre-filled from it (title/description/labels/checklist cloned).
test.describe('Card templates (per-board)', () => {
	const state = { boardId: 0, todoId: 0, labelId: 0, tplId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Card-Templates E2E' })
		state.boardId = board.id
		state.todoId = (await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })).id

		const label = await api('POST', '/labels', { boardId: board.id, title: 'Blueprint', color: '2ecc71' })
		state.labelId = label.id

		// A card to turn into a template, enriched with content.
		const tpl = await api('POST', '/cards', { stackId: state.todoId, title: 'Bug report' })
		state.tplId = tpl.id
		await api('PATCH', `/cards/${tpl.id}`, { description: '## Steps to reproduce', priority: 3 })
		await api('PUT', `/cards/${tpl.id}/labels/${label.id}`)
		await api('POST', `/cards/${tpl.id}/checklist`, { title: 'Attach logs' })

		state.boardUrl = `${BASE}/index.php/apps/kanso/#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('marking a card as a template hides it from the live board and lists it in the picker', async () => {
		// Before: the card is a live card in its column.
		expect(await stackTitles(state.boardId, state.todoId)).toEqual(['Bug report'])
		expect(await api('GET', `/boards/${state.boardId}/cards/templates`)).toEqual([])

		// Mark it as a template (EDIT-gated).
		await api('PUT', `/cards/${state.tplId}/template`, { isTemplate: true })

		// It disappears from the live board render...
		expect(await stackTitles(state.boardId, state.todoId)).toEqual([])
		// ...and appears in the per-board template picker.
		const templates = await api('GET', `/boards/${state.boardId}/cards/templates`)
		expect(templates.map((c) => c.title)).toEqual(['Bug report'])
		expect(templates[0].id).toBe(state.tplId)
		expect(templates[0].isTemplate).toBe(true)
	})

	test('create-from-template clones title/description/labels/checklist into a fresh live card', async () => {
		const created = await api('POST', `/cards/${state.tplId}/create-from-template`, { targetStackId: state.todoId })

		// The new card is a distinct, live (non-template) card.
		expect(created.id).not.toBe(state.tplId)
		expect(created.isTemplate).toBe(false)
		expect(created.title).toBe('Bug report')
		expect(created.description).toBe('## Steps to reproduce')
		expect(created.priority).toBe(3)
		expect(created.labelIds).toContain(state.labelId)
		expect(created.checklistItems.map((i) => i.title)).toEqual(['Attach logs'])
		// Comments/assignees are NOT cloned.
		expect(created.assigneeIds).toEqual([])
		expect(created.commentCount).toBe(0)

		// The fresh card shows on the live board; the template still does not.
		expect(await stackTitles(state.boardId, state.todoId)).toEqual(['Bug report'])
		expect((await api('GET', `/boards/${state.boardId}/cards/templates`)).map((c) => c.id)).toEqual([state.tplId])
	})

	test('the composer "from template" picker creates a card from the template in the UI', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 10_000 })

		const liveBefore = (await stackTitles(state.boardId, state.todoId)).length

		// Open the column's "from template" picker and pick the template.
		await page.locator('.card-composer__templates button').first().click()
		await page.getByRole('menuitem', { name: 'Bug report' }).click()

		// A new live card is created from the template (one more than before),
		// while the template itself stays out of the live list.
		await expect
			.poll(() => stackTitles(state.boardId, state.todoId).then((t) => t.length), { timeout: 8_000 })
			.toBe(liveBefore + 1)
		const titles = await stackTitles(state.boardId, state.todoId)
		expect(titles.every((t) => t === 'Bug report')).toBe(true)
		// The template card is never rendered as a live card.
		const board = await api('GET', `/boards/${state.boardId}`)
		expect(board.cards.some((c) => c.id === state.tplId)).toBe(false)
	})
})
