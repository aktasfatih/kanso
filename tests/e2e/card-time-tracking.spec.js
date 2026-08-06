// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')

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

async function api(method, path, body) {
	const r = await fetch(API + path, {
		method,
		headers: { ...HEADERS, Authorization: AUTH },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	const text = await r.text()
	return { ok: r.ok, status: r.status, body: text ? JSON.parse(text) : null }
}

// Card time tracking (#3536): manual entries (seconds + optional note), a
// per-card total in the DETAIL payload (never the summaries), board-EDIT gated,
// IDOR-guarded delete, and purge-cascade cleanup.
test.describe('Card time tracking', () => {
	let boardId = 0
	let stackId = 0
	let cardId = 0
	let otherCardId = 0

	test.beforeAll(async () => {
		boardId = (await api('POST', '/boards', { title: 'Time Tracking E2E' })).body.id
		stackId = (await api('POST', '/stacks', { boardId, title: 'Tasks' })).body.id
		cardId = (await api('POST', '/cards', { stackId, title: 'Card with time' })).body.id
		otherCardId = (await api('POST', '/cards', { stackId, title: 'Other card' })).body.id
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
	})

	test('add entries, list them, and the per-card total updates', async () => {
		// Starts empty; the detail total is 0.
		let res = await api('GET', `/cards/${cardId}/time-entries`)
		expect(res.ok).toBe(true)
		expect(res.body).toEqual([])

		res = await api('GET', `/cards/${cardId}`)
		expect(res.body.timeSpent).toBe(0)

		// Add 1h 30m (5400s) with a note.
		const a = await api('POST', `/cards/${cardId}/time-entries`, { seconds: 5400, note: 'Pairing' })
		expect(a.ok).toBe(true)
		expect(a.body.seconds).toBe(5400)
		expect(a.body.note).toBe('Pairing')
		expect(a.body.createdBy).toBe(USER)

		// Add another 30m, no note.
		const b = await api('POST', `/cards/${cardId}/time-entries`, { seconds: 1800 })
		expect(b.ok).toBe(true)
		expect(b.body.note).toBeNull()

		// Both show up, newest first.
		res = await api('GET', `/cards/${cardId}/time-entries`)
		expect(res.body).toHaveLength(2)
		expect(res.body[0].id).toBe(b.body.id)

		// The card detail total is the SUM (5400 + 1800).
		res = await api('GET', `/cards/${cardId}`)
		expect(res.body.timeSpent).toBe(7200)

		// Delete one entry; the total drops.
		res = await api('DELETE', `/cards/${cardId}/time-entries/${a.body.id}`)
		expect(res.ok).toBe(true)
		res = await api('GET', `/cards/${cardId}`)
		expect(res.body.timeSpent).toBe(1800)

		// Clean up the remaining entry.
		await api('DELETE', `/cards/${cardId}/time-entries/${b.body.id}`)
		res = await api('GET', `/cards/${cardId}`)
		expect(res.body.timeSpent).toBe(0)
	})

	test('the per-card total is NOT in the board summaries (perf bet preserved)', async () => {
		await api('POST', `/cards/${cardId}/time-entries`, { seconds: 600 })
		const board = await api('GET', `/boards/${boardId}`)
		expect(board.ok).toBe(true)
		// Find the card in the board payload; the summary must not carry timeSpent.
		const json = JSON.stringify(board.body)
		expect(json).not.toContain('timeSpent')
		// Clean up.
		const entries = await api('GET', `/cards/${cardId}/time-entries`)
		for (const e of entries.body) {
			await api('DELETE', `/cards/${cardId}/time-entries/${e.id}`)
		}
	})

	test('a zero or negative duration is rejected', async () => {
		let res = await api('POST', `/cards/${cardId}/time-entries`, { seconds: 0 })
		expect(res.ok).toBe(false)
		expect(res.status).toBe(400)

		res = await api('POST', `/cards/${cardId}/time-entries`, { seconds: -60 })
		expect(res.ok).toBe(false)
		expect(res.status).toBe(400)
	})

	test('IDOR: a time entry of one card cannot be deleted via another card', async () => {
		const a = await api('POST', `/cards/${cardId}/time-entries`, { seconds: 120 })
		expect(a.ok).toBe(true)

		// Deleting via the OTHER card's URL must 404 (not a leak).
		const del = await api('DELETE', `/cards/${otherCardId}/time-entries/${a.body.id}`)
		expect(del.ok).toBe(false)
		expect(del.status).toBe(404)

		// It still exists on its real card.
		const res = await api('GET', `/cards/${cardId}/time-entries`)
		expect(res.body.some((e) => e.id === a.body.id)).toBe(true)

		await api('DELETE', `/cards/${cardId}/time-entries/${a.body.id}`)
	})

	test('purging a card removes its time entries', async () => {
		const doomed = (await api('POST', '/cards', { stackId, title: 'Doomed' })).body.id
		await api('POST', `/cards/${doomed}/time-entries`, { seconds: 900 })
		let res = await api('GET', `/cards/${doomed}/time-entries`)
		expect(res.body).toHaveLength(1)

		// Trash then purge (hard delete).
		await api('DELETE', `/cards/${doomed}`)
		const purge = await api('DELETE', `/cards/${doomed}/purge`)
		expect(purge.ok).toBe(true)

		// The card is gone; its entries were cascaded (no strays).
		res = await api('GET', `/cards/${doomed}/time-entries`)
		expect(res.ok).toBe(false)
	})

	test('add a time entry through the CardModal UI and see the total on reopen', async ({ page }) => {
		await ncLogin(page)
		const cardUrl = `${BASE}/index.php/apps/kanso#/board/${boardId}/card/${cardId}`
		await page.goto(cardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Fill the duration + note and submit the add-time form.
		await page.fill('.card-modal__time-duration', '1h 30m')
		await page.fill('.card-modal__time-note', 'UI logged')
		await page.locator('.card-modal__time-add button', { hasText: 'Add time' }).click()

		// The entry row appears with the formatted duration + note.
		const row = page.locator('.card-modal__link-row', { hasText: 'UI logged' })
		await expect(row).toHaveCount(1, { timeout: 8000 })
		await expect(row).toContainText('1h 30m')

		// Reopen the card fresh; the per-card total (from the detail payload) shows.
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${boardId}`)
		await page.waitForLoadState('networkidle', { timeout: 10_000 }).catch(() => {})
		await page.goto(cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })
		await expect(page.locator('.card-modal__time-entry-duration', { hasText: '1h 30m' }))
			.toHaveCount(1, { timeout: 8000 })

		// Clean up so the shared card resets.
		const entries = await api('GET', `/cards/${cardId}/time-entries`)
		for (const e of entries.body) {
			await api('DELETE', `/cards/${cardId}/time-entries/${e.id}`)
		}
	})
})
