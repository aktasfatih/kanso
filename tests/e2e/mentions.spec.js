// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const OCS = BASE + '/ocs/v2.php/cloud'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const OCS_HEADERS = { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }
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
	await fetch(API + path, { method: 'DELETE', headers: { ...HEADERS, Authorization: AUTH } }).catch(() => {})
}

async function provisionUser(uid, password) {
	await fetch(`${OCS}/users/${uid}`, { method: 'DELETE', headers: { ...OCS_HEADERS, Authorization: AUTH } }).catch(() => {})
	const body = new URLSearchParams({ userid: uid, password })
	const r = await fetch(`${OCS}/users`, {
		method: 'POST',
		headers: { ...OCS_HEADERS, Authorization: AUTH, 'Content-Type': 'application/x-www-form-urlencoded' },
		body,
	})
	if (!r.ok) throw new Error(`provision ${uid} → ${r.status}: ${await r.text()}`)
}

async function deleteUser(uid) {
	await fetch(`${OCS}/users/${uid}`, { method: 'DELETE', headers: { ...OCS_HEADERS, Authorization: AUTH } }).catch(() => {})
}

async function shareBoardWith(boardId, uid, permission) {
	const r = await fetch(`${API}/boards/${boardId}/acl`, {
		method: 'POST',
		headers: { ...HEADERS, Authorization: AUTH },
		body: JSON.stringify({ participant: uid, participantType: 'user', permission }),
	})
	if (!r.ok) throw new Error(`share ${boardId}→${uid} → ${r.status}: ${await r.text()}`)
	return r.json()
}

async function ncLogin(page) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	const userInput = page.locator('#user')
	const isLoginPage = await userInput.isVisible({ timeout: 3000 }).catch(() => false)
	if (!isLoginPage) return
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

test.describe('@mentions in comments', () => {
	const BOB = 'kanso_mention_bob'
	const BOB_PASS = 'Mention#Bob2026'
	const STRANGER = 'kanso_mention_stranger'
	const STRANGER_PASS = 'Mention#Stranger2026'
	const state = { boardId: 0, stackId: 0, cardId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		await provisionUser(BOB, BOB_PASS)
		await provisionUser(STRANGER, STRANGER_PASS)

		for (const b of await apiGet('/boards')) {
			if (b.title === 'Mentions E2E Board') await apiDelete(`/boards/${b.id}`)
		}
		const board = await apiPost('/boards', { title: 'Mentions E2E Board' })
		state.boardId = board.id
		await shareBoardWith(board.id, BOB, 3) // READ | EDIT — a real participant
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id
		const card = await apiPost('/cards', { stackId: stack.id, title: 'Mention Target Card' })
		state.cardId = card.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await apiDelete(`/boards/${state.boardId}`)
		await deleteUser(BOB)
		await deleteUser(STRANGER)
	})

	test('mentioning a board participant auto-subscribes them (server-side)', async () => {
		await apiPost(`/cards/${state.cardId}/comments`, { body: `Heads up @${BOB} — take a look` })

		const sub = await apiGet(`/cards/${state.cardId}/subscription`)
		expect(sub.subscribers).toContain(BOB)
	})

	test('mentioning a non-member is inert (no subscribe, no leak)', async () => {
		// STRANGER is provisioned but NOT shared on this board.
		await apiPost(`/cards/${state.cardId}/comments`, { body: `cc @${STRANGER} should be inert` })

		const sub = await apiGet(`/cards/${state.cardId}/subscription`)
		expect(sub.subscribers).not.toContain(STRANGER)
	})

	test('a mention renders as a chip in the comment body', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// The comment posted in the first test contains @BOB — it should render as
		// a .kanso-mention chip in the sanitized markdown output.
		const chip = page.locator('.card-modal__comment-body .kanso-mention').first()
		await expect(chip).toBeVisible({ timeout: 8000 })
		await expect(chip).toContainText(`@${BOB}`)
	})
})
