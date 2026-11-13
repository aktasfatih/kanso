// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, BASE, api, me, ncLogin } from './helpers.js'

test.describe('New comment composer', () => {
	const state = { boardId: 0, stackId: 0, cardId: 0, commentId: 0, settings: null }

	test.beforeAll(async () => {
		const settings = await api.get('/settings')
		state.settings = { editorToolbarHidden: settings.editorToolbarHidden, cardDiscussionPosition: settings.cardDiscussionPosition }
		const board = await api.post('/boards', { title: `Comment Composer E2E Board ${Date.now()}` })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'To Do' })).id
	})

	test.beforeEach(async ({}, testInfo) => {
		await api.put('/settings', { editorToolbarHidden: false, cardDiscussionPosition: 'side' })
		const card = await api.post('/cards', { stackId: state.stackId, title: testInfo.title })
		state.cardId = card.id
		for (let i = 0; i < 14; i++) {
			const comment = await api.post(`/cards/${card.id}/comments`, { body: `Existing discussion ${i}` })
			if (i === 0) state.commentId = comment.id
		}
	})

	test.afterEach(async () => {
		if (state.settings) await api.put('/settings', state.settings)
	})

	test.afterAll(async () => {
		if (state.settings) await api.put('/settings', state.settings)
		if (state.boardId) await api.delete(`/boards/${state.boardId}`)
	})

	async function openCard(page, { width = 1280, height = 720, position = 'side', toolbarHidden = false } = {}) {
		await api.put('/settings', { editorToolbarHidden: toolbarHidden, cardDiscussionPosition: position })
		await page.setViewportSize({ width, height })
		await page.addInitScript(() => localStorage.removeItem('kanso.cardDiscussionCollapsed'))
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.cardId}`)
		await expect(page.locator('.card-modal')).toBeVisible()
		const mobileTab = page.locator('.card-modal__tabbar .card-modal__tab', { hasText: 'Discussion' })
		if (width <= 680 && position === 'side') {
			await expect(mobileTab).toBeVisible()
			await mobileTab.click()
		}
		const composer = page.locator('.card-modal__composer')
		const prose = composer.locator('.ProseMirror')
		await expect(prose).toBeVisible()
		await expect(page.locator('.card-modal__comment-body').first()).toBeVisible()
		return { composer, prose, toolbar: composer.locator('.kanso-md-editor__toolbar'), actions: composer.locator('.card-modal__composer-actions'), post: composer.getByRole('button', { name: 'Post', exact: true }) }
	}

	async function blurComposer(page) {
		await page.locator('.card-modal__discussion-tab').filter({ hasText: 'Discussion' }).focus()
	}

	async function expectCompactPlaceholder(prose) {
		const placeholder = prose.locator('[data-placeholder]')
		await expect(placeholder).toHaveAttribute('data-placeholder', 'Start a new thread…')
		const metrics = await placeholder.evaluate(el => {
			const style = getComputedStyle(el, '::before')
			return { height: parseFloat(style.height), lineHeight: parseFloat(style.lineHeight), paragraphHeight: el.getBoundingClientRect().height }
		})
		expect(metrics.height).toBeGreaterThan(0)
		expect(metrics.height).toBeLessThanOrEqual(metrics.lineHeight + 1)
		expect(metrics.paragraphHeight).toBeLessThanOrEqual(metrics.lineHeight + 1)
	}

	async function expectPostHittable(composer) {
		expect(await composer.evaluate((el) => {
			const actions = el.querySelector('.card-modal__composer-actions')
			const post = actions.querySelector('button')
			const r = post.getBoundingClientRect()
			const hit = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2)
			let overhang = r.bottom - innerHeight
			for (let parent = el.parentElement; parent; parent = parent.parentElement) {
				if (getComputedStyle(parent).overflowY !== 'visible') overhang = Math.max(overhang, r.bottom - parent.getBoundingClientRect().bottom)
			}
			return overhang <= 1 && !!hit && el.contains(hit) && (post === hit || post.contains(hit) || hit.closest('.card-modal__composer-actions') === actions)
		})).toBe(true)
	}

	test('untouched composer frees discussion space on a short desktop', async ({ page }) => {
		const { composer, prose, toolbar, actions } = await openCard(page)
		await expect(toolbar).toBeHidden()
		await expect(actions).toBeHidden()
		await expectCompactPlaceholder(prose)
		const closed = await composer.boundingBox()
		const threadClosed = await page.locator('.card-modal__thread-scroll').boundingBox()
		expect(closed.height).toBeLessThan(80)
		await prose.click()
		await expect(toolbar).toBeVisible()
		await expect(actions).toBeVisible()
		const expanded = await composer.boundingBox()
		const threadExpanded = await page.locator('.card-modal__thread-scroll').boundingBox()
		expect(expanded.height - closed.height).toBeGreaterThan(80)
		expect(threadClosed.height - threadExpanded.height).toBeGreaterThan(80)
		await expectPostHittable(composer)
		await blurComposer(page)
		await expect(actions).toBeHidden()
		expect((await composer.boundingBox()).height).toBeLessThan(80)
	})

	for (const layout of [{ name: 'phone', width: 390, height: 720, position: 'side' }, { name: 'bottom', width: 1280, height: 720, position: 'bottom' }]) {
		test(`${layout.name} layout keeps the compact entry and expanded controls visible`, async ({ page }) => {
			const { composer, prose, toolbar, actions, post } = await openCard(page, layout)
			await expect(actions).toBeHidden()
			await expectCompactPlaceholder(prose)
			if (layout.name === 'phone') {
				const longPlaceholder = 'Start a new discussion thread with everyone on this board…'
				const style = await page.addStyleTag({ content: `.card-modal__composer .ProseMirror [data-placeholder]::before { content: ${JSON.stringify(longPlaceholder)} !important; }` })
				try {
					expect(await prose.locator('[data-placeholder]').evaluate(el => getComputedStyle(el, '::before').content)).toBe(JSON.stringify(longPlaceholder))
					await expectCompactPlaceholder(prose)
					expect(await prose.evaluate(el => el.scrollWidth - el.clientWidth)).toBeLessThanOrEqual(1)
				} finally {
					await style.evaluate(el => el.remove())
				}
			}
			const closed = await composer.boundingBox()
			expect(closed.height).toBeLessThan(80)
			await prose.click()
			await expect(toolbar).toBeVisible()
			await expect(actions).toBeVisible()
			expect((await composer.boundingBox()).height - closed.height).toBeGreaterThan(80)
			if (layout.name === 'phone') await post.scrollIntoViewIfNeeded()
			await expectPostHittable(composer)
			await prose.fill(`${layout.name} comment`)
			await post.click()
			await expect(page.locator('.card-modal__comment-body').filter({ hasText: `${layout.name} comment` })).toBeVisible()
		})
	}

	test('keyboard entry, formatting controls and Post keep the composer expanded', async ({ page }) => {
		const { composer, prose, toolbar, actions, post } = await openCard(page)
		await page.evaluate(() => {
			const composer = document.querySelector('.card-modal__composer')
			const candidates = [...document.querySelectorAll('button, a[href], input, textarea, [tabindex]')].filter(el => el.tabIndex >= 0 && !el.disabled && el.getClientRects().length && !composer.contains(el) && (el.compareDocumentPosition(composer) & Node.DOCUMENT_POSITION_FOLLOWING))
			candidates.at(-1).focus()
		})
		await expect(actions).toBeHidden()
		for (let i = 0; i < 20; i++) {
			await page.keyboard.press('Tab')
			if (await composer.evaluate(el => el.contains(document.activeElement))) break
		}
		await expect(actions).toBeVisible()
		await prose.focus()
		await page.keyboard.press('Shift+Tab')
		await expect(toolbar.locator(':focus')).toHaveCount(1)
		await expect(actions).toBeVisible()
		await prose.fill('Keyboard posted thread')
		await page.keyboard.press('Tab')
		await expect(post).toBeFocused()
		await page.keyboard.press('Space')
		await expect(page.locator('.card-modal__comment-body').filter({ hasText: 'Keyboard posted thread' })).toBeVisible()
		await expect(prose).toBeEmpty()
		await blurComposer(page)
		await expect(actions).toBeHidden()
	})

	test('draft and controls survive blur, a pending post and failure', async ({ page }) => {
		const { prose, toolbar, actions, post } = await openCard(page)
		await prose.fill('Draft that must survive')
		await blurComposer(page)
		await expect(actions).toBeVisible()
		await expect(prose).toContainText('Draft that must survive')
		let release
		let started = false
		const gate = new Promise(resolve => { release = resolve })
		await page.route(`**/apps/kanso/api/cards/${state.cardId}/comments`, async route => {
			if (route.request().method() !== 'POST') return route.continue()
			started = true
			await gate
			return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ error: 'Composer forced failure' }) })
		})
		try {
			await post.click()
			await expect.poll(() => started).toBe(true)
			await blurComposer(page)
			await expect(post).toBeDisabled()
			await expect(actions).toBeVisible()
			await expect(toolbar).toBeVisible()
			await expect(prose).toContainText('Draft that must survive')
		} finally {
			release()
		}
		await expect(page.locator('.card-modal__save-error').filter({ hasText: 'Composer forced failure' })).toBeVisible()
		await expect(prose).toContainText('Draft that must survive')
		await expect(post).toBeEnabled()
		await prose.focus()
		await expect(toolbar).toBeVisible()
	})

	test('an image upload keeps empty composer controls visible after blur', async ({ page }) => {
		const { composer, prose, actions } = await openCard(page)
		let release
		let started = false
		const gate = new Promise(resolve => { release = resolve })
		await page.route(`**/apps/kanso/api/cards/${state.cardId}/attachments`, async route => {
			if (route.request().method() !== 'POST') return route.continue()
			started = true
			await gate
			return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ error: 'Composer upload failure' }) })
		})
		try {
			await prose.focus()
			await prose.evaluate(el => {
				const data = new DataTransfer()
				const bytes = Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII='), char => char.charCodeAt(0))
				data.items.add(new File([bytes], 'composer.png', { type: 'image/png' }))
				el.dispatchEvent(new ClipboardEvent('paste', { bubbles: true, cancelable: true, clipboardData: data }))
			})
			await expect.poll(() => started).toBe(true)
			await blurComposer(page)
			await expect(actions).toBeVisible()
			await expect(composer.getByText('Uploading image…', { exact: true })).toBeVisible()
		} finally {
			release()
		}
		await expect(composer.getByText('Composer upload failure', { exact: true })).toBeVisible()
	})

	test('toolbar formatting and its link dialog preserve the draft', async ({ page }) => {
		const { prose, toolbar, post } = await openCard(page)
		await prose.fill('Formatted link')
		await prose.press('Control+A')
		await toolbar.getByRole('button', { name: 'Bold', exact: true }).click()
		page.once('dialog', dialog => dialog.accept('https://example.com/composer'))
		await toolbar.getByRole('button', { name: 'Link', exact: true }).click()
		await expect(prose.locator('a[href="https://example.com/composer"]')).toContainText('Formatted link')
		await expect(toolbar).toBeVisible()
		await post.click()
		const body = page.locator('.card-modal__comment-body').filter({ hasText: 'Formatted link' })
		await expect(body.locator('strong')).toContainText('Formatted link')
		await expect(body.locator('a')).toHaveAttribute('href', 'https://example.com/composer')
	})

	test('mention selection keeps the editor usable', async ({ page }) => {
		const { prose, toolbar, post } = await openCard(page)
		await prose.click()
		await prose.pressSequentially(`@${me.slice(0, 3)}`)
		const suggestion = page.locator('.kanso-md-editor__mention-item').filter({ hasText: me }).first()
		await expect(suggestion).toBeVisible()
		await suggestion.click()
		await expect(prose).toContainText(`@${me}`)
		await expect(toolbar).toBeVisible()
		await post.click()
		await expect(page.locator('.card-modal__comment-body').filter({ hasText: `@${me}` })).toBeVisible()
	})

	test('hidden-toolbar preference and reply/edit flows remain intact', async ({ page }) => {
		const { prose, toolbar, actions } = await openCard(page, { toolbarHidden: true })
		await prose.click()
		await expect(actions).toBeVisible()
		await expect(toolbar).toHaveCount(0)
		const comment = page.locator(`#comment-${state.commentId}`)
		await comment.locator('.card-modal__comment-link-btn').first().click()
		const reply = page.locator('.card-modal__reply-compose .ProseMirror')
		await expect(reply).toBeVisible()
		await expect(page.locator('.card-modal__reply-compose .kanso-md-editor__toolbar')).toHaveCount(0)
		await reply.fill('Reply remains usable')
		await reply.press('Control+Enter')
		await expect(page.locator('.card-modal__comment-body').filter({ hasText: 'Reply remains usable' })).toBeVisible()
		await comment.locator('.card-modal__comment').first().getByRole('button', { name: 'Edit comment', exact: true }).click()
		const edit = comment.locator('.card-modal__comment-edit-textarea')
		await expect(edit).toBeVisible()
		await edit.fill('Edited discussion')
		await edit.press('Control+Enter')
		await expect(comment.locator('.card-modal__comment-body').first()).toContainText('Edited discussion')
		expect((await api.get('/settings')).editorToolbarHidden).toBe(true)
	})

	test('composition confirmation does not post and Ctrl+Enter still does', async ({ page }) => {
		const { prose } = await openCard(page)
		await prose.fill('Composition draft')
		let posted = 0
		page.on('request', request => {
			if (request.method() === 'POST' && request.url().endsWith(`/cards/${state.cardId}/comments`)) posted++
		})
		await prose.dispatchEvent('compositionstart', { data: '' })
		await prose.dispatchEvent('keydown', { key: 'Enter', code: 'Enter', keyCode: 229, which: 229, isComposing: true })
		expect(posted).toBe(0)
		await expect(prose).toContainText('Composition draft')
		await prose.dispatchEvent('compositionend', { data: '' })
		await prose.press('Control+Enter')
		await expect(page.locator('.card-modal__comment-body').filter({ hasText: 'Composition draft' })).toBeVisible()
		expect(posted).toBe(1)
	})

	test('unsaved draft guard retains text when leaving is cancelled', async ({ page }) => {
		const { prose, actions } = await openCard(page)
		await prose.fill('Unsaved composer draft')
		await blurComposer(page)
		const messages = []
		let discard = false
		page.on('dialog', async dialog => {
			messages.push(dialog.message())
			await (discard ? dialog.accept() : dialog.dismiss())
		})
		await page.locator('.card-modal-modal .modal-container__close').click()
		await expect.poll(() => messages.length).toBe(1)
		expect(messages[0]).toContain('unsaved changes')
		await expect(page.locator('.card-modal')).toBeVisible()
		await expect(prose).toContainText('Unsaved composer draft')
		await expect(actions).toBeVisible()
		discard = true
		await page.locator('.card-modal-modal .modal-container__close').click()
		await expect(page.locator('.card-modal')).toHaveCount(0)
		expect(messages).toHaveLength(2)
	})
})
