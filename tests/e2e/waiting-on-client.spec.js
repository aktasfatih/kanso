// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// Derived "waiting on client" status (#3746): a card with ≥1 open step whose
// FROZEN assigned_role is 'external' is waiting on the client - surfaced as an
// amber tile chip and a board filter facet, and derived purely from step state
// (no stored flag). The `tester` user (provisioned by dev/setup.sh) is shared
// onto the board as an EXTERNAL member, so a step assigned to tester freezes
// role='external' and flips the wait state.
test.describe.serial('Waiting on client (#3746)', () => {
	const state = { boardId: 0, waitCardId: 0, plainCardId: 0, itemId: 0, boardUrl: '' }

	test.beforeAll(async ({ peer }) => {
		// Hermetic setup: tear down any prior run's board.
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Waiting On Client Board') {
				await api.delete(`/boards/${b.id}`)
			}
		}

		const board = await api.post('/boards', { title: 'Waiting On Client Board' })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Doing' })

		// The peer joins the CLIENT side (external) with READ | EDIT.
		await api.post(`/boards/${board.id}/acl`, {
			participant: peer.user,
			participantType: 'user',
			permission: 3,
			role: 'external',
		})

		// Card A gets an open step assigned to the external member; card B has
		// no steps at all (the "not waiting" control).
		const waitCard = await api.post('/cards', { stackId: stack.id, title: 'Ball With Client' })
		state.waitCardId = waitCard.id
		const plainCard = await api.post('/cards', { stackId: stack.id, title: 'Ball With Us' })
		state.plainCardId = plainCard.id

		const item = await api.post(`/cards/${waitCard.id}/checklist`, { title: 'Client signs contract' })
		state.itemId = item.id
		await api.post(`/checklist/${item.id}/assign`, { participant: peer.user })

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('board summary derives waitingOnExternal + waitingSince from step state (real SQL)', async () => {
		const payload = await api.get(`/boards/${state.boardId}`)
		const byId = Object.fromEntries(payload.cards.map((c) => [c.id, c]))

		const waiting = byId[state.waitCardId]
		if (!waiting) throw new Error('wait card missing from board payload')
		expect(waiting.waitingOnExternal).toBe(true)
		expect(waiting.waitingSince).toBeGreaterThan(0)

		const plain = byId[state.plainCardId]
		if (!plain) throw new Error('plain card missing from board payload')
		expect(plain.waitingOnExternal).toBe(false)
		expect(plain.waitingSince).toBeNull()
	})

	test('tile shows the amber chip; the waiting filter isolates the card; completing the step clears both', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		const waitTile = page.locator('.card-tile').filter({ hasText: 'Ball With Client' })
		const plainTile = page.locator('.card-tile').filter({ hasText: 'Ball With Us' })

		// Chip on the waiting card only.
		await expect(waitTile.locator('.card-tile__waiting')).toBeVisible({ timeout: 10_000 })
		await expect(waitTile.locator('.card-tile__waiting')).toContainText('Waiting on client')
		await expect(plainTile.locator('.card-tile__waiting')).toHaveCount(0)

		// ── Filter facet: "Waiting on client" hides the non-waiting card ──────
		// Drill into the "Client status" dimension (#3785) and pick the facet.
		await page.locator('.board-filter-bar__filter button').first().click()
		await page.locator('.board-filter-bar__dim-row[data-dim="waiting"]').click()
		await page.locator('.board-filter-bar__opt-text', { hasText: 'Waiting on client' }).click()
		await page.keyboard.press('Escape')
		await expect(page.locator('.card-tile__title', { hasText: 'Ball With Client' })).toHaveCount(1)
		await expect(page.locator('.card-tile__title', { hasText: 'Ball With Us' })).toHaveCount(0)

		// The facet rides the URL like every other dimension (shareable link).
		await expect.poll(() => page.url()).toContain('fw=waiting')

		// ...and the mirror facet ("Not waiting") flips the visible set.
		await page.locator('.board-filter-bar__filter button').first().click()
		await page.locator('.board-filter-bar__dim-row[data-dim="waiting"]').click()
		await page.locator('.board-filter-bar__opt-text', { hasText: 'Not waiting' }).click()
		await page.keyboard.press('Escape')
		await expect(page.locator('.card-tile__title', { hasText: 'Ball With Us' })).toHaveCount(1)
		await expect(page.locator('.card-tile__title', { hasText: 'Ball With Client' })).toHaveCount(0)

		// Clear the filter (the root panel's "Clear filters") so the chip-clearing
		// assertions see both cards.
		await page.locator('.board-filter-bar__filter button').first().click()
		await page.locator('.board-filter-bar__clear').click()
		await page.keyboard.press('Escape')
		await expect(page.locator('.card-tile__title', { hasText: 'Ball With Client' })).toHaveCount(1)

		// ── Complete the step server-side → the chip clears with NO write to the
		// card: the toggle's change row rides the delta-sync poll and the
		// re-serialized summary drops the derived flag. ──────────────────────────
		await api.patch(`/checklist/${state.itemId}`, { done: true })
		await expect(waitTile.locator('.card-tile__waiting')).toHaveCount(0, { timeout: 20_000 })

		// Server truth: the derived state is gone from the board payload too.
		const payload = await api.get(`/boards/${state.boardId}`)
		const card = payload.cards.find((c) => c.id === state.waitCardId)
		expect(card.waitingOnExternal).toBe(false)
		expect(card.waitingSince).toBeNull()

		// Re-opening the step brings the wait state (and chip) straight back -
		// appears/clears purely from step state.
		await api.patch(`/checklist/${state.itemId}`, { done: false })
		await expect(waitTile.locator('.card-tile__waiting')).toBeVisible({ timeout: 20_000 })
	})
})
