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
	// Session is preloaded via storageState (see playwright.config.js) for the
	// default admin context — skip the UI login round-trip entirely. Specs that
	// opt out of storageState start with no cookies and fall through to log in.
	if ((await page.context().cookies()).some((c) => c.name === 'nc_username' || c.name === 'nc_session_id')) return
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
		const sectionTitles = page.locator('.project-view__section .project-view__section-title')
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

	test('renders the project description as markdown and round-trips the editor', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects/${state.projectId}`)
		await expect(page.locator('.project-view')).toBeVisible({ timeout: 10_000 })

		// Open the edit dialog (Edit project lives in the header NcActions menu).
		const openEdit = async () => {
			await page.locator('.project-view__header-actions .action-item__menutoggle').last().click()
			await page.getByRole('menuitem', { name: /Edit project/ }).click()
		}
		await openEdit()
		const dialog = page.locator('.project-view__form')
		await expect(dialog).toBeVisible({ timeout: 8_000 })

		const md = '**bold text**\n\n- first item\n- second item'
		const textarea = page.locator('#edit-project-desc')
		await expect(textarea).toBeVisible()
		await textarea.fill(md)

		// The in-dialog preview renders the markdown as HTML (strong + li).
		await dialog.locator('.project-view__md-btn[title="Toggle preview"]').click()
		const preview = dialog.locator('.project-view__desc-preview .project-view__desc-rendered')
		await expect(preview.locator('strong')).toHaveText('bold text')
		await expect(preview.locator('li')).toHaveCount(2)

		await page.getByRole('button', { name: /^Save$/ }).click()
		await expect(dialog).toBeHidden({ timeout: 8_000 })

		// The prominent under-title description renders HTML markdown, not raw source.
		const headerDesc = page.locator('.project-view__desc-view .project-view__desc-rendered')
		await expect(headerDesc.locator('strong')).toHaveText('bold text')
		await expect(headerDesc.locator('li')).toHaveCount(2)
		// The raw markdown asterisks must NOT be present as literal text.
		await expect(headerDesc).not.toContainText('**bold text**')

		// Round-trip: server persisted the raw markdown (client renders it).
		const projects = await api('GET', '/projects')
		const saved = projects.find((p) => p.id === state.projectId)
		expect(saved.description).toBe(md)

		// Reopening the editor shows the raw markdown source again (not HTML).
		await openEdit()
		await expect(page.locator('#edit-project-desc')).toHaveValue(md, { timeout: 8_000 })
	})

	test('edits the description in place under the title and persists across reload', async ({ page }) => {
		// Reset to a known starting description so this test is order-independent.
		await api('PATCH', `/projects/${state.projectId}`, { description: 'Starting note' })

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects/${state.projectId}`)
		await expect(page.locator('.project-view')).toBeVisible({ timeout: 10_000 })

		// The description is prominent under the title (its own region, rendered md).
		const descView = page.locator('.project-view__desc-view')
		await expect(descView).toBeVisible({ timeout: 8_000 })
		await expect(descView).toContainText('Starting note')

		// Click it to edit in place — no dialog: the inline toolbar + textarea appear.
		await descView.click()
		const textarea = page.locator('.project-view__desc-textarea')
		await expect(textarea).toBeVisible({ timeout: 5_000 })
		await expect(page.locator('.project-view__description .project-view__md-toolbar')).toBeVisible()

		const md = '## Overview\n\nA **detailed** project note with:\n\n- point one\n- point two'
		await textarea.fill(md)

		// Save via the inline Save button.
		await page.getByRole('button', { name: /^Save$/ }).click()

		// Back to read mode: rendered markdown, not raw source.
		await expect(page.locator('.project-view__desc-textarea')).toBeHidden({ timeout: 8_000 })
		const rendered = page.locator('.project-view__desc-view .project-view__desc-rendered')
		await expect(rendered.locator('h2')).toHaveText('Overview')
		await expect(rendered.locator('strong')).toHaveText('detailed')
		await expect(rendered.locator('li')).toHaveCount(2)
		await expect(rendered).not.toContainText('**detailed**')

		// Server persisted the raw markdown.
		const projects = await api('GET', '/projects')
		expect(projects.find((p) => p.id === state.projectId).description).toBe(md)

		// Persists across a full reload (database-first, not just optimistic UI).
		await page.reload()
		await expect(page.locator('.project-view')).toBeVisible({ timeout: 10_000 })
		const renderedAfter = page.locator('.project-view__desc-view .project-view__desc-rendered')
		await expect(renderedAfter.locator('h2')).toHaveText('Overview', { timeout: 8_000 })
		await expect(renderedAfter.locator('li')).toHaveCount(2)

		// Escape cancels an in-place edit without persisting.
		await page.locator('.project-view__desc-view').click()
		const ta2 = page.locator('.project-view__desc-textarea')
		await expect(ta2).toBeVisible({ timeout: 5_000 })
		await ta2.fill('discarded edit')
		await ta2.press('Escape')
		await expect(page.locator('.project-view__desc-textarea')).toBeHidden({ timeout: 5_000 })
		await expect(page.locator('.project-view__desc-view')).toContainText('Overview')
		const afterCancel = await api('GET', '/projects')
		expect(afterCancel.find((p) => p.id === state.projectId).description).toBe(md)
	})

	test('shows an add-a-description affordance when empty and saves inline', async ({ page }) => {
		await api('PATCH', `/projects/${state.projectId}`, { description: '' })

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects/${state.projectId}`)
		await expect(page.locator('.project-view')).toBeVisible({ timeout: 10_000 })

		// Empty state: a subtle "Add a description…" affordance under the title.
		const placeholder = page.locator('.project-view__desc-placeholder')
		await expect(placeholder).toBeVisible({ timeout: 8_000 })
		await placeholder.click()

		const textarea = page.locator('.project-view__desc-textarea')
		await expect(textarea).toBeVisible({ timeout: 5_000 })
		await textarea.fill('First description added inline')
		await page.getByRole('button', { name: /^Save$/ }).click()

		await expect(page.locator('.project-view__desc-view')).toContainText('First description added inline', { timeout: 8_000 })
		const projects = await api('GET', '/projects')
		expect(projects.find((p) => p.id === state.projectId).description).toBe('First description added inline')
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
		// The picker was extracted into the shared CardSearchPicker component (#3645).
		await page.locator('.card-search-picker__input').fill(uniqueTitle)

		const result = page.locator('.card-search-picker__item', { hasText: uniqueTitle }).first()
		await expect(result).toBeVisible({ timeout: 8_000 })
		await result.click()

		// The picked card now shows in the project feed…
		await expect(page.locator('.project-view__row', { hasText: uniqueTitle })).toBeVisible({ timeout: 8_000 })
		// …and the server recorded the membership.
		const cards = await api('GET', `/projects/${state.projectId}/cards`)
		expect(cards.some((c) => c.title === uniqueTitle)).toBe(true)
	})
})
