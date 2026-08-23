// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Markdown card descriptions - render and XSS safety', () => {
	const state = {
		boardId: 0,
		stackId: 0,
		cardId: 0,
		boardUrl: '',
		cardUrl: '',
	}

	const DESCRIPTION = '# Heading\n\n**bold** and [a link](https://example.com)\n\n<script>alert(1)</script>'

	test.beforeAll(async () => {
		// Clean up any leftover test board
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Markdown Test Board') {
				await api.delete(`/boards/${b.id}`)
			}
		}

		// Seed board + stack + card
		const board = await api.post('/boards', { title: 'Markdown Test Board' })
		state.boardId = board.id

		const stack = await api.post('/stacks', { boardId: board.id, title: 'Test Stack' })
		state.stackId = stack.id

		const card = await api.post('/cards', { stackId: stack.id, title: 'MD Card' })
		state.cardId = card.id

		// PATCH the card description with markdown + XSS payload
		await api.patch(`/cards/${card.id}`, { description: DESCRIPTION })

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
		console.log('Setup complete - cardUrl:', state.cardUrl)
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await api.delete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('renders markdown (bold, link) and strips XSS payload', async ({ page }) => {
		// Track any alert dialogs - XSS would fire one
		let alertFired = false
		page.on('dialog', async (dialog) => {
			alertFired = true
			await dialog.dismiss()
		})

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		// Wait for the card modal to appear
		await page.waitForSelector('.card-modal__desc-rendered', { timeout: 10_000 })

		// ── Positive assertions: safe markdown is rendered ────────────────────────

		// <strong> element inside the rendered description
		const strongEl = page.locator('.card-modal__desc-rendered strong')
		await expect(strongEl).toBeVisible({ timeout: 5000 })

		// <a> linking to https://example.com
		const linkEl = page.locator('.card-modal__desc-rendered a[href="https://example.com"]')
		await expect(linkEl).toBeVisible({ timeout: 5000 })

		// Link should have safe rel + target
		await expect(linkEl).toHaveAttribute('rel', 'noopener noreferrer')
		await expect(linkEl).toHaveAttribute('target', '_blank')

		// ── Negative assertions: XSS payload is neutralised ──────────────────────

		// No <script> elements inside the description container
		const scriptCount = await page.locator('.card-modal__desc-rendered script').count()
		expect(scriptCount).toBe(0)

		// No alert dialog was fired by the XSS payload
		expect(alertFired).toBe(false)

		// The raw text "<script>" must not appear as an unescaped tag in the HTML
		const descHtml = await page.locator('.card-modal__desc-rendered').innerHTML()
		expect(descHtml).not.toMatch(/<script[\s>]/i)
	})

	// Inline card-attachment images (#3525): a same-origin inline-endpoint <img>
	// renders; any OTHER img src (external host, data:, javascript:, svg,
	// protocol-relative) is stripped by the sanitiser — no external fetch, no XSS.
	test('renders a same-origin inline-attachment image and strips every other img', async ({ page }) => {
		let alertFired = false
		page.on('dialog', async (dialog) => {
			alertFired = true
			await dialog.dismiss()
		})

		// A description mixing a legit inline-attachment image with hostile ones.
		// The inline path is what cardAttachmentInlineUrl() produces for this card.
		const inlineSrc = `/apps/kanso/api/cards/${state.cardId}/attachments/1/inline`
		const md = [
			`![ok](${inlineSrc})`,
			'![ext](https://evil.example.com/pixel.png)',
			'![data](data:image/png;base64,AAAA)',
			'![proto](//evil.example.com/x.png)',
			'![svg](/apps/kanso/api/cards/1/attachments/1/inline.svg)',
			'![js](javascript:alert(1))',
			'<img src=x onerror=alert(1)>',
		].join('\n\n')
		await api.patch(`/cards/${state.cardId}`, { description: md })

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal__desc-rendered', { timeout: 10_000 })

		// Exactly ONE img element survives: the same-origin inline-attachment one.
		// Every hostile img markdown produced NO rendered <img> (external/data:/
		// protocol-relative/svg src stripped by the hook; javascript:/raw-<img>
		// never became an element at all — they stay inert, escaped plain text).
		const imgs = page.locator('.card-modal__desc-rendered img')
		await expect(imgs).toHaveCount(1, { timeout: 5000 })
		const src = await imgs.first().getAttribute('src')
		expect(src).toContain(`/api/cards/${state.cardId}/attachments/1/inline`)

		// The surviving img carries NO on* handler and its src is same-origin only.
		const onerror = await imgs.first().getAttribute('onerror')
		expect(onerror).toBeNull()
		expect(src).not.toContain('evil.example.com')
		expect(src).not.toContain('data:')

		// No img element anywhere points at an external / data: / javascript: src
		// (i.e. nothing hostile was rendered as an actual <img>).
		expect(await page.locator('.card-modal__desc-rendered img[src*="evil.example.com"]').count()).toBe(0)
		expect(await page.locator('.card-modal__desc-rendered img[src^="data:"]').count()).toBe(0)
		expect(await page.locator('.card-modal__desc-rendered img[src^="//"]').count()).toBe(0)
		expect(await page.locator('.card-modal__desc-rendered img[onerror]').count()).toBe(0)

		// The onerror payload survives only as INERT escaped text, never as a live
		// attribute/element — so no dialog fires.
		expect(alertFired).toBe(false)

		// Restore the original description for the reload test below.
		await api.patch(`/cards/${state.cardId}`, { description: DESCRIPTION })
	})

	test('markdown is still safe after page reload', async ({ page }) => {
		let alertFired = false
		page.on('dialog', async (dialog) => {
			alertFired = true
			await dialog.dismiss()
		})

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal__desc-rendered', { timeout: 10_000 })

		// Reload to verify persistence
		await page.reload()
		await page.waitForSelector('.card-modal__desc-rendered', { timeout: 10_000 })

		// Strong and link still rendered
		await expect(page.locator('.card-modal__desc-rendered strong')).toBeVisible({ timeout: 5000 })
		await expect(page.locator('.card-modal__desc-rendered a[href="https://example.com"]')).toBeVisible({ timeout: 5000 })

		// Still no XSS
		const scriptCount = await page.locator('.card-modal__desc-rendered script').count()
		expect(scriptCount).toBe(0)
		expect(alertFired).toBe(false)

		const descHtml = await page.locator('.card-modal__desc-rendered').innerHTML()
		expect(descHtml).not.toMatch(/<script[\s>]/i)
	})
})
