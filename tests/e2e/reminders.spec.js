// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, adminAuth, BASE, API } from './helpers.js'
import { execSync } from 'node:child_process'

const NOTIF = BASE + '/ocs/v2.php/apps/notifications/api/v2/notifications'
const HEADERS = {
	'OCS-APIREQUEST': 'true',
	'Content-Type': 'application/json',
}
const OCS_HEADERS = { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }
const AUTH = adminAuth

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
	// --limit is high on purpose: background-job:list defaults to 500 rows, and on
	// an instance with many jobs our freshly-registered sweep (highest id) can fall
	// outside that window, making a find-by-class over the default page spuriously
	// "not registered".
	const list = execSync(
		'docker exec -u www-data kanso-dev php occ background-job:list --output=json --limit=100000',
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

test.describe('Personal reminders (remind me)', () => {
	const state = { boardId: 0, stackId: 0, cardId: 0, commentId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		for (const b of await api.get('/boards')) {
			if (b.title === 'Reminder E2E Board') await api.delete(`/boards/${b.id}`)
		}
		const board = await api.post('/boards', { title: 'Reminder E2E Board' })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id
		const card = await api.post('/cards', { stackId: stack.id, title: 'Card To Be Reminded Of' })
		state.cardId = card.id
		const comment = await api.post(`/cards/${card.id}/comments`, { body: 'A comment to remind about' })
		state.commentId = comment.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('schedule + list a card-level reminder via API', async () => {
		const at = Math.floor(Date.now() / 1000) + 3600
		const created = await api.post(`/cards/${state.cardId}/reminders`, { remindAt: at })
		expect(created.id).toBeGreaterThan(0)
		expect(created.remindAt).toBe(at)
		expect(created.commentId).toBeNull()
		expect(created.firedAt).toBeNull()

		const list = await api.get(`/cards/${state.cardId}/reminders`)
		expect(list.some((r) => r.id === created.id)).toBeTruthy()

		// Clean up: leave no lingering pending reminder on the shared card, or the
		// later UI test (which asserts the card has exactly zero chips after its own
		// add/cancel) sees this leftover and fails.
		await api.delete(`/cards/${state.cardId}/reminders/${created.id}`)
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
		const created = await api.post(`/cards/${state.cardId}/reminders`, { remindAt: at, commentId: state.commentId })
		expect(created.commentId).toBe(state.commentId)

		await api.delete(`/cards/${state.cardId}/reminders/${created.id}`)
		let list = await api.get(`/cards/${state.cardId}/reminders`)
		expect(list.some((r) => r.id === created.id)).toBeFalsy()

		// Cancelling again is a no-op (still succeeds) - idempotent.
		await api.delete(`/cards/${state.cardId}/reminders/${created.id}`)
		list = await api.get(`/cards/${state.cardId}/reminders`)
		expect(list.some((r) => r.id === created.id)).toBeFalsy()
	})

	test('firing delivers a Kanso notification once and is overdue-safe / idempotent', async () => {
		// Schedule a few seconds out — enough margin that the API's "must be in the
		// future" check still passes after client→server latency (a 1s margin flaked
		// on slow CI: the server saw the time as already past and 400'd) — then let it
		// lapse so the sweep treats it as overdue-owed.
		const at = Math.floor(Date.now() / 1000) + 5
		const created = await api.post(`/cards/${state.cardId}/reminders`, { remindAt: at })
		await new Promise((res) => setTimeout(res, 6500))

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
		const pending = await api.get(`/cards/${state.cardId}/reminders`)
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
