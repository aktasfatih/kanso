// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'
import { execSync } from 'node:child_process'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const NOTIF = BASE + '/ocs/v2.php/apps/notifications/api/v2/notifications'
const HEADERS = {
	'OCS-APIREQUEST': 'true',
	'Content-Type': 'application/json',
}
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
	const r = await fetch(API + path, {
		method: 'DELETE',
		headers: { ...HEADERS, Authorization: AUTH },
	})
	if (!r.ok) throw new Error(`DELETE ${path} → ${r.status}`)
	return r
}

async function kansoNotifications() {
	const r = await fetch(NOTIF, { headers: { ...OCS_HEADERS, Authorization: AUTH } })
	if (!r.ok) return []
	const body = await r.json()
	const data = body?.ocs?.data ?? []
	return data.filter((n) => n.app === 'kanso')
}

// Trigger the personal-reminder cron directly (the "fire the job" leg). Mirrors
// how the CI e2e job shells into the app container via `docker exec`. Resolves
// the job id by class and executes it once so it fires regardless of the
// 15-minute schedule.
function firePersonalReminders() {
	const cls = 'OCA\\Kanso\\Cron\\SendPersonalReminders'
	const list = execSync(
		'docker exec -u www-data kanso-dev php occ background-job:list --output=json',
		{ encoding: 'utf8' },
	)
	const jobs = JSON.parse(list)
	const job = jobs.find((j) => j.class === cls)
	if (!job) throw new Error('SendPersonalReminders job not registered')
	execSync(
		`docker exec -u www-data kanso-dev php occ background-job:execute ${job.id} --force-execute`,
		{ encoding: 'utf8', stdio: 'pipe' },
	)
}

async function ncLogin(page) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	const userInput = page.locator('#user')
	if (!(await userInput.isVisible({ timeout: 3000 }).catch(() => false))) return
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

test.describe('Personal reminders (remind me)', () => {
	const state = { boardId: 0, stackId: 0, cardId: 0, commentId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		for (const b of await apiGet('/boards')) {
			if (b.title === 'Reminder E2E Board') await apiDelete(`/boards/${b.id}`)
		}
		const board = await apiPost('/boards', { title: 'Reminder E2E Board' })
		state.boardId = board.id
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id
		const card = await apiPost('/cards', { stackId: stack.id, title: 'Card To Be Reminded Of' })
		state.cardId = card.id
		const comment = await apiPost(`/cards/${card.id}/comments`, { body: 'A comment to remind about' })
		state.commentId = comment.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await apiDelete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('schedule + list a card-level reminder via API', async () => {
		const at = Math.floor(Date.now() / 1000) + 3600
		const created = await apiPost(`/cards/${state.cardId}/reminders`, { remindAt: at })
		expect(created.id).toBeGreaterThan(0)
		expect(created.remindAt).toBe(at)
		expect(created.commentId).toBeNull()
		expect(created.firedAt).toBeNull()

		const list = await apiGet(`/cards/${state.cardId}/reminders`)
		expect(list.some((r) => r.id === created.id)).toBeTruthy()
	})

	test('a past reminder time is rejected (400)', async () => {
		const r = await fetch(`${API}/cards/${state.cardId}/reminders`, {
			method: 'POST',
			headers: { ...HEADERS, Authorization: AUTH },
			body: JSON.stringify({ remindAt: Math.floor(Date.now() / 1000) - 60 }),
		})
		expect(r.status).toBe(400)
	})

	test('schedule + cancel a comment-scoped reminder; cancel is idempotent', async () => {
		const at = Math.floor(Date.now() / 1000) + 7200
		const created = await apiPost(`/cards/${state.cardId}/reminders`, { remindAt: at, commentId: state.commentId })
		expect(created.commentId).toBe(state.commentId)

		await apiDelete(`/cards/${state.cardId}/reminders/${created.id}`)
		let list = await apiGet(`/cards/${state.cardId}/reminders`)
		expect(list.some((r) => r.id === created.id)).toBeFalsy()

		// Cancelling again is a no-op (still succeeds) - idempotent.
		await apiDelete(`/cards/${state.cardId}/reminders/${created.id}`)
		list = await apiGet(`/cards/${state.cardId}/reminders`)
		expect(list.some((r) => r.id === created.id)).toBeFalsy()
	})

	test('firing delivers a Kanso notification once and is overdue-safe / idempotent', async () => {
		// Schedule for ~1s out (the API requires a future time), then let it lapse
		// so the sweep treats it as overdue-owed.
		const at = Math.floor(Date.now() / 1000) + 1
		const created = await apiPost(`/cards/${state.cardId}/reminders`, { remindAt: at })
		await new Promise((res) => setTimeout(res, 2500))

		const before = (await kansoNotifications()).length
		firePersonalReminders()
		await new Promise((res) => setTimeout(res, 1500))

		const afterFirst = await kansoNotifications()
		expect(afterFirst.length).toBeGreaterThan(before)
		const mine = afterFirst.find((n) => Number(n.object_id) === state.cardId && /Reminder/i.test(n.subject))
		expect(mine).toBeTruthy()
		// Deep link points at the card.
		expect(String(mine.link)).toContain(`/card/${state.cardId}`)

		// The reminder is stamped fired - it drops out of the pending list.
		const pending = await apiGet(`/cards/${state.cardId}/reminders`)
		expect(pending.some((r) => r.id === created.id)).toBeFalsy()

		// Firing again does not re-deliver (idempotent: fired_at consumed it).
		firePersonalReminders()
		await new Promise((res) => setTimeout(res, 1000))
		const afterSecond = await kansoNotifications()
		const countFor = (arr) => arr.filter((n) => Number(n.object_id) === state.cardId && /Reminder/i.test(n.subject)).length
		expect(countFor(afterSecond)).toBe(countFor(afterFirst))
	})

	test('UI: card menu "Remind me later today" sets a chip; cancel removes it', async ({ page }) => {
		const errors = []
		page.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()) })

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Open the card overflow menu and pick the preset.
		await page.locator('.card-modal__actions-menu button').first().click()
		await page.getByText('Remind me later today').click()

		// A reminder chip appears in the header region.
		const chip = page.locator('.card-modal__reminder-chip')
		await expect(chip.first()).toBeVisible({ timeout: 6000 })

		// Cancel it via the chip's × button.
		await chip.first().locator('.card-modal__reminder-cancel').click()
		await expect(page.locator('.card-modal__reminder-chip')).toHaveCount(0, { timeout: 6000 })

		// No new console errors from the reminder flow.
		expect(errors.filter((e) => !/favicon|manifest/i.test(e))).toEqual([])
	})
})
