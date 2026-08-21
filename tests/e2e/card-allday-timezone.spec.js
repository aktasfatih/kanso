// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')

// Run the whole file in a west-of-UTC zone. That is the exact condition the bug
// needed: an all-day date stored at UTC midnight (2026-07-22T00:00:00Z) rendered
// with the viewer's local getters lands on the PREVIOUS calendar day in
// America/New_York (UTC-4/-5), so "the 22nd" used to display as "the 21st".
test.use({ timezoneId: 'America/New_York' })

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

// NC serves the app JS with immutable caching; force a fresh fetch of the just-
// built bundle so the test exercises the current code, not a cached copy.
async function clearJsCache(page) {
	const client = await page.context().newCDPSession(page)
	await client.send('Network.clearBrowserCache').catch(() => {})
}

async function cardDates(boardId, cardId) {
	const board = await api('GET', `/boards/${boardId}`)
	const c = board.cards.find((x) => x.id === cardId)
	return { duedate: c?.duedate, startDate: c?.startDate, allDay: c?.allDay }
}

// The card fix: an all-day due/start date is STORED at UTC midnight, and the
// read-back (date input value + pill label) must be formatted in UTC so it shows
// the same calendar day everywhere, regardless of the viewer's timezone.
test.describe('All-day dates in a non-UTC timezone', () => {
	const state = { boardId: 0, cardId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'All-Day TZ E2E' })
		state.boardId = board.id
		const stack = await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api('POST', '/cards', { stackId: stack.id, title: 'TZ card' })
		state.cardId = card.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('an all-day due date shows the picked day (not the previous one) after reload', async ({ page }) => {
		// Seed an all-day due date at UTC midnight for the 22nd — exactly what the
		// modal writes: new Date("2026-07-22").toISOString() + allDay: true.
		await api('PATCH', `/cards/${state.cardId}`, {
			duedate: '2026-07-22T00:00:00.000Z',
			allDay: true,
		})

		await ncLogin(page)
		await clearJsCache(page)
		await page.goto(state.cardUrl)
		await page.setViewportSize({ width: 1280, height: 800 })
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		// The due pill (collapsed attribute bar) must read "22", not "21".
		const duePill = page.locator('.card-modal__attrbar button.card-modal__pill[data-pill="due"]')
		await expect(duePill).toContainText('22')
		await expect(duePill).not.toContainText('21')

		// Open the popover: the date input must be the plain date picker holding the
		// exact stored day.
		await duePill.click()
		const dueInput = page.locator('.card-modal__popover .card-modal__date-input').first()
		await expect(dueInput).toHaveAttribute('type', 'date')
		await expect(dueInput).toHaveValue('2026-07-22')

		// Storage is unchanged — still UTC midnight of the 22nd.
		const d = await cardDates(state.boardId, state.cardId)
		expect(d.allDay).toBe(true)
		expect(new Date(d.duedate).toISOString()).toBe('2026-07-22T00:00:00.000Z')
	})

	test('an all-day start date shows the picked day (not the previous one) after reload', async ({ page }) => {
		await api('PATCH', `/cards/${state.cardId}`, {
			duedate: '2026-07-22T00:00:00.000Z',
			startDate: '2026-07-20T00:00:00.000Z',
			allDay: true,
		})

		await ncLogin(page)
		await clearJsCache(page)
		await page.goto(state.cardUrl)
		await page.setViewportSize({ width: 1280, height: 800 })
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		await page.locator('.card-modal__attrbar button.card-modal__pill[data-pill="due"]').click()
		// Second date input in the popover is the start date.
		const startInput = page.locator('.card-modal__popover .card-modal__date-input').nth(1)
		// An all-day card reads its start back in UTC → the 20th, not the 19th.
		await expect(startInput).toHaveValue(/^2026-07-20T/)
	})

	test('a TIMED due date still round-trips in local time (no regression)', async ({ page }) => {
		// A non-all-day card keeps local formatting. 2026-07-22T02:00:00Z is
		// 2026-07-21 22:00 in America/New_York, so the input must show the 21st —
		// the correct LOCAL day for a real instant.
		await api('PATCH', `/cards/${state.cardId}`, {
			duedate: '2026-07-22T02:00:00.000Z',
			startDate: '',
			allDay: false,
		})

		await ncLogin(page)
		await clearJsCache(page)
		await page.goto(state.cardUrl)
		await page.setViewportSize({ width: 1280, height: 800 })
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		await page.locator('.card-modal__attrbar button.card-modal__pill[data-pill="due"]').click()
		const dueInput = page.locator('.card-modal__popover .card-modal__date-input').first()
		await expect(dueInput).toHaveAttribute('type', 'datetime-local')
		// 22:00 local on the 21st — local time-of-day preserved for timed dates.
		await expect(dueInput).toHaveValue('2026-07-21T22:00')
	})
})
