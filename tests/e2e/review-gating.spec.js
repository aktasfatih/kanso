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
		body: body ? JSON.stringify(body) : undefined,
	})
	if (!r.ok) throw new Error(`PUT ${path} → ${r.status}: ${await r.text()}`)
	return r.json()
}

async function apiPatch(path, body) {
	const r = await fetch(API + path, {
		method: 'PATCH',
		headers: { ...HEADERS, Authorization: AUTH },
		body: body ? JSON.stringify(body) : undefined,
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

test.describe('Review-type stage gating', () => {
	const state = {
		boardId: 0,
		stackId: 0,
		cardId: 0,
		codeTypeId: 0,
		qaTypeId: 0,
		cardUrl: '',
	}

	test.beforeAll(async () => {
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'Review Gating E2E Board') {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		const board = await apiPost('/boards', { title: 'Review Gating E2E Board' })
		state.boardId = board.id
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'Backlog' })
		state.stackId = stack.id
		const card = await apiPost('/cards', { stackId: stack.id, title: 'Gated card' })
		state.cardId = card.id

		// Code = stage 0, QA = stage 1. Lower stage gates higher.
		const code = await apiPost('/review-types', { boardId: board.id, title: 'Code', stage: 0 })
		state.codeTypeId = code.id
		const qa = await apiPost('/review-types', { boardId: board.id, title: 'QA', stage: 1 })
		state.qaTypeId = qa.id

		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiDelete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('QA renders gated while Code is pending, then un-gates once Code is approved', async ({ page }) => {
		// Request both reviews from admin (one per type is allowed on the same card).
		await apiPut(`/cards/${state.cardId}/reviews/${USER}`, { reviewTypeId: state.codeTypeId })
		await apiPut(`/cards/${state.cardId}/reviews/${USER}`, { reviewTypeId: state.qaTypeId })

		// The server should mark the QA (stage 1) review gated, blocked by the Code
		// (stage 0) review, and QA's notification should be deferred (notifiedAt null).
		let detail = await apiGet(`/cards/${state.cardId}`)
		let qa = detail.reviews.find((r) => r.reviewTypeId === state.qaTypeId)
		let code = detail.reviews.find((r) => r.reviewTypeId === state.codeTypeId)
		expect(qa.gated).toBe(true)
		expect(qa.blockedBy).toContain(code.id)
		expect(qa.notifiedAt).toBeNull()
		// Code (stage 0) is not gated and was notified at request time.
		expect(code.gated).toBe(false)

		// The QA chip should render distinctly gated in the modal.
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-modal', { timeout: 12_000 })
		const gatedChip = page.locator('.card-modal__review-pill--gated')
		await expect(gatedChip).toBeVisible({ timeout: 8_000 })
		await expect(gatedChip.locator('.card-modal__review-type-badge')).toContainText('QA')

		// Approve the Code review → QA un-gates and its deferred notification fires.
		await apiPatch(`/cards/${state.cardId}/reviews/${code.id}`, { state: 'approved' })

		detail = await apiGet(`/cards/${state.cardId}`)
		qa = detail.reviews.find((r) => r.reviewTypeId === state.qaTypeId)
		expect(qa.gated).toBe(false)
		expect(qa.blockedBy).toEqual([])
		expect(qa.state).toBe('pending')
		// The deferred notification fired: notifiedAt is now stamped.
		expect(qa.notifiedAt).not.toBeNull()

		// The gated chip is gone in the UI after reload.
		await page.reload()
		await page.waitForSelector('.card-modal', { timeout: 12_000 })
		await expect(page.locator('.card-modal__review-pill--gated')).toHaveCount(0, { timeout: 8_000 })
	})
})
