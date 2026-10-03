// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

const MAX = 4000

async function openGeneralSettings(page) {
	// Board settings lives in the consolidated ⋯ More overflow menu.
	await page.getByRole('button', { name: 'More' }).click()
	await page.getByRole('menuitem', { name: /board settings/i }).click()
	await page.getByRole('tab', { name: /general/i }).click()
	await expect(page.locator('#bs-pane-general')).toBeVisible()
}

async function boardDescription(boardId) {
	const board = await api.get(`/boards/${boardId}`)
	return board.board.description
}

// #173 — a board carries a free-text description saying what it is FOR, set in
// board settings, read by every member from the ⓘ button beside the board name,
// and rideable by an agent over the MCP (it is part of the board payload).
test.describe('Board description (#173)', () => {
	const state = { boardId: 0, boardUrl: '' }
	const DESCRIPTION = 'Support escalations only. **One card per customer.**'

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Board-Description E2E' })
		state.boardId = board.id
		await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('sets a description in board settings and reads it back from the ⓘ button', async ({ page }) => {
		// A fresh board has none, and the button is not rendered at all — a board
		// without a description keeps exactly the toolbar it had.
		await api.patch(`/boards/${state.boardId}`, { description: '' })
		expect(await boardDescription(state.boardId)).toBeFalsy()

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await expect(page.locator('[data-test="board-description-btn"]')).toHaveCount(0)

		await openGeneralSettings(page)
		await page.locator('[data-test="board-description-input"]').fill(DESCRIPTION)
		// Wait for the PATCH so the assertions below are not racing an in-flight
		// request on slow infra.
		const [patch] = await Promise.all([
			page.waitForResponse(
				(r) => /\/api\/boards\/\d+$/.test(r.url()) && r.request().method() === 'PATCH',
				{ timeout: 15_000 },
			),
			page.locator('[data-test="board-description-save"]').click(),
		])
		expect(patch.ok()).toBeTruthy()

		// Persisted server-side — this is the same payload kanso_get_board
		// returns, so the MCP sees it too …
		await expect.poll(() => boardDescription(state.boardId)).toBe(DESCRIPTION)

		// … and the ⓘ button appears beside the board name, with the description
		// as its hover text, and opens the note on click with the markdown
		// RENDERED — the ** is bold, not two asterisks.
		const button = page.locator('[data-test="board-description-btn"]')
		await expect(button).toBeVisible()
		await expect(button).toHaveAttribute('title', /Support escalations only\./)

		await button.click()
		const body = page.locator('[data-test="board-description"]')
		await expect(body).toBeVisible()
		await expect(body).toContainText('Support escalations only.')
		await expect(body.locator('strong')).toHaveText('One card per customer.')
		await expect(body).not.toContainText('**')

		// Escape closes it, and nothing is left behind on the board.
		await page.keyboard.press('Escape')
		await expect(body).toHaveCount(0)

		// It survives a reload (the server is the source of truth) and the
		// settings field is seeded with the stored value.
		await page.reload()
		await expect(page.locator('[data-test="board-description-btn"]'))
			.toBeVisible({ timeout: 15_000 })
		await openGeneralSettings(page)
		await expect(page.locator('[data-test="board-description-input"]')).toHaveValue(DESCRIPTION)
	})

	test('costs the board no vertical space when closed', async ({ page }) => {
		// The whole point of the modal (#173 feedback): a board with a description
		// gives the columns as much room as a board without one. Measured against
		// the same board with and without a description, so this cannot pass by
		// measuring nothing.
		//
		// Both measurements wait for the SAME settled state — a real column
		// rendered (`.board-view__stacks-wrap` exists during the skeleton phase
		// too, at a different height) — and compare with a 2px tolerance rather
		// than for exact equality. Exact equality is the wrong claim: it made
		// this test fail twice on a CI runner that was running ~3x slow, where
		// the first measurement landed mid-layout. 2px is subpixel noise; the
		// strip this test exists to forbid was ~38px, so the assertion still
		// fails loudly if one ever comes back.
		const settledStacksHeight = async () => {
			await expect(page.locator('.stack-column').first()).toBeVisible({ timeout: 15_000 })
			return (await page.locator('.board-view__stacks-wrap').boundingBox()).height
		}

		await api.patch(`/boards/${state.boardId}`, { description: '' })
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await expect(page.locator('[data-test="board-description-btn"]')).toHaveCount(0)
		const without = await settledStacksHeight()

		await api.patch(`/boards/${state.boardId}`, { description: '## Rules\n\nLine one.\n\nLine two.' })
		await page.reload()
		await expect(page.locator('[data-test="board-description-btn"]'))
			.toBeVisible({ timeout: 15_000 })
		const withDescription = await settledStacksHeight()

		expect(Math.abs(withDescription - without)).toBeLessThanOrEqual(2)
	})

	test('keeps blank lines and block formatting as written', async ({ page }) => {
		// The feedback that moved this into a modal: the old collapsed strip
		// flattened everything onto one line. Nothing flattens the note now — a
		// multi-paragraph description renders as separate blocks.
		await api.patch(`/boards/${state.boardId}`, {
			description: '# Scope\n\nFirst paragraph.\n\nSecond paragraph.\n\n- one\n- two',
		})

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.locator('[data-test="board-description-btn"]').click({ timeout: 15_000 })

		const body = page.locator('[data-test="board-description"]')
		await expect(body.locator('h1')).toHaveText('Scope')
		await expect(body.locator('p')).toHaveCount(2)
		await expect(body.locator('li')).toHaveCount(2)
		// Two paragraphs really are two lines on screen, not one run of text.
		const [first, second] = await body.locator('p').all()
		const a = await first.boundingBox()
		const b = await second.boundingBox()
		expect(b.y).toBeGreaterThan(a.y + a.height - 1)
	})

	test('renders markdown but never user-supplied markup', async ({ page }) => {
		// The description is rendered through the shared sanitising renderer, so
		// markup named in it is TEXT: nothing it asks for is inserted into the
		// page. This is the property that makes a rendered field safe — if the
		// modal ever stops going through renderMarkdown, this fails.
		const MARKUP = '<img src=x onerror=alert(1)> <script>alert(2)</script> plain words'
		await api.patch(`/boards/${state.boardId}`, { description: MARKUP })

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.locator('[data-test="board-description-btn"]').click({ timeout: 15_000 })

		const body = page.locator('[data-test="board-description"]')
		await expect(body).toContainText('plain words')
		expect(await body.locator('img').count()).toBe(0)
		expect(await body.locator('script').count()).toBe(0)
		// The literal characters survive as text, so nothing was silently eaten.
		await expect(body).toContainText('onerror=alert(1)')
	})

	test('a long description scrolls inside the modal', async ({ page }) => {
		const LONG = '## House rules\n\n'
			+ Array.from({ length: 60 }, (_, i) => `- Rule ${i + 1} of the house rules.`).join('\n')
		await api.patch(`/boards/${state.boardId}`, { description: LONG })

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.locator('[data-test="board-description-btn"]').click({ timeout: 15_000 })

		// Every rule is in the document — nothing is truncated away …
		await expect(page.locator('[data-test="board-description"] li')).toHaveCount(60)
		// … and the long note scrolls inside its own box instead of growing the
		// dialog past the viewport.
		const box = page.locator('.board-description')
		const m = await box.evaluate((el) => ({
			client: el.clientHeight,
			scroll: el.scrollHeight,
			viewport: window.innerHeight,
		}))
		expect(m.scroll).toBeGreaterThan(m.client)
		expect(m.client).toBeLessThanOrEqual(m.viewport)
	})

	test('a manager can jump from the note to the field that sets it', async ({ page }) => {
		await api.patch(`/boards/${state.boardId}`, { description: DESCRIPTION })

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.locator('[data-test="board-description-btn"]').click({ timeout: 15_000 })
		await page.locator('[data-test="board-description-edit"]').click()

		// The note closes and board settings opens on GENERAL — the section the
		// field is in, not the Labels section the gear opens — with the field
		// seeded. Asserting the pane is visible is the part that matters: the
		// input is in the DOM on every tab, so a value check alone would pass
		// while the user landed somewhere else.
		await expect(page.locator('[data-test="board-description"]')).toHaveCount(0)
		await expect(page.locator('#bs-pane-general')).toBeVisible({ timeout: 15_000 })
		await expect(page.locator('[data-test="board-description-input"]')).toHaveValue(DESCRIPTION)
	})

	test('… and lands on General even when settings is already open elsewhere', async ({ page }) => {
		// The drawer is non-blocking and the ⓘ sits above it, so the note's Edit
		// shortcut can fire while board settings is ALREADY mounted on another
		// section. The panel keeps its tab state across that, so asking for
		// General has to be honoured on a live component, not only at mount.
		await api.patch(`/boards/${state.boardId}`, { description: DESCRIPTION })

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Open settings the normal way: the gear, which lands on Labels.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await expect(page.locator('#bs-pane-labels')).toBeVisible()

		// Now go through the note's Edit shortcut, with the panel still open.
		await page.locator('[data-test="board-description-btn"]').click()
		await page.locator('[data-test="board-description-edit"]').click()

		await expect(page.locator('#bs-pane-general')).toBeVisible()
		await expect(page.locator('[data-test="board-description-input"]')).toHaveValue(DESCRIPTION)

		// And AGAIN, after the user navigates away by hand. This is the path a
		// declarative "initial tab" prop cannot serve: the second request names
		// the same section as the first, so there is no prop CHANGE to react to
		// and a watcher never fires.
		await page.locator('#bs-rail-tab-labels').click()
		await expect(page.locator('#bs-pane-labels')).toBeVisible()
		await page.locator('[data-test="board-description-btn"]').click()
		await page.locator('[data-test="board-description-edit"]').click()
		await expect(page.locator('#bs-pane-general')).toBeVisible()
	})

	test('board shortcuts do not reach through the open note', async ({ page }) => {
		// The note is a real modal, so the board's single-key shortcuts must not
		// fire behind it. Space is the one that bit: the keydown handler's preview
		// branch runs before its overlay guard, so Space both swallowed the
		// focused button's activation and toggled a card preview behind the
		// dialog.
		await api.patch(`/boards/${state.boardId}`, { description: DESCRIPTION })

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.locator('[data-test="board-description-btn"]').click({ timeout: 15_000 })
		await expect(page.locator('[data-test="board-description"]')).toBeVisible()

		// Space: no card preview opens behind the note, and the note stays up.
		await page.keyboard.press(' ')
		await expect(page.locator('.card-preview')).toHaveCount(0)
		await expect(page.locator('[data-test="board-description"]')).toBeVisible()

		// '?' (shortcuts overlay) is likewise inert while the note is open.
		await page.keyboard.press('?')
		await expect(page.locator('.shortcuts-modal')).toHaveCount(0)
		await expect(page.locator('[data-test="board-description"]')).toBeVisible()

		// Escape still closes the note itself.
		await page.keyboard.press('Escape')
		await expect(page.locator('[data-test="board-description"]')).toHaveCount(0)
	})

	// Each of these sets its own precondition through the API rather than leaning
	// on the browser tests above, so a failure there cannot cascade into them.
	test('rejects a description past the 4000-character cap', async () => {
		await api.patch(`/boards/${state.boardId}`, { description: DESCRIPTION })

		const res = await api.raw('PATCH', `/boards/${state.boardId}`, { description: 'x'.repeat(MAX + 1) })
		expect(res.status).toBe(400)
		// …and the stored description is untouched by the refused write.
		expect(await boardDescription(state.boardId)).toBe(DESCRIPTION)

		// Exactly at the cap is accepted.
		const atCap = 'y'.repeat(MAX)
		await api.patch(`/boards/${state.boardId}`, { description: atCap })
		expect(await boardDescription(state.boardId)).toBe(atCap)
	})

	test('clears the description with an empty value', async () => {
		await api.patch(`/boards/${state.boardId}`, { description: DESCRIPTION })
		expect(await boardDescription(state.boardId)).toBe(DESCRIPTION)

		await api.patch(`/boards/${state.boardId}`, { description: '' })
		expect(await boardDescription(state.boardId)).toBeNull()
	})

	test('stays out of the boards LIST payload', async () => {
		// The list is one row per board; a description belongs to the board's own
		// payload, which the test above asserts.
		await api.patch(`/boards/${state.boardId}`, { description: DESCRIPTION })
		const list = await api.get('/boards')
		const row = list.find((b) => b.id === state.boardId)
		expect(row).toBeTruthy()
		expect('description' in row).toBe(false)
	})
})

// #180 — the board-settings panel can be maximized, because its multi-field
// rows (email intake, the forge webhooks) are unreadable in a narrow panel.
test.describe('Board settings panel size (#180)', () => {
	const state = { boardId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Board-Settings-Size E2E' })
		state.boardId = board.id
		await api.post('/stacks', { boardId: board.id, title: 'To Do' })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('maximizing widens the panel and the choice is remembered', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		await openGeneralSettings(page)
		const panel = page.locator('.bs-modal')
		const docked = (await panel.boundingBox()).width

		await page.locator('[data-test="board-settings-maximize"]').click()
		await expect
			.poll(async () => (await panel.boundingBox()).width)
			.toBeGreaterThan(docked)

		// Remembered: re-opening the panel (even after a reload) keeps the
		// maximized size, so the choice is made once rather than every visit.
		await page.reload()
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await openGeneralSettings(page)
		expect((await panel.boundingBox()).width).toBeGreaterThan(docked)

		// And it can be restored.
		await page.locator('[data-test="board-settings-maximize"]').click()
		await expect
			.poll(async () => (await panel.boundingBox()).width)
			.toBeLessThanOrEqual(docked)
	})
})
