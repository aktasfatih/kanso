// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Two-browser realtime sync: admin mutates a shared board, tester must see
// the change WITHOUT any interaction — via notify_push when the dev stack
// has it configured (dev/setup.sh does), via the 5s delta poll otherwise.
// The fallback test toggles the notify_push app off through occ in the dev
// container, so this suite assumes the dev/ docker stack.

import { test, expect } from '@playwright/test'
import { execSync } from 'node:child_process'

const BASE = 'http://localhost:8891'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = {
	'OCS-APIREQUEST': 'true',
	'Content-Type': 'application/json',
}
const ADMIN_AUTH = 'Basic ' + Buffer.from('admin:admin').toString('base64')

const TESTER = { user: 'tester', pass: 'kanso-dev-tester!1' }

async function apiGet(path) {
	const r = await fetch(API + path, { headers: { ...HEADERS, Authorization: ADMIN_AUTH } })
	if (!r.ok) throw new Error(`GET ${path} → ${r.status}`)
	return r.json()
}

async function apiPost(path, body) {
	const r = await fetch(API + path, {
		method: 'POST',
		headers: { ...HEADERS, Authorization: ADMIN_AUTH },
		body: JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`POST ${path} → ${r.status}: ${await r.text()}`)
	return r.json()
}

async function apiDelete(path) {
	const r = await fetch(API + path, {
		method: 'DELETE',
		headers: { ...HEADERS, Authorization: ADMIN_AUTH },
	})
	if (!r.ok) throw new Error(`DELETE ${path} → ${r.status}`)
}

async function ncLogin(page, user, pass) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})

	const userInput = page.locator('#user')
	const isLoginPage = await userInput.isVisible({ timeout: 3000 }).catch(() => false)
	if (!isLoginPage) return // Already logged in

	await page.fill('#user', user)
	await page.fill('#password', pass)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

function occ(command) {
	execSync(`docker exec -u www-data kanso-dev php occ ${command}`, { stdio: 'pipe' })
}

test.describe('Realtime sync', () => {
	const state = { boardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'Realtime Test Board') {
				await apiDelete(`/boards/${b.id}`)
			}
		}
		const board = await apiPost('/boards', { title: 'Realtime Test Board' })
		state.boardId = board.id
		await apiPost('/stacks', { boardId: board.id, title: 'S1' })
		// Share with tester (READ|EDIT = 3)
		await apiPost(`/boards/${board.id}/acl`, {
			participant: TESTER.user,
			participantType: 'user',
			permission: 3,
		})
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(() => {
		// Whatever happened above, leave push enabled for the next suite
		try {
			occ('app:enable notify_push')
		} catch {
			// dev container not running — nothing to restore
		}
	})

	test('push: tester sees a new card near-instantly without interaction', async ({ browser }) => {
		const adminCtx = await browser.newContext()
		const testerCtx = await browser.newContext()
		try {
			const adminPage = await adminCtx.newPage()
			const testerPage = await testerCtx.newPage()
			await ncLogin(adminPage, 'admin', 'admin')
			await ncLogin(testerPage, TESTER.user, TESTER.pass)

			await adminPage.goto(state.boardUrl)
			await testerPage.goto(state.boardUrl)
			await expect(testerPage.locator('.stack-column').first()).toBeVisible({ timeout: 15_000 })

			// Admin creates a card; the mutation broadcasts kanso_board_changed
			await apiPost('/cards', { stackId: (await apiGet(`/boards/${state.boardId}`)).stacks[0].id, title: 'push-card' })

			// Push must beat the 60s safety-net poll by a wide margin
			await expect(
				testerPage.locator('.card-tile').filter({ hasText: 'push-card' }),
			).toBeVisible({ timeout: 4_000 })
		} finally {
			await adminCtx.close()
			await testerCtx.close()
		}
	})

	test('fallback: tester sees a new card via the 5s poll when push is off', async ({ browser }) => {
		occ('app:disable notify_push')
		// Give Nextcloud a moment to clear any capabilities cache so the tester
		// page loads with push marked as unavailable → 5s poll interval.
		await new Promise((r) => setTimeout(r, 1000))
		const testerCtx = await browser.newContext()
		try {
			const testerPage = await testerCtx.newPage()
			await ncLogin(testerPage, TESTER.user, TESTER.pass)

			// Fresh load AFTER disabling: no notify_push capability → the
			// client falls back to the 5s delta poll.
			await testerPage.goto(state.boardUrl)
			await expect(testerPage.locator('.stack-column').first()).toBeVisible({ timeout: 15_000 })

			await apiPost('/cards', { stackId: (await apiGet(`/boards/${state.boardId}`)).stacks[0].id, title: 'poll-card' })

			// 5s poll interval + 5s board-load time + latency headroom = 15s budget,
			// still far under the push-enabled 60s safety-net.
			await expect(
				testerPage.locator('.card-tile').filter({ hasText: 'poll-card' }),
			).toBeVisible({ timeout: 15_000 })
		} finally {
			occ('app:enable notify_push')
			await testerCtx.close()
		}
	})
})
