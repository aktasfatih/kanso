// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Projects e2e (#3447): admin creates two boards, one card on each, a project,
// and adds both cards to it via the API. The project page (#/projects/:id) must
// show both cards grouped under their board headers. Also exercises the Projects
// list page and a UI remove.

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from(`${USER}:${PASS}`).toString('base64')

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

test.describe('Projects — cross-board card collections', () => {
	const state = { boardA: 0, boardB: 0, projectId: 0, cardA: 0, cardB: 0 }

	test.beforeAll(async () => {
		const ts = Date.now()
		const boardA = await api('POST', '/boards', { title: `Proj Board A ${ts}` })
		const boardB = await api('POST', '/boards', { title: `Proj Board B ${ts}` })
		state.boardA = boardA.id
		state.boardB = boardB.id
		const stackA = await api('POST', '/stacks', { boardId: boardA.id, title: 'To Do' })
		const stackB = await api('POST', '/stacks', { boardId: boardB.id, title: 'Doing' })
		const cardA = await api('POST', '/cards', { stackId: stackA.id, title: 'Alpha cross-board task' })
		const cardB = await api('POST', '/cards', { stackId: stackB.id, title: 'Beta cross-board task' })
		state.cardA = cardA.id
		state.cardB = cardB.id

		const project = await api('POST', '/projects', { title: `Q3 Initiative ${ts}` })
		state.projectId = project.id
		await api('PUT', `/projects/${project.id}/cards/${cardA.id}`)
		await api('PUT', `/projects/${project.id}/cards/${cardB.id}`)
	})

	test.afterAll(async () => {
		if (state.projectId) await api('DELETE', `/projects/${state.projectId}`).catch(() => {})
		if (state.boardA) await api('DELETE', `/boards/${state.boardA}`).catch(() => {})
		if (state.boardB) await api('DELETE', `/boards/${state.boardB}`).catch(() => {})
	})

	test('the project page groups member cards from two boards by board', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects/${state.projectId}`)

		const view = page.locator('.project-view')
		await expect(view).toBeVisible({ timeout: 10_000 })

		// Both cards, from two different boards, are listed.
		await expect(view).toContainText('Alpha cross-board task')
		await expect(view).toContainText('Beta cross-board task')

		// Grouped by board: both board headers render as section titles.
		const sectionTitles = page.locator('.project-view__section-title')
		await expect(sectionTitles).toHaveCount(2)

		// Two card rows total.
		await expect(page.locator('.project-view__row')).toHaveCount(2)
	})

	test('the Projects list page shows the project and links to it', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects`)

		const list = page.locator('.projects-view__list')
		await expect(list).toBeVisible({ timeout: 10_000 })
		const row = page.locator('.projects-view__row', { hasText: 'Q3 Initiative' }).first()
		await expect(row).toBeVisible()

		await row.click()
		await expect(page.locator('.project-view')).toBeVisible({ timeout: 8_000 })
		await expect(page).toHaveURL(new RegExp(`#/projects/${state.projectId}`))
	})

	test('removing a card from the project via the UI drops it from the feed', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects/${state.projectId}`)
		await expect(page.locator('.project-view__row')).toHaveCount(2, { timeout: 10_000 })

		// A single-action NcActions with force-menu=false renders the action as an
		// inline icon button labelled by its text — click it directly (fall back to
		// the menu toggle if this NC version collapses it into a menu).
		const firstRow = page.locator('.project-view__row').first()
		await firstRow.hover()
		const inlineRemove = firstRow.getByRole('button', { name: /Remove from project/ })
		if (await inlineRemove.isVisible({ timeout: 2000 }).catch(() => false)) {
			await inlineRemove.click()
		} else {
			await firstRow.locator('.action-item__menutoggle').first().click()
			await page.getByRole('menuitem', { name: /Remove from project/ }).click()
		}

		await expect(page.locator('.project-view__row')).toHaveCount(1, { timeout: 8_000 })

		// Server agrees the membership is gone.
		const remaining = await api('GET', `/projects/${state.projectId}/cards`)
		expect(remaining.length).toBe(1)
	})

	test('adds a card via the cross-board search picker', async ({ page }) => {
		// A fresh, distinctively-named card on board A to find through the picker.
		const ts = Date.now()
		const stack = await api('POST', '/stacks', { boardId: state.boardA, title: 'Picker' })
		const uniqueTitle = `Zeta pickable ${ts}`
		await api('POST', '/cards', { stackId: stack.id, title: uniqueTitle })

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects/${state.projectId}`)
		await expect(page.locator('.project-view')).toBeVisible({ timeout: 10_000 })

		await page.locator('.project-view__add-btn').click()
		await page.locator('.project-view__picker-input').fill(uniqueTitle)

		const result = page.locator('.project-view__picker-item', { hasText: uniqueTitle }).first()
		await expect(result).toBeVisible({ timeout: 8_000 })
		await result.click()

		// The picked card now shows in the project feed…
		await expect(page.locator('.project-view__row', { hasText: uniqueTitle })).toBeVisible({ timeout: 8_000 })
		// …and the server recorded the membership.
		const cards = await api('GET', `/projects/${state.projectId}/cards`)
		expect(cards.some((c) => c.title === uniqueTitle)).toBe(true)
	})
})
