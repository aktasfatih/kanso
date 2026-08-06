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
	if (!r.ok) throw new Error(`GET ${path} → ${r.status}: ${await r.text()}`)
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

async function apiPut(path, body) {
	const r = await fetch(API + path, {
		method: 'PUT',
		headers: { ...HEADERS, Authorization: AUTH },
		body: JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`PUT ${path} → ${r.status}: ${await r.text()}`)
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

test.describe('Custom fields', () => {
	const state = {
		boardId: 0,
		stackId: 0,
		cardId: 0,
		fields: {},
		cardUrl: '',
		boardUrl: '',
	}

	test.beforeAll(async () => {
		// Clean up any stale board from a previous run
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'Custom Fields E2E Board') {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		const board = await apiPost('/boards', { title: 'Custom Fields E2E Board' })
		state.boardId = board.id
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'Backlog' })
		state.stackId = stack.id
		const card = await apiPost('/cards', { stackId: stack.id, title: 'Card with fields' })
		state.cardId = card.id

		// Define one field per type via the API.
		state.fields.text = (await apiPost('/card-fields', {
			boardId: board.id, name: 'Owner note', type: 'text',
		})).id
		state.fields.number = (await apiPost('/card-fields', {
			boardId: board.id, name: 'Story points', type: 'number',
		})).id
		state.fields.date = (await apiPost('/card-fields', {
			boardId: board.id, name: 'Target date', type: 'date',
		})).id
		state.fields.select = (await apiPost('/card-fields', {
			boardId: board.id, name: 'Severity', type: 'select', options: ['low', 'high'],
		})).id

		// Set a value per field on the card (a set is an upsert).
		await apiPut(`/cards/${card.id}/fields/${state.fields.text}`, { value: 'ship it' })
		await apiPut(`/cards/${card.id}/fields/${state.fields.number}`, { value: '8' })
		await apiPut(`/cards/${card.id}/fields/${state.fields.date}`, { value: '2026-09-02' })
		await apiPut(`/cards/${card.id}/fields/${state.fields.select}`, { value: 'high' })

		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiDelete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	// ── Definitions ride the board payload; values ride card detail ──────────

	test('definitions are on the board payload, values only on card detail', async () => {
		const boardPayload = await apiGet(`/boards/${state.boardId}`)
		expect(Array.isArray(boardPayload.cardFields)).toBe(true)
		expect(boardPayload.cardFields.map((f) => f.name).sort())
			.toEqual(['Owner note', 'Severity', 'Story points', 'Target date'])
		// The board summary must NOT leak values.
		for (const c of boardPayload.cards) {
			expect(c.fieldValues).toBeUndefined()
		}

		const cardPayload = await apiGet(`/cards/${state.cardId}`)
		const byField = Object.fromEntries(cardPayload.fieldValues.map((v) => [v.fieldId, v.value]))
		expect(byField[state.fields.text]).toBe('ship it')
		expect(byField[state.fields.number]).toBe('8')
		expect(byField[state.fields.date]).toBe('2026-09-02')
		expect(byField[state.fields.select]).toBe('high')
	})

	// ── Card modal renders each field's current value as an editable input ───

	test('card modal renders the custom fields with their values', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-modal', { timeout: 12_000 })

		const section = page.locator('[data-test="card-custom-fields"]')
		await expect(section).toBeVisible({ timeout: 8_000 })

		await expect(section.locator(`[data-test="cf-input-${state.fields.text}"]`)).toHaveValue('ship it')
		await expect(section.locator(`[data-test="cf-input-${state.fields.number}"]`)).toHaveValue('8')
		await expect(section.locator(`[data-test="cf-input-${state.fields.date}"]`)).toHaveValue('2026-09-02')
		await expect(section.locator(`[data-test="cf-select-${state.fields.select}"]`)).toHaveValue('high')
	})

	// ── Editing a value in the modal upserts it ──────────────────────────────

	test('editing a text field in the modal persists the new value', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-modal', { timeout: 12_000 })

		const input = page.locator(`[data-test="cf-input-${state.fields.text}"]`)
		await input.fill('done and dusted')
		await input.blur()

		await expect.poll(async () => {
			const cardPayload = await apiGet(`/cards/${state.cardId}`)
			const byField = Object.fromEntries(cardPayload.fieldValues.map((v) => [v.fieldId, v.value]))
			return byField[state.fields.text]
		}, { timeout: 8_000 }).toBe('done and dusted')
	})

	// ── Board settings: create a new field via the UI form ───────────────────

	test('can define a new field via the board settings panel', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		await page.getByRole('button', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /custom fields/i }).click()

		await page.locator('[data-test="cf-name-input"]').fill('Team')
		await page.locator('[data-test="cf-type-select"]').selectOption('text')
		await page.locator('[data-test="cf-create-form"]').getByRole('button', { name: /add|create/i }).click()

		await expect.poll(async () => {
			const boardPayload = await apiGet(`/boards/${state.boardId}`)
			return boardPayload.cardFields.some((f) => f.name === 'Team')
		}, { timeout: 8_000 }).toBe(true)
	})
})
