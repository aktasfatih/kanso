// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #5894 — per-board switches for the BUILT-IN card sections.
//
// Every card used to show every section whether the team used it or not. A
// board manager can now switch five of them off (contacts, attachments, GitHub
// links, time tracking, cover colour) from board settings → Card fields.
//
// The guarantee this suite exists to protect: **hiding is not deleting.** A
// disabled section vanishes from the card modal AND from the tiles/list rows,
// but the attachment, time entry and cover colour behind it stay in the
// database and come back intact the moment the switch goes on again.

import { test, expect, api, ncLogin, BASE, currentAuth } from './helpers.js'

const API = BASE + '/index.php/apps/kanso/api'
const ROLE_IN_PROGRESS = 3

/** Uploads a small in-memory file as a card attachment (multipart). */
async function uploadFile(cardId, filename, content) {
	const form = new FormData()
	form.append('file', new Blob([content], { type: 'text/plain' }), filename)
	const r = await fetch(API + `/cards/${cardId}/attachments`, {
		method: 'POST',
		headers: { 'OCS-APIREQUEST': 'true', Authorization: currentAuth },
		body: form,
	})
	if (!r.ok) throw new Error(`attachment upload failed: ${r.status}`)
	return r.json()
}

// NcCheckboxRadioSwitch passes fallthrough attrs to its <input>, so the
// data-test attribute lands on the input itself; the visible, clickable part is
// the label text next to it.
const featureInput = (page, key) => page.locator(`input[data-test="builtin-${key}"]`)
const featureLabel = (page, label) => page.locator('[data-test="builtin-sections"]').getByText(label, { exact: true })

/** Opens board settings and lands on the Card fields pane. */
async function openCardFieldsPane(page, boardId) {
	await page.goto(`${BASE}/index.php/apps/kanso#/board/${boardId}`)
	await page.waitForSelector('.board-view__header', { timeout: 15_000 })
	await page.getByRole('button', { name: 'More' }).click()
	await page.getByRole('menuitem', { name: /board settings/i }).click()
	// The rail entry is "Card fields" — it covers the built-in sections as well
	// as the user-defined ones now.
	await page.getByRole('tab', { name: /card fields/i }).click()
	await expect(page.locator('#bs-pane-card-fields')).toBeVisible({ timeout: 8_000 })
}

const FILE_NAME = 'kept-on-purpose.txt'

test.describe('Built-in card sections (#5894)', () => {
	const state = { boardId: 0, otherBoardId: 0, todoStackId: 0, progStackId: 0, cardId: 0 }

	test.beforeAll(async () => {
		const stamp = Math.floor(Date.now() / 1000)
		const board = await api.post('/boards', { title: 'Card features ' + stamp })
		state.boardId = board.id
		state.todoStackId = (await api.post('/stacks', { boardId: board.id, title: 'To do' })).id
		state.progStackId = (await api.post('/stacks', { boardId: board.id, title: 'Doing' })).id
		await api.patch(`/stacks/${state.progStackId}`, { role: ROLE_IN_PROGRESS })
		// A second board that never touches these settings — proves the switches
		// are per board, not global.
		state.otherBoardId = (await api.post('/boards', { title: 'Card features control ' + stamp })).id
		const otherStack = await api.post('/stacks', { boardId: state.otherBoardId, title: 'To do' })
		await api.post('/cards', { stackId: otherStack.id, title: 'Untouched card' })

		// The card carries real data behind three of the five features, so a
		// later re-enable has something to restore.
		const card = await api.post('/cards', { stackId: state.todoStackId, title: 'Loaded card' })
		state.cardId = card.id
		await uploadFile(card.id, FILE_NAME, 'do not delete me')
		await api.post(`/cards/${card.id}/time-entries`, { seconds: 3600, note: 'Manual entry' })
		await api.patch(`/cards/${card.id}`, { coverColor: '0082c9' })

		// A running timer, so the tile badge has something to show. Started the
		// only way the app starts one: an automation rule on column entry.
		await api.post(`/boards/${board.id}/automation-rules`, {
			trigger: 'card_entered_role', action: 'start_timer', params: { role: ROLE_IN_PROGRESS },
		})
		await api.post(`/cards/${card.id}/move`, { targetStackId: state.progStackId, afterCardId: null })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
		if (state.otherBoardId) await api.delete(`/boards/${state.otherBoardId}`).catch(() => {})
	})

	test('every feature is on by default, and the payload says so', async () => {
		const board = await api.get(`/boards/${state.boardId}`)
		expect(board.board.cardFeatures).toEqual({
			contacts: true,
			attachments: true,
			github: true,
			timeTracking: true,
			coverColor: true,
		})
		// Sanity: the fixture data really is there.
		expect(board.cards.find((c) => c.id === state.cardId).timerRunning).toBe(true)
		expect(await api.get(`/cards/${state.cardId}/attachments`)).toHaveLength(1)
	})

	test('switching attachments and time tracking off hides them everywhere, and back on restores the data', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// --- Baseline: both sections and the tile badges are present ---
		const tile = page.locator('.card-tile', { hasText: 'Loaded card' })
		await expect(tile).toBeVisible({ timeout: 10_000 })
		await expect(tile.locator('.card-tile__timer-running')).toBeVisible()
		await expect(tile.locator('.card-tile__cover')).toBeVisible()

		await tile.click()
		const modal = page.locator('.card-modal')
		await expect(modal).toBeVisible({ timeout: 10_000 })
		await expect(modal.getByText('Attachments', { exact: true })).toBeVisible()
		await expect(modal.getByText('Time tracking', { exact: true })).toBeVisible()
		await expect(modal.getByText(FILE_NAME)).toBeVisible()
		await page.keyboard.press('Escape')
		await expect(modal).toBeHidden({ timeout: 8_000 })

		// --- Switch attachments + time tracking off from board settings ---
		await openCardFieldsPane(page, state.boardId)
		await expect(featureInput(page, 'attachments')).toBeChecked()
		await expect(featureInput(page, 'timeTracking')).toBeChecked()
		await featureLabel(page, 'Attachments').click()
		await expect(featureInput(page, 'attachments')).not.toBeChecked({ timeout: 8_000 })
		await featureLabel(page, 'Time tracking').click()
		await expect(featureInput(page, 'timeTracking')).not.toBeChecked({ timeout: 8_000 })

		// Cover colour was NOT touched — one switch must not drag the others with it.
		await expect(featureInput(page, 'coverColor')).toBeChecked()

		// Close settings. No reload: the change rides the normal board payload.
		await page.keyboard.press('Escape')

		// --- Gone from the tile ---
		await expect(tile.locator('.card-tile__timer-running')).toHaveCount(0, { timeout: 10_000 })
		// The cover band stays: that feature is still on.
		await expect(tile.locator('.card-tile__cover')).toBeVisible()

		// --- Gone from the card modal ---
		await tile.click()
		await expect(modal).toBeVisible({ timeout: 10_000 })
		await expect(modal.getByText('Attachments', { exact: true })).toHaveCount(0)
		await expect(modal.getByText('Time tracking', { exact: true })).toHaveCount(0)
		await expect(modal.getByText(FILE_NAME)).toHaveCount(0)
		await page.keyboard.press('Escape')
		await expect(modal).toBeHidden({ timeout: 8_000 })

		// --- Hiding is not deleting: the rows are untouched on the server ---
		expect(await api.get(`/cards/${state.cardId}/attachments`)).toHaveLength(1)
		expect(await api.get(`/cards/${state.cardId}/time-entries`)).toHaveLength(1)

		// --- Now the other three, so all five switches have a UI-level guard ---
		await openCardFieldsPane(page, state.boardId)
		await featureLabel(page, 'Cover colour').click()
		await expect(featureInput(page, 'coverColor')).not.toBeChecked({ timeout: 8_000 })
		await featureLabel(page, 'GitHub links').click()
		await expect(featureInput(page, 'github')).not.toBeChecked({ timeout: 8_000 })
		await featureLabel(page, 'Contacts').click()
		await expect(featureInput(page, 'contacts')).not.toBeChecked({ timeout: 8_000 })
		await page.keyboard.press('Escape')

		// The cover band is gone from the tile, and the picker + the GitHub and
		// contact controls are gone from the modal.
		await expect(tile.locator('.card-tile__cover')).toHaveCount(0, { timeout: 10_000 })
		await tile.click()
		await expect(modal).toBeVisible({ timeout: 10_000 })
		await expect(modal.getByText('Cover', { exact: true })).toHaveCount(0)
		await expect(modal.getByText('GitHub', { exact: true })).toHaveCount(0)
		await expect(modal.getByText('Link contact', { exact: true })).toHaveCount(0)
		await page.keyboard.press('Escape')
		await expect(modal).toBeHidden({ timeout: 8_000 })

		// The cover colour itself was never cleared.
		expect((await api.get(`/cards/${state.cardId}`)).coverColor).toBe('0082c9')

		// --- Switch everything back on: it all returns exactly as it was ---
		await openCardFieldsPane(page, state.boardId)
		for (const [key, label] of [
			['attachments', 'Attachments'],
			['timeTracking', 'Time tracking'],
			['coverColor', 'Cover colour'],
			['github', 'GitHub links'],
			['contacts', 'Contacts'],
		]) {
			await featureLabel(page, label).click()
			await expect(featureInput(page, key)).toBeChecked({ timeout: 8_000 })
		}
		await page.keyboard.press('Escape')

		await expect(tile).toBeVisible({ timeout: 10_000 })
		await expect(tile.locator('.card-tile__cover')).toBeVisible({ timeout: 10_000 })
		await tile.click()
		await expect(modal).toBeVisible({ timeout: 10_000 })
		await expect(modal.getByText('Attachments', { exact: true })).toBeVisible({ timeout: 8_000 })
		await expect(modal.getByText('Time tracking', { exact: true })).toBeVisible()
		await expect(modal.getByText('GitHub', { exact: true })).toBeVisible()
		// The file that was uploaded before anything was switched off is still listed.
		await expect(modal.getByText(FILE_NAME)).toBeVisible()
	})

	test('the switches are per board — a sibling board is unaffected', async () => {
		// Turn GitHub off on the first board only.
		await api.patch(`/boards/${state.boardId}`, { cardFeatures: { github: false } })
		const boardA = await api.get(`/boards/${state.boardId}`)
		const boardB = await api.get(`/boards/${state.otherBoardId}`)
		expect(boardA.board.cardFeatures.github).toBe(false)
		expect(boardB.board.cardFeatures.github).toBe(true)
		// And the other four on board A are still on.
		expect(boardA.board.cardFeatures.attachments).toBe(true)
		expect(boardA.board.cardFeatures.timeTracking).toBe(true)
		await api.patch(`/boards/${state.boardId}`, { cardFeatures: { github: true } })
	})
})

test.describe('Built-in card sections — non-manager', () => {
	// A second identity logs in for real, so this must not inherit the shared
	// authenticated admin storageState (see the e2e storageState guard).
	test.use({ storageState: { cookies: [], origins: [] } })

	const state = { boardId: 0 }

	test.beforeAll(async ({ peer }) => {
		const board = await api.post('/boards', { title: 'Card features acl ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		await api.post('/stacks', { boardId: board.id, title: 'To do' })
		// READ|EDIT = 3 — a member who can edit cards but not manage the board.
		await api.post(`/boards/${board.id}/acl`, {
			participant: peer.user,
			participantType: 'user',
			permission: 3,
		})
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('a member without MANAGE sees the switches read-only', async ({ browser, peer }) => {
		const ctx = await browser.newContext()
		try {
			const page = await ctx.newPage()
			await ncLogin(page, { user: peer.user, pass: peer.pass })
			await openCardFieldsPane(page, state.boardId)
			// Visible (so the member can see how the board is configured) but not
			// editable — same shape as the label / review-type editors.
			await expect(featureLabel(page, 'Attachments')).toBeVisible()
			await expect(featureInput(page, 'attachments')).toBeDisabled()
			await expect(featureInput(page, 'attachments')).toBeChecked()
		} finally {
			await ctx.close()
		}

		// And the API refuses the write too - the read-only rendering is not the
		// only thing standing between an EDIT member and the board's settings.
		const denied = await peer.api.raw('PATCH', `/boards/${state.boardId}`, { cardFeatures: { attachments: false } })
		expect(denied.status).toBe(403)
		expect((await api.get(`/boards/${state.boardId}`)).board.cardFeatures.attachments).toBe(true)
	})
})
