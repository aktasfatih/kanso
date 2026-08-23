// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Project discussion log e2e (#3563): the project page carries an owner-only
// comment thread reusing the markdown editor. Post a comment (with markdown),
// assert it renders as HTML and persists across a full reload; then edit and
// delete through the UI.

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Project discussion log (owner-only comments)', () => {
	const state = { projectId: 0 }

	test.beforeAll(async () => {
		const project = await api.post('/projects', { title: `Discussion Project ${Date.now()}` })
		state.projectId = project.id
	})

	test.afterAll(async () => {
		if (state.projectId) await api.delete(`/projects/${state.projectId}`).catch(() => {})
	})

	test('post a comment with markdown, assert it renders and persists across reload', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects/${state.projectId}`)
		await expect(page.locator('.project-view')).toBeVisible({ timeout: 10_000 })

		// The discussion section + its composer are present.
		const composer = page.locator('.project-view__composer')
		await expect(composer).toBeVisible({ timeout: 8_000 })

		// Post a comment carrying markdown (bold text).
		const ta = composer.locator('.project-view__comment-textarea')
		await ta.fill('First **note** in the log')
		await composer.getByRole('button', { name: /^Post$/ }).click()

		// The rendered comment body appears with the markdown turned into HTML.
		const body = page.locator('.project-view__comment-body').first()
		await expect(body).toBeVisible({ timeout: 8_000 })
		await expect(body.locator('strong')).toHaveText('note')
		// Raw asterisks must NOT be present as literal text.
		await expect(body).not.toContainText('**note**')

		// Server persisted the raw markdown.
		const comments = await api.get(`/projects/${state.projectId}/comments`)
		expect(comments.length).toBe(1)
		expect(comments[0].body).toBe('First **note** in the log')

		// Persists across a full reload (database-first, not just optimistic UI).
		await page.reload()
		await expect(page.locator('.project-view')).toBeVisible({ timeout: 10_000 })
		const bodyAfter = page.locator('.project-view__comment-body').first()
		await expect(bodyAfter.locator('strong')).toHaveText('note', { timeout: 8_000 })
	})

	test('post a one-level reply under the top-level comment', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects/${state.projectId}`)
		await expect(page.locator('.project-view__comment')).toBeVisible({ timeout: 10_000 })

		// Open the reply box on the top-level comment.
		const replyBtn = page.locator('.project-view__comment-group > .project-view__comment .project-view__comment-link-btn').first()
		await expect(replyBtn).toBeVisible({ timeout: 5_000 })
		await replyBtn.click()

		const replyTa = page.locator('.project-view__reply-compose .project-view__comment-textarea').first()
		await expect(replyTa).toBeVisible({ timeout: 4_000 })
		await replyTa.fill('A **reply** note')
		await replyTa.press('Control+Enter')

		// The reply appears nested under the top-level comment, rendered as markdown.
		const replies = page.locator('.project-view__replies .project-view__comment--reply')
		await expect(replies).toHaveCount(1, { timeout: 8_000 })
		await expect(replies.locator('.project-view__comment-body strong').first()).toBeVisible({ timeout: 4_000 })
	})

	test('edit the top-level comment and assert the "edited" marker appears', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects/${state.projectId}`)
		const topComment = page.locator('.project-view__comment-group > .project-view__comment').first()
		await expect(topComment).toBeVisible({ timeout: 10_000 })

		const editBtn = topComment.locator('.project-view__comment-icon-btn:not(.project-view__comment-icon-btn--danger)').first()
		await editBtn.click()

		const editTa = topComment.locator('.project-view__comment-textarea')
		await expect(editTa).toBeVisible({ timeout: 4_000 })
		await editTa.fill('Updated **note** body')
		await editTa.press('Control+Enter')

		await expect(topComment.locator('.project-view__comment-edited')).toBeVisible({ timeout: 8_000 })
	})

	test('delete the top-level comment removes it and its reply', async ({ page }) => {
		// Self-contained: don't depend on the prior tests' thread (order/sharding
		// safe). Reset to exactly one top-level comment + one reply via the API.
		const existing = await api.get(`/projects/${state.projectId}/comments`)
		for (const c of existing.filter((c) => c.parentCommentId == null)) {
			await api.delete(`/project-comments/${c.id}`).catch(() => {})
		}
		const top = await api.post(`/projects/${state.projectId}/comments`, { body: 'Top **note** to delete' })
		await api.post(`/projects/${state.projectId}/comments`, { body: 'A **reply** note', parentCommentId: top.id })

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects/${state.projectId}`)
		await expect(page.locator('.project-view__comment-group > .project-view__comment')).toHaveCount(1, { timeout: 10_000 })
		await expect(page.locator('.project-view__comment--reply')).toHaveCount(1, { timeout: 5_000 })

		const topComment = page.locator('.project-view__comment-group > .project-view__comment').first()
		await topComment.locator('.project-view__comment-icon-btn--danger').click()

		await expect(page.locator('.project-view__comment-group > .project-view__comment')).toHaveCount(0, { timeout: 8_000 })
		await expect(page.locator('.project-view__comment--reply')).toHaveCount(0, { timeout: 4_000 })

		// Server agrees the whole thread is gone.
		const comments = await api.get(`/projects/${state.projectId}/comments`)
		expect(comments.length).toBe(0)
	})

	test('XSS payload in a project comment is rendered inert - no alert fires', async ({ page }) => {
		let alertFired = false
		page.on('dialog', async (dialog) => {
			alertFired = true
			await dialog.dismiss()
		})

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects/${state.projectId}`)
		await expect(page.locator('.project-view__composer')).toBeVisible({ timeout: 10_000 })

		const ta = page.locator('.project-view__composer .project-view__comment-textarea')
		await ta.fill('Safe text <img src=x onerror=alert(1)> end')
		await page.locator('.project-view__composer').getByRole('button', { name: /^Post$/ }).click()

		const body = page.locator('.project-view__comment-body').first()
		await expect(body).toBeVisible({ timeout: 8_000 })
		expect(alertFired).toBe(false)
		expect(await body.locator('img').count()).toBe(0)
		await expect(body).toContainText('<img src=x onerror=alert(1)>')
	})
})
