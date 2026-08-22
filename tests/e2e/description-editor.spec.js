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

// #3470 — WYSIWYG markdown formatting toolbar over the description.
// The description editor is now an inline Tiptap (ProseMirror) WYSIWYG editor
// (MarkdownEditor.vue). There is no separate "Preview" toggle anymore — markdown
// renders live as you type. The formatting toolbar is .kanso-md-editor__toolbar.
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

	test('toolbar Bold wraps the selection and the WYSIWYG editor shows <strong>', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Enter description edit via the empty-state placeholder.
		await page.locator('.card-modal__desc-placeholder').click()

		// Wait for the Tiptap WYSIWYG editor to appear.
		const editor = page.locator('.card-modal__section .kanso-md-editor')
		await expect(editor).toBeVisible({ timeout: 6000 })
		const prose = editor.locator('.ProseMirror')
		await expect(prose).toBeVisible({ timeout: 4000 })

		// Type text into the ProseMirror contenteditable using keyboard input, so
		// Tiptap's ProseMirror document model receives and processes the keystrokes
		// correctly (prose.fill() bypasses the DOM input events that ProseMirror uses
		// to track changes, causing the v-model to diverge from the visible content).
		await prose.click()
		await page.keyboard.type('hello world')

		// Select all the text, then click Bold in the toolbar.
		await page.keyboard.press('Control+A')
		const boldBtn = editor.locator('.kanso-md-editor__tb-btn[title="Bold"]')
		await expect(boldBtn).toBeVisible({ timeout: 4000 })
		await boldBtn.click()

		// The ProseMirror node should now contain a <strong> element — the live
		// WYSIWYG renders markdown richly inline (no Preview toggle needed).
		await expect(prose.locator('strong')).toBeVisible({ timeout: 4000 })
		await expect(prose.locator('strong')).toHaveText('hello world')

		// Save → the stored markdown re-renders in the read view (same render path,
		// proving the stored value is markdown, not a rich-HTML blob).
		// Also verify the API persisted it (the desc-view shows after save, not the placeholder).
		await page.locator('.card-modal__desc-actions button', { hasText: 'Save' }).click()
		await expect(page.locator('.card-modal__desc-rendered')).toBeVisible({ timeout: 8000 })
		await expect(page.locator('.card-modal__desc-rendered strong')).toHaveText('hello world', { timeout: 8000 })
		// Confirm the desc-view is shown (placeholder hidden) — means save persisted.
		await expect(page.locator('.card-modal__desc-view')).toBeVisible({ timeout: 5000 })
	})

	test('Escape closes only the @mention dropdown, not the whole modal (keeps the edit)', async ({ page }) => {
		// Dedicated card so this test is independent of the others' saved state.
		const stack = await api('POST', '/stacks', { boardId: state.boardId, title: 'Esc' })
		const card = await api('POST', '/cards', { stackId: stack.id, title: 'Escape card' })
		const cardUrl = `${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${card.id}`

		await ncLogin(page)
		await page.goto(cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })
		// Also wait for the card to be fully loaded (not in skeleton state)
		await page.waitForSelector('.card-modal__desc-placeholder, .card-modal__desc-view', { timeout: 10_000 })

		await page.locator('.card-modal__desc-placeholder').click()
		const editor = page.locator('.card-modal__section .kanso-md-editor')
		await expect(editor).toBeVisible({ timeout: 6000 })
		const prose = editor.locator('.ProseMirror')
		await expect(prose).toBeVisible({ timeout: 4000 })
		await prose.click()
		await page.keyboard.type('draft text ')

		// Type '@' to trigger the mention dropdown. The suggestion plugin renders the
		// dropdown only when there are matching participants; admin IS a board member.
		// We retry typing '@' a few times to handle the case where the async
		// participants query hasn't resolved yet on the first keystroke.
		const dropdown = page.locator('.kanso-md-editor__mention-dropdown')
		let dropdownVisible = false
		for (let attempt = 0; attempt < 5 && !dropdownVisible; attempt++) {
			if (attempt > 0) {
				// Remove the previous '@' and wait for participants to load
				await page.keyboard.press('Backspace')
				await page.waitForTimeout(400)
			}
			await prose.press('@')
			dropdownVisible = await dropdown.isVisible({ timeout: 1200 }).catch(() => false)
		}
		await expect(dropdown).toBeVisible({ timeout: 4000 })

		// Escape must dismiss ONLY the dropdown — the modal stays open, draft intact.
		await prose.press('Escape')
		await expect(dropdown).toBeHidden({ timeout: 2000 })
		await expect(page.locator('.card-modal')).toBeVisible()

		// The description editor is still open (desc-actions with Save/Cancel visible).
		await expect(page.locator('.card-modal__desc-actions button', { hasText: 'Save' })).toBeVisible()

		// A second Escape (dropdown now closed) cancels the edit without closing the modal.
		await prose.press('Escape')
		await expect(editor).toBeHidden({ timeout: 2000 })
		await expect(page.locator('.card-modal')).toBeVisible()
	})

	test('Bulleted list button prefixes each selected paragraph with a bullet', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Prior test saved a description → edit via the desc view area.
		await page.locator('.card-modal__desc-view').click()
		const editor = page.locator('.card-modal__section .kanso-md-editor')
		await expect(editor).toBeVisible({ timeout: 6000 })
		const prose = editor.locator('.ProseMirror')

		// Clear and type two lines.
		await prose.click()
		// Select all and delete first
		await page.keyboard.press('Control+A')
		await page.keyboard.press('Backspace')
		await page.keyboard.type('one')
		await page.keyboard.press('Enter')
		await page.keyboard.type('two')

		// Select all, then click the Bullet list toolbar button.
		await page.keyboard.press('Control+A')
		const listBtn = editor.locator('.kanso-md-editor__tb-btn[title="Bullet list"]')
		await expect(listBtn).toBeVisible({ timeout: 4000 })
		await listBtn.click()

		// The ProseMirror should now contain a <ul> with <li> items.
		await expect(prose.locator('ul li')).toHaveCount(2, { timeout: 4000 })
		const items = prose.locator('ul li')
		await expect(items.nth(0)).toContainText('one')
		await expect(items.nth(1)).toContainText('two')
	})
})
