// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// Helper: locate the Tiptap ProseMirror editor inside the new-comment composer.
// The composer now uses MarkdownEditor.vue (Tiptap) instead of a raw <textarea>.
function composerProse(page) {
	return page.locator('.card-modal__composer .kanso-md-editor .ProseMirror').first()
}

// Helper: locate the ProseMirror inside the reply compose box.
function replyProse(page) {
	return page.locator('.card-modal__reply-compose .kanso-md-editor .ProseMirror').first()
}

test.describe('Comments / Discussion', () => {
	const state = { boardId: 0, cardId: 0, boardUrl: '', cardUrl: '' }

	test.beforeAll(async () => {
		// Tear down any prior board with the same name to ensure hermetic setup
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Comments E2E Board') {
				await api.delete(`/boards/${b.id}`)
			}
		}

		// Create fresh board + stack + card
		const board = await api.post('/boards', { title: 'Comments E2E Board' })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Card With Discussion' })
		state.cardId = card.id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await api.delete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('post a top-level comment with markdown, assert rendering', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Discussion pane should be visible
		const discussionSection = page.locator('.card-modal__discussion')
		await expect(discussionSection).toBeVisible({ timeout: 5000 })

		// The composer now uses a Tiptap WYSIWYG editor (MarkdownEditor.vue).
		// Type into the ProseMirror contenteditable.
		const prose = composerProse(page)
		await expect(prose).toBeVisible({ timeout: 6000 })
		await prose.click()
		// Type bold markdown via the keyboard — Tiptap stores and serialises markdown.
		await prose.fill('Hello ')
		// Use Ctrl+B to bold the word "world" typed next
		await page.keyboard.press('Control+B')
		await page.keyboard.type('world')
		await page.keyboard.press('Control+B')
		await page.keyboard.type(' from test')

		// Submit via the Post button
		await page.locator('.card-modal__composer .card-modal__composer-actions button').first().click()

		// The comment body should appear and markdown should be rendered
		const commentBody = page.locator('.card-modal__comment-body').first()
		await expect(commentBody).toBeVisible({ timeout: 6000 })

		// **world** should render as <strong>world</strong>
		const strongEl = commentBody.locator('strong')
		await expect(strongEl).toBeVisible({ timeout: 4000 })
		await expect(strongEl).toHaveText('world')
	})

	test('post a reply under the top-level comment, assert nested rendering', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Wait for the first comment to appear
		await page.waitForSelector('.card-modal__comment', { timeout: 8000 })

		// Click the Reply button on the top-level comment (the top-level comment is
		// the direct child of a comment-group; replies are nested under __replies).
		const replyBtn = page.locator('.card-modal__comment-group > .card-modal__comment .card-modal__comment-link-btn').first()
		await expect(replyBtn).toBeVisible({ timeout: 5000 })
		await replyBtn.click()

		// Reply compose box should appear — it also uses the Tiptap editor.
		const prose = replyProse(page)
		await expect(prose).toBeVisible({ timeout: 6000 })

		// Type bold reply text
		await prose.click()
		await prose.fill('This is a ')
		await page.keyboard.press('Control+B')
		await page.keyboard.type('reply')
		await page.keyboard.press('Control+B')

		// Submit via Ctrl+Enter
		await prose.press('Control+Enter')

		// The reply should appear nested under the top-level comment
		const replies = page.locator('.card-modal__replies .card-modal__comment--reply')
		await expect(replies).toHaveCount(1, { timeout: 6000 })

		// Check reply markdown renders
		const replyBody = replies.locator('.card-modal__comment-body').first()
		await expect(replyBody.locator('strong')).toBeVisible({ timeout: 4000 })
	})

	test('card tile shows commentCount badge after closing modal', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		const tile = page.locator('.card-tile').filter({ hasText: 'Card With Discussion' })
		await expect(tile).toBeVisible({ timeout: 5000 })

		// After 2 comments (1 top-level + 1 reply), the badge should show >= 2
		const badge = tile.locator('.card-tile__comments')
		await expect(badge).toBeVisible({ timeout: 5000 })
		const badgeText = await badge.innerText()
		expect(Number(badgeText.trim())).toBeGreaterThanOrEqual(2)
	})

	test('edit the top-level comment and assert "edited" marker appears', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Wait for the top-level comment and its author controls
		const topComment = page.locator('.card-modal__comment-group > .card-modal__comment').first()
		await expect(topComment).toBeVisible({ timeout: 8000 })

		// Click the edit button (pencil icon) - the first non-danger icon button
		const editBtn = topComment.locator('.card-modal__comment-icon-btn:not(.card-modal__comment-icon-btn--danger)').first()
		await expect(editBtn).toBeVisible({ timeout: 5000 })
		await editBtn.click()

		// The inline edit textarea should appear (comment editing still uses a plain textarea)
		const editTa = topComment.locator('.card-modal__comment-edit-textarea')
		await expect(editTa).toBeVisible({ timeout: 4000 })

		// Change the body
		await editTa.fill('Updated **comment** body')
		await editTa.press('Control+Enter')

		// The "edited" marker should appear after saving
		const editedMarker = topComment.locator('.card-modal__comment-edited')
		await expect(editedMarker).toBeVisible({ timeout: 6000 })
	})

	test('delete top-level comment removes it and its reply from the UI', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Confirm the top-level comment and reply both exist
		await expect(page.locator('.card-modal__comment-group > .card-modal__comment')).toHaveCount(1, { timeout: 8000 })
		await expect(page.locator('.card-modal__comment--reply')).toHaveCount(1, { timeout: 5000 })

		// Click the delete button (trash icon) on the top-level comment
		const topComment = page.locator('.card-modal__comment-group > .card-modal__comment').first()
		const deleteBtn = topComment.locator('.card-modal__comment-icon-btn--danger')
		await expect(deleteBtn).toBeVisible({ timeout: 5000 })
		await deleteBtn.click()

		// Both the comment and its reply should be gone
		await expect(page.locator('.card-modal__comment-group > .card-modal__comment')).toHaveCount(0, { timeout: 6000 })
		await expect(page.locator('.card-modal__comment--reply')).toHaveCount(0, { timeout: 4000 })
	})

	test('reload confirms deletion is persisted', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// After reload there should be no comments
		await expect(page.locator('.card-modal__comment')).toHaveCount(0, { timeout: 8000 })
	})

	test('XSS payload in comment body is rendered inert - no alert fires', async ({ page }) => {
		// Track any alert dialogs - XSS would fire one
		let alertFired = false
		page.on('dialog', async (dialog) => {
			alertFired = true
			await dialog.dismiss()
		})

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Post a comment containing an XSS payload.
		// The Tiptap editor (html:false) stores and serialises plain markdown;
		// the read view uses renderMarkdown+DOMPurify, so raw HTML tags are
		// escaped to inert text, never parsed as markup.
		const xssPayload = 'Safe text <img src=x onerror=alert(1)> end'
		const prose = composerProse(page)
		await expect(prose).toBeVisible({ timeout: 6000 })
		await prose.click()
		await prose.fill(xssPayload)
		await page.locator('.card-modal__composer .card-modal__composer-actions button').first().click()

		// Wait for the comment to appear
		const commentBody = page.locator('.card-modal__comment-body').first()
		await expect(commentBody).toBeVisible({ timeout: 6000 })

		// No alert should have fired
		expect(alertFired).toBe(false)

		// No <img> element at all should be produced - markdown-it (html:false)
		// escapes the raw tag to inert text, so the payload never becomes markup.
		expect(await commentBody.locator('img').count()).toBe(0)

		// The payload survives as VISIBLE TEXT (proof it was neutralised, not
		// parsed as HTML): the literal tag shows in the rendered text content.
		await expect(commentBody).toContainText('<img src=x onerror=alert(1)>')
	})

	test('a failed comment post keeps the typed text (no data loss) (#3510)', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Force the comment POST to fail.
		await page.route('**/apps/kanso/api/cards/*/comments', (route) => {
			if (route.request().method() === 'POST') {
				return route.fulfill({
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify({ error: 'forced failure' }),
				})
			}
			return route.continue()
		})

		const prose = composerProse(page)
		await expect(prose).toBeVisible({ timeout: 6000 })
		await prose.click()
		await prose.fill('this text must survive a failed post')
		await page.locator('.card-modal__composer .card-modal__composer-actions button').first().click()

		// The Tiptap editor keeps the text so the user can retry (the v-model
		// newCommentBody is only cleared on success — never before the await).
		// With ProseMirror, check the contenteditable text content rather than
		// toHaveValue() (which only works on <textarea>/<input>).
		await expect(prose).toContainText('this text must survive a failed post', { timeout: 5000 })
	})
})
