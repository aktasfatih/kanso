// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, adminAuth, ncLogin, BASE, OCS } from './helpers.js'

const OCS_HEADERS = { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }

// Local provisionUser DELETES-then-creates and THROWS on failure (unlike the
// shared idempotent one) — this suite relies on a clean, freshly-created user,
// so it is kept intact per the migration contract (rule 6).
async function provisionUser(uid, password) {
	await fetch(`${OCS}/users/${uid}`, { method: 'DELETE', headers: { ...OCS_HEADERS, Authorization: adminAuth } }).catch(() => {})
	const body = new URLSearchParams({ userid: uid, password })
	const r = await fetch(`${OCS}/users`, {
		method: 'POST',
		headers: { ...OCS_HEADERS, Authorization: adminAuth, 'Content-Type': 'application/x-www-form-urlencoded' },
		body,
	})
	if (!r.ok) throw new Error(`provision ${uid} → ${r.status}: ${await r.text()}`)
}

async function deleteUser(uid) {
	await fetch(`${OCS}/users/${uid}`, { method: 'DELETE', headers: { ...OCS_HEADERS, Authorization: adminAuth } }).catch(() => {})
}

async function shareBoardWith(boardId, uid, permission) {
	return api.post(`/boards/${boardId}/acl`, { participant: uid, participantType: 'user', permission })
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

		for (const b of await api.get('/boards')) {
			if (b.title === 'Mentions E2E Board') await api.delete(`/boards/${b.id}`).catch(() => {})
		}
		const board = await api.post('/boards', { title: 'Mentions E2E Board' })
		state.boardId = board.id
		await shareBoardWith(board.id, BOB, 3) // READ | EDIT — a real participant
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id
		const card = await api.post('/cards', { stackId: stack.id, title: 'Mention Target Card' })
		state.cardId = card.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
		await deleteUser(BOB)
		await deleteUser(STRANGER)
	})

	test('mentioning a board participant auto-subscribes them (server-side)', async () => {
		await api.post(`/cards/${state.cardId}/comments`, { body: `Heads up @${BOB} — take a look` })

		const sub = await api.get(`/cards/${state.cardId}/subscription`)
		expect(sub.subscribers).toContain(BOB)
	})

	test('mentioning a non-member is inert (no subscribe, no leak)', async () => {
		// STRANGER is provisioned but NOT shared on this board.
		await api.post(`/cards/${state.cardId}/comments`, { body: `cc @${STRANGER} should be inert` })

		const sub = await api.get(`/cards/${state.cardId}/subscription`)
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
