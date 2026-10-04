// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, boardUrl, BASE } from './helpers.js'

test.describe('Labels', () => {
	const state = { boardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Labels Test Board') {
				await api.delete(`/boards/${b.id}`)
			}
		}
		const board = await api.post('/boards', { title: 'Labels Test Board' })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'S1' })
		await api.post('/cards', { stackId: stack.id, title: 'Card X' })
		state.boardUrl = boardUrl(board.id)
	})

	test('colored label created via the settings panel renders colored everywhere', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		// Board settings now lives in the consolidated ⋯ More overflow menu.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()

		// Pick the first preset (red e74c3c), name it, create it
		await page.getByRole('button', { name: /pick color for new label/i }).click()
		await page.locator('.label-settings__color-option').first().click()
		await page.getByLabel(/new label name/i).fill('ColoredE2E')
		await page.getByRole('button', { name: /create label/i }).click()

		// Created without error, swatch actually painted red
		const item = page.locator('.label-settings__item', { hasText: 'ColoredE2E' })
		await expect(item).toHaveCount(1)
		await expect(page.locator('.label-settings__error')).toHaveCount(0)
		const swatchBg = await item.locator('.label-settings__swatch')
			.evaluate((el) => getComputedStyle(el).backgroundColor)
		expect(swatchBg).toBe('rgb(231, 76, 60)') // preset e74c3c

		// Server stored the bare-hex color
		const boardPayload = await api.get(`/boards/${state.boardId}`)
		const label = boardPayload.labels.find((l) => l.title === 'ColoredE2E')
		expect(label?.color).toBe('e74c3c')

		// Filter button is visible in the header now that the board has a label
		await page.keyboard.press('Escape')
		const filterBtn = page.locator('.board-view__filter-menu button', { hasText: /filter/i })
		await expect(filterBtn).toHaveCount(1)

		// Opening the filter popover and drilling into Labels shows a row for the
		// new label (progressive drill-in, #3785).
		await filterBtn.click()
		await page.locator('.board-filter-bar__dim-row[data-dim="labels"]').click()
		await expect(page.locator('.board-filter-bar__label-item .board-filter-bar__opt-text', { hasText: 'ColoredE2E' })).toHaveCount(1)
	})

	// #10920 — the label twin of card-multi-assign.spec.js's "a pick taken while
	// the previous write is in flight is not lost" (#10799).
	//
	// The label picker stays open across picks, so adding three labels is three
	// quick clicks. Every label write is held for 800ms here, so the second and
	// third click are both made while the previous one is still on the wire —
	// the window in which the modal's single-flight guard used to `return` early
	// and send nothing at all. With no added latency the writes usually answer
	// between clicks, which is why this test adds the latency rather than
	// hoping for it.
	//
	// It asserts the REQUESTS as well as the result: a cache-only assertion can
	// be satisfied by an optimistic patch no write ever backed.
	test('a label picked while the previous write is in flight is not lost', async ({ page }) => {
		const stack = await api.post('/stacks', { boardId: state.boardId, title: 'Slow' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Slow link labels' })
		const names = ['QueueOne', 'QueueTwo', 'QueueThree']
		for (const title of names) {
			await api.post('/labels', { boardId: state.boardId, title })
		}

		const sent = []
		page.on('request', (r) => {
			if (r.method() === 'PUT' && /\/cards\/\d+\/labels\/\d+$/.test(r.url())) sent.push(r.url())
		})
		await page.route(/\/cards\/\d+\/labels\/\d+$/, async (route) => {
			await new Promise((resolve) => setTimeout(resolve, 800))
			await route.continue()
		})

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${card.id}`)
		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		await page.locator('.card-modal__attr button[data-pill="label"]').first().click()
		const popover = page.locator('.card-modal__attr .card-modal__popover')
		await expect(popover).toBeVisible()

		// Three clicks with nothing awaited between them but the first row's own
		// chip — deliberately the shape a user produces.
		await popover.locator('.card-modal__label-toggle', { hasText: names[0] }).click()
		await expect(page.locator('.card-modal__label-chip', { hasText: names[0] })).toBeVisible()
		await popover.locator('.card-modal__label-toggle', { hasText: names[1] }).click()
		await popover.locator('.card-modal__label-toggle', { hasText: names[2] }).click()

		for (const title of names) {
			await expect(page.locator('.card-modal__label-chip', { hasText: title })).toBeVisible()
		}
		await expect.poll(async () => (await api.get(`/cards/${card.id}`)).labelIds.length).toBe(3)
		expect(sent.length, 'every pick must put a write on the wire, not just a chip on screen').toBe(3)
	})

	test('inline create from the card view: new label is assigned + present on the board', async ({ page }) => {
		// Fresh card to work in.
		const stack = await api.post('/stacks', { boardId: state.boardId, title: 'Inline' })
		const card = await api.post('/cards', { stackId: stack.id, title: 'Inline Label Card' })
		const cardUrl = `${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${card.id}`

		await ncLogin(page)
		await page.goto(cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		// Open the label popover (admin owns the board → MANAGE, so the create row shows).
		await page.locator('.card-modal__attr button', { hasText: 'Label' }).first().click()
		const createRow = page.locator('.card-modal__label-create')
		await expect(createRow).toBeVisible()

		// Pick a colour preset, name it, create.
		await createRow.locator('.card-modal__label-swatch').click()
		await createRow.locator('.card-modal__label-color-option').first().click()
		await createRow.locator('.card-modal__label-create-input').fill('InlineFromCard')
		await createRow.locator('.card-modal__label-create-btn').click()

		// The new label is assigned to the card (chip appears in the attribute bar).
		await expect(
			page.locator('.card-modal__label-chip', { hasText: 'InlineFromCard' }),
		).toBeVisible()
		await expect(page.locator('.card-modal__save-error')).toHaveCount(0)

		// And it now exists on the board (visible in Board settings / everywhere).
		const boardPayload = await api.get(`/boards/${state.boardId}`)
		const created = boardPayload.labels.find((l) => l.title === 'InlineFromCard')
		expect(created).toBeTruthy()
		expect(created.color).toBe('e74c3c') // first preset
	})
})
