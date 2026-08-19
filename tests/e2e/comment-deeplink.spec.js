// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Scroll-to-comment deep links (#3870).
 *
 * A reminder notification links to a card carrying a `#comment-<id>` fragment
 * (Notifier::cardLink). The fragment-free deep-link boot (main.js) stashes that
 * id into the route query (`?comment=<id>`) before it hash-routes into the SPA;
 * CardDetail then scrolls to + briefly highlights that comment once the thread
 * loads. This spec drives the full-page card route with such a target and asserts
 * the targeted comment is scrolled into view and carries the highlight class.
 */

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = {
	'OCS-APIREQUEST': 'true',
	'Content-Type': 'application/json',
}
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
}

async function ncLogin(page) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	const userInput = page.locator('#user')
	const isLoginPage = await userInput.isVisible({ timeout: 3000 }).catch(() => false)
	if (!isLoginPage) return
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

test.describe('Scroll-to-comment deep links (#3870)', () => {
	const state = { boardId: 0, cardId: 0, comments: [], replyId: 0 }

	test.beforeAll(async () => {
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'Comment Deeplink E2E') {
				await apiDelete(`/boards/${b.id}`)
			}
		}
		const board = await apiPost('/boards', { title: 'Comment Deeplink E2E' })
		state.boardId = board.id
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'To Do' })
		const card = await apiPost('/cards', { stackId: stack.id, title: 'Card With Many Comments' })
		state.cardId = card.id

		// Enough top-level comments that the target is well below the fold, so a
		// scroll genuinely has to happen (not already in view).
		for (let i = 1; i <= 8; i++) {
			const c = await apiPost(`/cards/${card.id}/comments`, { body: `Comment number ${i} body text here` })
			state.comments.push(c.id)
		}
		// A reply under the first comment - replies are deep-linkable too.
		const reply = await apiPost(`/cards/${card.id}/comments`, {
			body: 'A nested reply to comment one',
			parentCommentId: state.comments[0],
		})
		state.replyId = reply.id
	})

	test.afterAll(async () => {
		if (state.boardId) await apiDelete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('full-page card route with ?comment=<id> scrolls to + highlights the target', async ({ page }) => {
		const errors = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') errors.push(msg.text())
		})

		// The 6th comment - far enough down the thread to require a scroll.
		const targetId = state.comments[5]
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/card/${state.cardId}?comment=${targetId}`)

		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const target = page.locator(`#comment-${targetId}`)
		await expect(target).toBeVisible({ timeout: 10_000 })

		// The transient highlight class lands on the target while it fades.
		await expect(target).toHaveClass(/card-modal__comment-group--highlight/, { timeout: 5000 })

		// And it is actually scrolled into the viewport (not merely present in DOM).
		await expect(target).toBeInViewport({ timeout: 5000 })

		// The highlight is transient: it clears after the fade (~4s).
		await expect(target).not.toHaveClass(/card-modal__comment-group--highlight/, { timeout: 8000 })

		// No console errors from the scroll-to-comment path.
		const relevant = errors.filter((e) => !/favicon|manifest|ResizeObserver/i.test(e))
		expect(relevant, `console errors: ${relevant.join('\n')}`).toEqual([])
	})

	test('raw #comment-<id> fragment on the full-page route also scrolls + highlights', async ({ page }) => {
		// An in-app link that carries the raw fragment (no query) still works: the
		// SPA reads window.location.hash as a fallback.
		const targetId = state.comments[6]
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/card/${state.cardId}#comment-${targetId}`)

		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const target = page.locator(`#comment-${targetId}`)
		await expect(target).toBeVisible({ timeout: 10_000 })
		await expect(target).toHaveClass(/card-modal__comment-group--highlight/, { timeout: 5000 })
		await expect(target).toBeInViewport({ timeout: 5000 })
	})

	test('a reply is deep-linkable and gets highlighted', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/card/${state.cardId}?comment=${state.replyId}`)

		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const reply = page.locator(`#comment-${state.replyId}`)
		await expect(reply).toBeVisible({ timeout: 10_000 })
		await expect(reply).toHaveClass(/card-modal__comment--highlight/, { timeout: 5000 })
	})

	test('an unknown comment fragment opens the card normally with no error', async ({ page }) => {
		const errors = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') errors.push(msg.text())
		})

		await ncLogin(page)
		// A comment id that does not exist in this thread.
		await page.goto(`${BASE}/index.php/apps/kanso#/card/${state.cardId}?comment=99999999`)

		await page.waitForSelector('.card-modal', { timeout: 10_000 })
		// Card opens fine and the thread renders.
		await expect(page.locator('.card-modal__comment-group').first()).toBeVisible({ timeout: 10_000 })
		// Nothing got the highlight class.
		await expect(page.locator('.card-modal__comment-group--highlight')).toHaveCount(0)

		const relevant = errors.filter((e) => !/favicon|manifest|ResizeObserver/i.test(e))
		expect(relevant, `console errors: ${relevant.join('\n')}`).toEqual([])
	})
})
