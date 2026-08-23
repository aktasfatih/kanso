// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'
import { buildCardPrompt, formatPromptDate } from '../../src/utils/cardPrompt.js'

// ── Unit coverage for the pure buildCardPrompt helper (no browser needed) ──────
test.describe('buildCardPrompt (unit)', () => {
	const ts = Math.floor(Date.UTC(2026, 0, 15, 10, 30) / 1000)

	test('emits title, description and chronological comment thread', () => {
		const card = { title: 'My Card', description: 'Line one\nLine two' }
		const comments = [
			{ id: 1, parentCommentId: null, author: 'alice', authorDisplayName: 'Alice', body: 'Top comment', createdAt: ts },
			{ id: 2, parentCommentId: 1, author: 'bob', authorDisplayName: 'Bob', body: 'A reply', createdAt: ts + 60 },
			{ id: 3, parentCommentId: null, author: 'carol', authorDisplayName: '', body: 'Second thread', createdAt: ts + 120 },
		]
		const out = buildCardPrompt(card, comments)

		expect(out).toContain('# My Card')
		expect(out).toContain('Line one\nLine two')
		expect(out).toContain('## Comments')
		expect(out).toContain('**Alice**')
		expect(out).toContain('Top comment')
		// Reply is quoted and follows its parent.
		expect(out).toContain('> **Bob**')
		expect(out).toContain('> A reply')
		// Missing display name falls back to author uid.
		expect(out).toContain('**carol**')
		// Order: Alice thread (with Bob reply) before Carol's thread.
		expect(out.indexOf('Top comment')).toBeLessThan(out.indexOf('A reply'))
		expect(out.indexOf('A reply')).toBeLessThan(out.indexOf('Second thread'))
	})

	test('omits the Comments section when there are none', () => {
		const out = buildCardPrompt({ title: 'Solo', description: 'Just a body' }, [])
		expect(out).toContain('# Solo')
		expect(out).toContain('Just a body')
		expect(out).not.toContain('## Comments')
	})

	test('handles missing title/description gracefully', () => {
		expect(buildCardPrompt({}, [])).toBe('# Untitled card\n')
		expect(buildCardPrompt(null, null)).toBe('# Untitled card\n')
	})

	test('copies raw markdown body, not rendered HTML', () => {
		const out = buildCardPrompt(
			{ title: 'X', description: '' },
			[{ id: 1, parentCommentId: null, author: 'a', authorDisplayName: 'A', body: 'Some **bold** text', createdAt: ts }],
		)
		expect(out).toContain('Some **bold** text')
		expect(out).not.toContain('<strong>')
	})

	test('sorts chronologically even when the array is out of order', () => {
		const out = buildCardPrompt(
			{ title: 'X', description: '' },
			[
				{ id: 2, parentCommentId: null, author: 'b', authorDisplayName: 'B', body: 'Newer top', createdAt: ts + 100 },
				{ id: 1, parentCommentId: null, author: 'a', authorDisplayName: 'A', body: 'Older top', createdAt: ts },
			],
		)
		expect(out.indexOf('Older top')).toBeLessThan(out.indexOf('Newer top'))
	})

	test('retains a reply whose parent is missing from the page (no data loss)', () => {
		const out = buildCardPrompt(
			{ title: 'X', description: '' },
			[{ id: 5, parentCommentId: 999, author: 'z', authorDisplayName: 'Z', body: 'Orphan reply', createdAt: ts }],
		)
		expect(out).toContain('## Comments')
		expect(out).toContain('Orphan reply')
	})

	test('formatPromptDate returns empty for invalid timestamps', () => {
		expect(formatPromptDate(0)).toBe('')
		expect(formatPromptDate(null)).toBe('')
		expect(formatPromptDate('nope')).toBe('')
		expect(formatPromptDate(ts)).not.toBe('')
	})
})

test.describe('Copy as prompt', () => {
	const state = { boardId: 0, cardId: 0, cardUrl: '' }
	const CARD_TITLE = 'Prompt Copy Card'
	const DESCRIPTION = 'A **descriptive** body for the prompt.'
	const COMMENT_BODY = 'First insightful comment on the card.'

	test.beforeAll(async () => {
		// Hermetic setup: tear down any prior board with this name.
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Copy Prompt E2E Board') {
				await api.delete(`/boards/${b.id}`)
			}
		}

		const board = await api.post('/boards', { title: 'Copy Prompt E2E Board' })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api.post('/cards', { stackId: stack.id, title: CARD_TITLE })
		state.cardId = card.id
		// Give the card a description (card#update is PATCH /api/cards/{id}).
		await api.patch(`/cards/${card.id}`, { description: DESCRIPTION })
		// Post a comment via API so setup is deterministic.
		await api.post(`/cards/${card.id}/comments`, { body: COMMENT_BODY })

		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await api.delete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('copies title + description + comment thread to the clipboard as markdown', async ({ page, context }) => {
		// Grant clipboard read/write so we can verify the actual copied content.
		await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE })

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Open the overflow (⋯) actions menu in the card modal header.
		const menuTrigger = page.locator('.card-modal__actions-menu button').first()
		await expect(menuTrigger).toBeVisible({ timeout: 5000 })
		await menuTrigger.click()

		// Click the "Copy as prompt" item (rendered in a portal by NcActions).
		const copyItem = page.getByRole('menuitem', { name: 'Copy as prompt' })
		await expect(copyItem).toBeVisible({ timeout: 5000 })
		await copyItem.click()

		// A success toast should confirm the copy.
		await expect(page.locator('.toast-success, .toastify.toast-success')).toBeVisible({ timeout: 6000 })

		// Read the clipboard back and assert it contains the title + comment body.
		const clip = await page.evaluate(() => navigator.clipboard.readText())
		expect(clip).toContain(`# ${CARD_TITLE}`)
		expect(clip).toContain(DESCRIPTION)
		expect(clip).toContain('## Comments')
		expect(clip).toContain(COMMENT_BODY)
	})
})
