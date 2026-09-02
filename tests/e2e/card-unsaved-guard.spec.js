// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// #10069 — leaving a card without saving used to throw the work away silently:
// the description, the title, a new thread and a reply are ALL explicit-save, and
// dismissing the card just navigated away. These specs pin the confirm-before-you-
// lose-it behaviour, the "don't nag me when nothing changed" half of it (which is
// what would make the feature hated), and the draft LEAK the same defect caused —
// the board renders CardDetail through an unkeyed <router-view>, so a half-typed
// reply used to survive a card→card switch and turn up in the next card.

/** The ProseMirror inside the new-thread composer. */
function composerProse(page) {
	return page.locator('.card-modal__composer .kanso-md-editor .ProseMirror').first()
}

/** The ProseMirror inside the open reply box. */
function replyProse(page) {
	return page.locator('.card-modal__reply-compose .kanso-md-editor .ProseMirror').first()
}

/** The ProseMirror inside the description editor. */
function descProse(page) {
	return page.locator('.card-modal__section .kanso-md-editor .ProseMirror').first()
}

// A point on the dark backdrop: midway between the modal wrapper's left edge and
// the centred container's left edge, at the container's vertical centre. Same
// geometry card-modal-layout.spec.js uses — that point resolves to .modal-wrapper,
// which is what the close-on-backdrop handler keys off.
async function backdropPoint(page) {
	return page.evaluate(() => {
		const wrapper = document.querySelector('.card-modal-modal .modal-wrapper')
		const container = document.querySelector('.card-modal-modal .modal-container')
		const wb = wrapper.getBoundingClientRect()
		const cb = container.getBoundingClientRect()
		return { x: (wb.x + cb.x) / 2, y: cb.y + cb.height / 2 }
	})
}

/**
 * Record every native dialog the page raises and answer it. `control.accept`
 * decides the answer, so one test can first Cancel ("keep me here") and then OK
 * ("yes, discard") without re-wiring the listener.
 *
 * @param {import('@playwright/test').Page} page
 * @return {{messages: string[], accept: boolean}} live record + the answer switch
 */
function trackDialogs(page) {
	const control = { messages: [], accept: false }
	page.on('dialog', async (dialog) => {
		control.messages.push(dialog.message())
		if (control.accept) {
			await dialog.accept()
		} else {
			await dialog.dismiss()
		}
	})
	return control
}

test.describe('Unsaved changes are confirmed before leaving a card (#10069)', () => {
	const state = { boardId: 0, stackId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Unsaved-Guard E2E' })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Do' })
		state.stackId = stack.id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	async function makeCard(title) {
		const card = await api.post('/cards', { stackId: state.stackId, title })
		return {
			id: card.id,
			url: `${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${card.id}`,
		}
	}

	async function openCard(page, card) {
		await page.setViewportSize({ width: 1280, height: 900 })
		await ncLogin(page)
		await page.goto(card.url)
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })
	}

	test('a description draft: Cancel keeps the card open with the text, OK discards and closes', async ({ page }) => {
		const card = await makeCard('Description draft card')
		const dialogs = trackDialogs(page)
		await openCard(page, card)

		await page.locator('.card-modal__desc-placeholder').click()
		const prose = descProse(page)
		await expect(prose).toBeVisible({ timeout: 8_000 })
		await prose.click()
		await page.keyboard.type('half-written description')
		await expect(prose).toContainText('half-written description')

		// Click the backdrop → the guard must ask before losing the draft.
		dialogs.accept = false
		let pt = await backdropPoint(page)
		await page.mouse.click(pt.x, pt.y)

		await expect.poll(() => dialogs.messages.length, { timeout: 8_000 }).toBe(1)
		expect(dialogs.messages[0]).toContain('unsaved changes')

		// Cancelled → still on the card, editor still open, draft untouched.
		await expect(page).toHaveURL(new RegExp(`/card/${card.id}`))
		await expect(page.locator('.card-modal')).toBeVisible()
		await expect(descProse(page)).toContainText('half-written description')

		// Confirmed → the card closes and the draft is gone.
		dialogs.accept = true
		pt = await backdropPoint(page)
		await page.mouse.click(pt.x, pt.y)

		await expect(page).not.toHaveURL(new RegExp(`/card/${card.id}`), { timeout: 8_000 })
		expect(dialogs.messages.length).toBe(2)
		// Nothing was written to the server.
		const fresh = await api.get(`/cards/${card.id}`)
		expect(fresh.description || '').toBe('')
	})

	test('a reply draft is confirmed too (the owner named replies explicitly)', async ({ page }) => {
		const card = await makeCard('Reply draft card')
		await api.post(`/cards/${card.id}/comments`, { body: 'Thread to answer' })
		const dialogs = trackDialogs(page)
		await openCard(page, card)

		const replyBtn = page.locator('.card-modal__comment-group > .card-modal__comment .card-modal__comment-link-btn').first()
		await expect(replyBtn).toBeVisible({ timeout: 10_000 })
		await replyBtn.click()

		const prose = replyProse(page)
		await expect(prose).toBeVisible({ timeout: 8_000 })
		await prose.click()
		await page.keyboard.type('half-written reply')
		await expect(prose).toContainText('half-written reply')

		dialogs.accept = false
		let pt = await backdropPoint(page)
		await page.mouse.click(pt.x, pt.y)

		await expect.poll(() => dialogs.messages.length, { timeout: 8_000 }).toBe(1)
		expect(dialogs.messages[0]).toContain('unsaved changes')
		await expect(page).toHaveURL(new RegExp(`/card/${card.id}`))
		await expect(replyProse(page)).toContainText('half-written reply')

		dialogs.accept = true
		pt = await backdropPoint(page)
		await page.mouse.click(pt.x, pt.y)
		await expect(page).not.toHaveURL(new RegExp(`/card/${card.id}`), { timeout: 8_000 })

		// The reply was never posted.
		const comments = await api.get(`/cards/${card.id}/comments`)
		expect(comments.length).toBe(1)
	})

	// The regression that would make the guard hated: prompting on a card nobody
	// typed into. Opening the description editor and the reply box WITHOUT typing
	// must still count as clean.
	test('closing a card with nothing typed never prompts', async ({ page }) => {
		const card = await makeCard('Untouched card')
		await api.post(`/cards/${card.id}/comments`, { body: 'Just reading' })
		const dialogs = trackDialogs(page)
		await openCard(page, card)

		// Merely opening the editors is not "unsaved work".
		await page.locator('.card-modal__desc-placeholder').click()
		await expect(descProse(page)).toBeVisible({ timeout: 8_000 })
		const replyBtn = page.locator('.card-modal__comment-group > .card-modal__comment .card-modal__comment-link-btn').first()
		await expect(replyBtn).toBeVisible({ timeout: 10_000 })
		await replyBtn.click()
		await expect(replyProse(page)).toBeVisible({ timeout: 8_000 })

		// Escape at the card root closes it, silently.
		dialogs.accept = true
		const pt = await backdropPoint(page)
		await page.mouse.click(pt.x, pt.y)

		await expect(page).not.toHaveURL(new RegExp(`/card/${card.id}`), { timeout: 8_000 })
		expect(dialogs.messages).toEqual([])
	})

	// Tab close / reload leaves the app entirely, where only the browser's own
	// beforeunload prompt is available. It must fire with a draft…
	test('reloading with a draft raises the browser leave prompt', async ({ page }) => {
		const card = await makeCard('Reload card')
		const dialogs = trackDialogs(page)
		dialogs.accept = true
		await openCard(page, card)

		await page.locator('.card-modal__desc-placeholder').click()
		await expect(descProse(page)).toBeVisible({ timeout: 8_000 })
		await descProse(page).click()
		await page.keyboard.type('typed then reloaded')

		await page.reload()
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })
		expect(dialogs.messages.length).toBe(1)
	})

	// …and must stay silent on a clean card.
	test('reloading a card with no draft does not raise the browser leave prompt', async ({ page }) => {
		const card = await makeCard('Clean reload card')
		const dialogs = trackDialogs(page)
		dialogs.accept = true
		await openCard(page, card)

		await page.reload()
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })
		expect(dialogs.messages).toEqual([])
	})

	// The same unkeyed-<router-view> reuse the description state already guarded
	// against: comment drafts survived a card→card switch and showed up in the
	// next card's composer / reply box, one Post away from the wrong discussion.
	test('a draft never leaks into the next card opened', async ({ page }) => {
		const cardA = await makeCard('Leak source A')
		const cardB = await makeCard('Leak target B')
		await api.post(`/cards/${cardA.id}/comments`, { body: 'Thread on A' })
		await api.post(`/cards/${cardB.id}/comments`, { body: 'Thread on B' })
		const dialogs = trackDialogs(page)
		await openCard(page, cardA)

		// A new-thread draft AND a reply draft on card A.
		const replyBtnA = page.locator('.card-modal__comment-group > .card-modal__comment .card-modal__comment-link-btn').first()
		await expect(replyBtnA).toBeVisible({ timeout: 10_000 })
		await replyBtnA.click()
		await expect(replyProse(page)).toBeVisible({ timeout: 8_000 })
		await replyProse(page).click()
		await page.keyboard.type('reply meant for A')

		await composerProse(page).click()
		await page.keyboard.type('thread meant for A')
		await expect(composerProse(page)).toContainText('thread meant for A')

		// Card→card navigation (same route record, so the component is REUSED).
		await page.goto(cardB.url)
		await expect(page.locator('.card-modal__title')).toHaveText('Leak target B', { timeout: 15_000 })
		await page.waitForSelector('.card-modal__comment', { timeout: 10_000 })

		// The new-thread composer on B is empty…
		await expect(composerProse(page)).toHaveText('')
		// …no reply box is left open…
		await expect(page.locator('.card-modal__reply-compose')).toHaveCount(0)
		// …and opening B's own reply box starts blank.
		const replyBtnB = page.locator('.card-modal__comment-group > .card-modal__comment .card-modal__comment-link-btn').first()
		await replyBtnB.click()
		await expect(replyProse(page)).toBeVisible({ timeout: 8_000 })
		await expect(replyProse(page)).toHaveText('')

		// Card→card is not a dismissal, so it must not have prompted either.
		expect(dialogs.messages).toEqual([])
	})
})
