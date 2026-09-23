// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

async function stackDescription(boardId, stackId) {
	const board = await api.get(`/boards/${boardId}`)
	return board.stacks.find((s) => s.id === stackId)?.description
}

// #10474 — a column carries a free-text description saying what belongs in it,
// readable by the people on the board (the header subtitle) and by an agent over
// the MCP (it rides the board payload).
test.describe('Column description', () => {
	const state = { boardId: 0, stackId: 0, boardUrl: '' }
	const DESCRIPTION = 'Only cards with a reproducer land here.'

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Column-Description E2E' })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'To Do' })).id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('sets a description from the column menu and reads it back on the header', async ({ page }) => {
		// A fresh column has none.
		expect(await stackDescription(state.boardId, state.stackId)).toBeFalsy()

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column__header', { timeout: 15_000 })

		// Open the column ⋯ menu. NcActions teleports its popover and animates it
		// in, so wait for the SHOWN popover before typing into anything inside it.
		await page.locator('.stack-column__actions button').first().click()
		const field = page
			.locator('.v-popper__popper--shown')
			.getByRole('textbox', { name: /column description/i })
			.first()
		await expect(field).toBeVisible()

		await field.fill(DESCRIPTION)

		// The field is a TEXTAREA (a description may run to a couple of lines), so
		// Enter inserts a newline — the arrow beside it is what submits the form.
		const submit = page.locator('.v-popper__popper--shown .action-text-editable__label').first()
		// Wait for the PATCH so the assertions below are not racing an in-flight
		// request on slow infra.
		const [patch] = await Promise.all([
			page.waitForResponse(
				(r) => /\/api\/stacks\/\d+/.test(r.url()) && r.request().method() === 'PATCH',
				{ timeout: 15_000 },
			),
			submit.click(),
		])
		expect(patch.ok()).toBeTruthy()

		// Persisted server-side — this is the same payload kanso_get_board returns,
		// so the MCP sees it too …
		await expect
			.poll(() => stackDescription(state.boardId, state.stackId))
			.toBe(DESCRIPTION)

		// … and a human reads it straight off the column header.
		const subtitle = page.locator('.stack-column__description').first()
		await expect(subtitle).toBeVisible()
		await expect(subtitle).toHaveText(DESCRIPTION)

		// PLAIN TEXT, never markup: markup set as a description is shown as the
		// characters typed, and nothing it names is ever inserted into the page.
		// This is the property that makes the field safe — if the subtitle ever
		// switches to v-html, this fails.
		const MARKUP = '<img src=x onerror=alert(1)> **not bold**'
		await api.patch(`/stacks/${state.stackId}`, { description: MARKUP })
		await page.reload()
		await expect(page.locator('.stack-column__description').first())
			.toHaveText(MARKUP, { timeout: 15_000 })
		expect(await page.locator('.stack-column__description img').count()).toBe(0)
	})

	// Each of these sets its own precondition through the API rather than leaning
	// on the browser test above, so a failure there cannot cascade into them.
	test('rejects a description past the 2000-character cap', async () => {
		await api.patch(`/stacks/${state.stackId}`, { description: DESCRIPTION })

		const res = await api.raw('PATCH', `/stacks/${state.stackId}`, { description: 'x'.repeat(2001) })
		expect(res.status).toBe(400)
		// …and the stored description is untouched by the refused write.
		expect(await stackDescription(state.boardId, state.stackId)).toBe(DESCRIPTION)

		// Exactly at the cap is accepted.
		const atCap = 'y'.repeat(2000)
		await api.patch(`/stacks/${state.stackId}`, { description: atCap })
		expect(await stackDescription(state.boardId, state.stackId)).toBe(atCap)
	})

	test('clears the description with an empty value', async () => {
		await api.patch(`/stacks/${state.stackId}`, { description: DESCRIPTION })
		expect(await stackDescription(state.boardId, state.stackId)).toBe(DESCRIPTION)

		await api.patch(`/stacks/${state.stackId}`, { description: '' })
		expect(await stackDescription(state.boardId, state.stackId)).toBeNull()
	})
})
