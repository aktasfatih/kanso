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

const ROLE_REVIEW = 4
const ROLE_IN_PROGRESS = 3

test.describe('Automation rules (#3400)', () => {
	const state = { boardId: 0, todoStackId: 0, reviewStackId: 0, progStackId: 0, labelId: 0 }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Automation ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.todoStackId = (await api('POST', '/stacks', { boardId: board.id, title: 'To do' })).id
		state.reviewStackId = (await api('POST', '/stacks', { boardId: board.id, title: 'Review' })).id
		state.progStackId = (await api('POST', '/stacks', { boardId: board.id, title: 'Doing' })).id
		await api('PATCH', `/stacks/${state.reviewStackId}`, { role: ROLE_REVIEW })
		await api('PATCH', `/stacks/${state.progStackId}`, { role: ROLE_IN_PROGRESS })
		state.labelId = (await api('POST', '/labels', { boardId: board.id, title: 'Auto-tagged', color: 'e74c3c' })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('entering a review-role column fires the request_review rule', async () => {
		await api('POST', `/boards/${state.boardId}/automation-rules`, {
			trigger: 'card_entered_role',
			action: 'request_review',
			params: { role: ROLE_REVIEW, reviewer: USER },
		})
		const cardId = (await api('POST', '/cards', { stackId: state.todoStackId, title: 'Needs review' })).id

		let card = await api('GET', `/cards/${cardId}`)
		expect(card.reviews ?? []).toHaveLength(0)

		await api('POST', `/cards/${cardId}/move`, { targetStackId: state.reviewStackId, afterCardId: null })

		card = await api('GET', `/cards/${cardId}`)
		expect(card.reviews.some((rv) => rv.reviewer === USER)).toBe(true)
	})

	test('entering an in-progress-role column fires the add_label rule', async () => {
		await api('POST', `/boards/${state.boardId}/automation-rules`, {
			trigger: 'card_entered_role',
			action: 'add_label',
			params: { role: ROLE_IN_PROGRESS, label: state.labelId },
		})
		const cardId = (await api('POST', '/cards', { stackId: state.todoStackId, title: 'Gets a label' })).id

		let card = await api('GET', `/cards/${cardId}`)
		expect(card.labelIds ?? []).not.toContain(state.labelId)

		await api('POST', `/cards/${cardId}/move`, { targetStackId: state.progStackId, afterCardId: null })

		card = await api('GET', `/cards/${cardId}`)
		expect(card.labelIds).toContain(state.labelId)
	})

	test('a label from another board is rejected at rule creation', async () => {
		const other = await api('POST', '/boards', { title: 'Other ' + Math.floor(Date.now() / 1000) })
		const foreignLabel = (await api('POST', '/labels', { boardId: other.id, title: 'Foreign', color: null })).id
		const r = await fetch(API + `/boards/${state.boardId}/automation-rules`, {
			method: 'POST',
			headers: { ...HEADERS, Authorization: AUTH },
			body: JSON.stringify({ trigger: 'card_entered_role', action: 'add_label', params: { role: ROLE_REVIEW, label: foreignLabel } }),
		})
		expect(r.ok).toBe(false)
		await api('DELETE', `/boards/${other.id}`).catch(() => {})
	})

	test('a rule created in the settings panel persists and lists', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)

		// Open board settings → Automation tab.
		await page.getByRole('button', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /automation/i }).click()

		// The card-rules "Add rule" form: pick In-progress role + add-label, then submit.
		const roleSelect = page.locator(`#auto-role-${state.boardId}`)
		await expect(roleSelect).toBeVisible({ timeout: 8_000 })
		await roleSelect.selectOption(String(ROLE_IN_PROGRESS))
		await page.locator(`#auto-action-${state.boardId}`).selectOption('add_label')
		await page.locator(`#auto-label-${state.boardId}`).selectOption(String(state.labelId))
		await page.getByRole('button', { name: /^Add rule$/ }).last().click()

		// It shows up in the rules list with the readable description.
		await expect(page.locator('.automation__rule-desc', { hasText: /add label "Auto-tagged"/ }).first())
			.toBeVisible({ timeout: 8_000 })

		// And it survives a reload (server round-trip via GET automation-rules).
		const rules = await api('GET', `/boards/${state.boardId}/automation-rules`)
		expect(rules.some((rl) => rl.action === 'add_label' && rl.params.label === state.labelId)).toBe(true)
	})
})
