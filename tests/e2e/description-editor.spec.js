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

// #3470 — markdown formatting toolbar + live preview over the description.
test.describe('Description formatting toolbar', () => {
	const state = { boardId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Desc-Editor E2E' })
		state.boardId = board.id
		const stack = await api('POST', '/stacks', { boardId: board.id, title: 'Do' })
		const card = await api('POST', '/cards', { stackId: stack.id, title: 'Editable card' })
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('toolbar wraps the selection in markdown and the preview renders it', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Enter description edit via the empty-state placeholder.
		await page.locator('.card-modal__desc-placeholder').click()
		await expect(page.locator('.card-modal__md-toolbar')).toBeVisible({ timeout: 4000 })

		const textarea = page.locator('.card-modal__desc-textarea')
		await textarea.fill('hello world')
		await textarea.selectText() // select all → Bold wraps the whole selection

		await page.locator('.card-modal__md-toolbar [title="Bold"]').click()
		await expect(textarea).toHaveValue('**hello world**')

		// Live preview renders the markdown as real <strong> (same path as read view).
		await page.locator('.card-modal__md-toolbar [title="Toggle preview"]').click()
		await expect(page.locator('.card-modal__desc-preview .card-modal__desc-rendered strong')).toHaveText('hello world')

		// Save → the stored markdown re-renders in the read view (same render path,
		// proving the stored value is markdown, not a rich-HTML blob).
		await page.locator('.card-modal__desc-actions button', { hasText: 'Save' }).click()
		await expect(page.locator('.card-modal__desc-rendered strong')).toHaveText('hello world', { timeout: 8000 })
	})

	test('Escape closes only the @mention dropdown, not the whole modal (keeps the edit)', async ({ page }) => {
		// Dedicated card so this test is independent of the others' saved state.
		const stack = await api('POST', '/stacks', { boardId: state.boardId, title: 'Esc' })
		const card = await api('POST', '/cards', { stackId: stack.id, title: 'Escape card' })
		const cardUrl = `${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${card.id}`

		await ncLogin(page)
		await page.goto(cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		await page.locator('.card-modal__desc-placeholder').click()
		const textarea = page.locator('.card-modal__desc-textarea')
		await textarea.click()
		await textarea.fill('draft text ')
		await textarea.press('@') // opens the mention dropdown (admin is a participant)
		await expect(page.locator('.card-modal__mention-dropdown')).toBeVisible({ timeout: 4000 })

		// Escape must dismiss ONLY the dropdown — the modal stays open, draft intact.
		await textarea.press('Escape')
		await expect(page.locator('.card-modal__mention-dropdown')).toBeHidden()
		await expect(page.locator('.card-modal')).toBeVisible()
		await expect(textarea).toHaveValue('draft text @')

		// A second Escape (dropdown now closed) cancels the edit without closing the modal.
		await textarea.press('Escape')
		await expect(page.locator('.card-modal__desc-textarea')).toBeHidden()
		await expect(page.locator('.card-modal')).toBeVisible()
	})

	test('list button prefixes each selected line', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Prior test saved a description → edit via the pencil/desc view.
		await page.locator('.card-modal__desc-view').click()
		await expect(page.locator('.card-modal__md-toolbar')).toBeVisible({ timeout: 4000 })

		const textarea = page.locator('.card-modal__desc-textarea')
		await textarea.fill('one\ntwo')
		await textarea.selectText()
		await page.locator('.card-modal__md-toolbar [title="Bulleted list"]').click()
		await expect(textarea).toHaveValue('- one\n- two')
	})
})
