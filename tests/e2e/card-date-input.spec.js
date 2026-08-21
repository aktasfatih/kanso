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
// to be erratic: the native segmented input commits on every `change` (fired
// per-segment as soon as one is edited), so `handleDueDateChange` ran mid-edit →
// updateCard → refetch → the controlled :value re-applied and reset the field.
// The fix keeps the native inputs but commits on BLUR / Enter only. So while the
// field is focused NO PATCH may fire; exactly one PATCH fires once the user leaves
// the field (or presses Enter), carrying the fully-typed value.
test.describe('Typing a date by keyboard', () => {
	const state = { boardId: 0, cardId: 0, cardUrl: '' }

	// Focus the all-day checkbox to move focus OFF the date input — a real blur
	// that fires the commit. (Pressing Tab only hops between the datetime-local's
	// own segments, so it does not blur the field.)
	const blur = (page) => page.locator('.card-modal__allday input[type=checkbox]').focus()

	// PATCH requests to this card, tagged so we can assert none fire mid-edit and
	// exactly one fires on commit.
	function trackPatches(page) {
		const patches = []
		page.on('request', (req) => {
			if (req.method() === 'PATCH' && new RegExp(`/cards/${state.cardId}(\\?|$)`).test(req.url())) {
				let body = null
				try { body = req.postDataJSON() } catch { /* non-JSON body */ }
				patches.push(body)
			}
		})
		return patches
	}

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Date Input E2E' })
		state.boardId = board.id
		const stack = await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api('POST', '/cards', { stackId: stack.id, title: 'Typed date card' })
		// Seed both dates so the segmented inputs start pre-populated (all segments
		// present). A timed (non-all-day) due date keeps the input a datetime-local.
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

	test('typing a due date fires no PATCH mid-edit and one PATCH on blur with the typed date', async ({ page }) => {
		const patches = trackPatches(page)
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.setViewportSize({ width: 1280, height: 800 })
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		await page.locator('.card-modal__attrbar button.card-modal__pill[data-pill="due"]').click()
		const dueInput = page.locator('.card-modal__popover .card-modal__date-input').first()
		await expect(dueInput).toHaveAttribute('type', 'datetime-local')

		// Seat the caret on the first (month) segment, then type the whole date one
		// key at a time with a real typing delay. This drives the per-segment change
		// events that used to trigger the mid-edit refetch clobber.
		await dueInput.click()
		for (let i = 0; i < 5; i++) await page.keyboard.press('ArrowLeft')
		await dueInput.pressSequentially('03142027', { delay: 120 })

		// The typed value must be intact — no dropped digits, no reset to segment 1 —
		// and, crucially, NO PATCH may have fired while the field is still focused.
		await expect(dueInput).toHaveValue(/^2027-03-14T/)
		await page.waitForTimeout(400)
		expect(patches, 'no PATCH may fire mid-edit').toHaveLength(0)

		// Leaving the field commits exactly once, with the fully-typed date.
		await blur(page)
		await expect
			.poll(() => cardDates(state.boardId, state.cardId).then((d) => localYmd(d.duedate)), { timeout: 8_000 })
			.toBe('2027-03-14')
		expect(patches, 'exactly one PATCH on blur').toHaveLength(1)
		expect(patches[0]).toHaveProperty('duedate')
	})

	test('pressing Enter commits the typed due date', async ({ page }) => {
		const patches = trackPatches(page)
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.setViewportSize({ width: 1280, height: 800 })
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		await page.locator('.card-modal__attrbar button.card-modal__pill[data-pill="due"]').click()
		const dueInput = page.locator('.card-modal__popover .card-modal__date-input').first()
		await dueInput.click()
		for (let i = 0; i < 5; i++) await page.keyboard.press('ArrowLeft')
		await dueInput.pressSequentially('09102027', { delay: 120 })
		await expect(dueInput).toHaveValue(/^2027-09-10T/)
		await page.waitForTimeout(400)
		expect(patches, 'no PATCH before Enter').toHaveLength(0)

		await dueInput.press('Enter')
		await expect
			.poll(() => cardDates(state.boardId, state.cardId).then((d) => localYmd(d.duedate)), { timeout: 8_000 })
			.toBe('2027-09-10')
		expect(patches.length, 'Enter commits at least once').toBeGreaterThanOrEqual(1)
	})

	test('typing a start date fires no PATCH mid-edit and one PATCH on blur with the typed date', async ({ page }) => {
		const patches = trackPatches(page)
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.setViewportSize({ width: 1280, height: 800 })
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		await page.locator('.card-modal__attrbar button.card-modal__pill[data-pill="due"]').click()
		// Second date input in the popover is the start date (always datetime-local).
		const startInput = page.locator('.card-modal__popover .card-modal__date-input').nth(1)
		await expect(startInput).toHaveAttribute('type', 'datetime-local')

		await startInput.click()
		for (let i = 0; i < 5; i++) await page.keyboard.press('ArrowLeft')
		await startInput.pressSequentially('05092027', { delay: 120 })
		await expect(startInput).toHaveValue(/^2027-05-09T/)
		await page.waitForTimeout(400)
		expect(patches, 'no PATCH may fire mid-edit').toHaveLength(0)

		await blur(page)
		await expect
			.poll(() => cardDates(state.boardId, state.cardId).then((d) => localYmd(d.startDate)), { timeout: 8_000 })
			.toBe('2027-05-09')
		expect(patches, 'exactly one PATCH on blur').toHaveLength(1)
		expect(patches[0]).toHaveProperty('startDate')
	})

	test('leaving a date field unedited fires no redundant PATCH', async ({ page }) => {
		const patches = trackPatches(page)
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.setViewportSize({ width: 1280, height: 800 })
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		await page.locator('.card-modal__attrbar button.card-modal__pill[data-pill="due"]').click()
		const dueInput = page.locator('.card-modal__popover .card-modal__date-input').first()
		await dueInput.click()
		await blur(page)
		await page.waitForTimeout(600)
		expect(patches, 'no PATCH when the field is left untouched').toHaveLength(0)
	})
})
