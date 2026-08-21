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

async function cardDates(boardId, cardId) {
	const board = await api('GET', `/boards/${boardId}`)
	const c = board.cards.find((x) => x.id === cardId)
	return { duedate: c?.duedate, startDate: c?.startDate }
}

// The local Y-M-D of a date, matching what the datetime-local input shows and
// what handleDueDateChange persists (`new Date(localValue).toISOString()`).
function localYmd(iso) {
	const d = new Date(iso)
	const pad = (n) => String(n).padStart(2, '0')
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

// #64 — typing a full date by keyboard into the card's Start/Due date fields used
// to be erratic: each segment "change" kicked off updateCard → refetch → the
// controlled :value re-applied mid-edit, resetting the caret and dropping digits.
// The buffered input must now let the user type an entire date, key-by-key, with a
// realistic per-key delay, without the async write-back clobbering the field.
test.describe('Typing a date by keyboard', () => {
	const state = { boardId: 0, cardId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Date Input E2E' })
		state.boardId = board.id
		const stack = await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api('POST', '/cards', { stackId: stack.id, title: 'Typed date card' })
		// Seed both dates so the segmented inputs start pre-populated (all segments
		// present) and the time-of-day segments don't interfere while we retype the
		// date. A timed (non-all-day) due date keeps the input a datetime-local.
		await api('PATCH', `/cards/${card.id}`, {
			duedate: '2026-01-02T09:30:00+00:00',
			startDate: '2026-01-02T09:30:00+00:00',
		})
		state.cardId = card.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('typing a due date key-by-key keeps every digit and commits the typed date', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.setViewportSize({ width: 1280, height: 800 })
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		await page.locator('.card-modal__attrbar button.card-modal__pill[data-pill="due"]').click()
		const dueInput = page.locator('.card-modal__popover .card-modal__date-input').first()
		await expect(dueInput).toHaveAttribute('type', 'datetime-local')

		// Focus the first (month) segment, then type the whole date one key at a
		// time with a real typing delay. This is what fires the per-segment change
		// events that used to trigger the mid-edit refetch clobber.
		await dueInput.click()
		await page.keyboard.press('Home')
		// MM DD YYYY → 03 / 14 / 2027
		await dueInput.pressSequentially('03142027', { delay: 120 })

		// The committed input value must contain the full date exactly as typed —
		// no dropped digits, no reset to the first segment.
		await expect(dueInput).toHaveValue(/^2027-03-14T/)

		// And the server must store that same calendar day (the change handler ran
		// on the intact value, not a half-typed one).
		await expect
			.poll(() => cardDates(state.boardId, state.cardId).then((d) => localYmd(d.duedate)), { timeout: 8_000 })
			.toBe('2027-03-14')
	})

	test('typing a start date key-by-key keeps every digit and commits the typed date', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.setViewportSize({ width: 1280, height: 800 })
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		await page.locator('.card-modal__attrbar button.card-modal__pill[data-pill="due"]').click()
		// Second date input in the popover is the start date (always datetime-local).
		const startInput = page.locator('.card-modal__popover .card-modal__date-input').nth(1)
		await expect(startInput).toHaveAttribute('type', 'datetime-local')

		await startInput.click()
		await page.keyboard.press('Home')
		// MM DD YYYY → 05 / 09 / 2027
		await startInput.pressSequentially('05092027', { delay: 120 })

		await expect(startInput).toHaveValue(/^2027-05-09T/)

		await expect
			.poll(() => cardDates(state.boardId, state.cardId).then((d) => localYmd(d.startDate)), { timeout: 8_000 })
			.toBe('2027-05-09')
	})
})
