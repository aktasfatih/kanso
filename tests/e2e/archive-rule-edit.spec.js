// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, boardUrl } from './helpers.js'

/**
 * A saved auto-archive rule is editable in place — the "Add rule" form in board
 * settings doubles as its editor.
 *
 * The headline case is WIDENING: a rule pinned to one column re-scoped back to
 * the whole board. That PATCH only lands if the client sends `stackId: null` as
 * a PRESENT key (an omitted key means "leave the scope alone"), so only a real
 * UI round-trip proves the path.
 */
test.describe('Auto-archive rule editing (#10464)', () => {
	const state = { boardId: 0, doneStackId: 0, otherStackId: 0 }

	test.beforeAll(async () => {
		const board = await api.send('POST', '/boards', { title: 'ArchiveEdit ' + Date.now() })
		state.boardId = board.id
		state.doneStackId = (await api.send('POST', '/stacks', { boardId: board.id, title: 'Done' })).id
		state.otherStackId = (await api.send('POST', '/stacks', { boardId: board.id, title: 'Backlog' })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.send('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	/**
	 * Open board settings → Automation tab → expand the Auto-archive group, and
	 * return locators scoped to that group (the sibling automation groups carry
	 * identically-labelled buttons).
	 */
	async function openAutoArchive(page) {
		await ncLogin(page)
		await page.goto(boardUrl(state.boardId))
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /automation/i }).click()
		// The group is collapsed by default.
		await page.getByRole('button', { name: /auto-archive/i }).click()

		const group = page.locator('#bs-automation-auto-archive')
		const form = group.locator('.automation__create-form')
		await expect(form.locator(`#archive-scope-${state.boardId}`)).toBeVisible()
		return {
			group,
			form,
			desc: group.locator('.automation__rule-desc').first(),
			scope: form.locator(`#archive-scope-${state.boardId}`),
			condition: form.locator(`#archive-condition-${state.boardId}`),
			days: form.locator(`#archive-days-${state.boardId}`),
			edit: group.getByRole('button', { name: /^Edit$/ }).first(),
			save: form.getByRole('button', { name: /^Save rule$/ }),
			add: form.getByRole('button', { name: /^Add rule$/ }),
			cancel: form.getByRole('button', { name: /^Cancel$/ }),
		}
	}

	test('a column-scoped rule can be widened to the whole board, then narrowed back', async ({ page }) => {
		// A rule pinned to the "Done" column.
		const rule = await api.send('POST', `/boards/${state.boardId}/archive-rules`, {
			stackId: state.doneStackId,
			condition: 0,
			thresholdSeconds: 7 * 86400,
		})
		expect(rule.stackId).toBe(state.doneStackId)

		const ui = await openAutoArchive(page)

		// The list shows the column scope it was created with.
		await expect(ui.desc).toContainText('stack: Done')

		// ── Widen ────────────────────────────────────────────────────────────
		await ui.edit.click()
		// The form loads the saved rule's values, not the create defaults.
		await expect(ui.scope).toHaveValue(String(state.doneStackId))
		await expect(ui.days).toHaveValue('7')

		// "Whole board" binds to null, which renders as an empty option value.
		await ui.scope.selectOption({ label: 'Whole board' })
		await ui.save.click()

		await expect(ui.desc).toContainText('whole board')

		// The server really re-scoped it — this is the path #10461 unlocked.
		let saved = await api.send('GET', `/boards/${state.boardId}/archive-rules`)
		expect(saved.find((r) => r.id === rule.id).stackId).toBeNull()

		// ── Narrow back, changing condition + threshold in the same save ─────
		await ui.edit.click()
		await ui.scope.selectOption(String(state.otherStackId))
		await ui.condition.selectOption('1')
		await ui.days.fill('3')
		await ui.save.click()

		await expect(ui.desc).toContainText('stack: Backlog')
		await expect(ui.desc).toContainText('3 days')

		saved = await api.send('GET', `/boards/${state.boardId}/archive-rules`)
		const after = saved.find((r) => r.id === rule.id)
		expect(after.stackId).toBe(state.otherStackId)
		expect(after.condition).toBe(1)
		expect(after.thresholdSeconds).toBe(3 * 86400)

		// The form dropped back to create mode and reset — it is not still bound
		// to the rule, so the next submit would add, not overwrite.
		await expect(ui.add).toBeVisible()
		await expect(ui.days).toHaveValue('0')

		await api.send('DELETE', `/archive-rules/${rule.id}`).catch(() => {})
	})

	test('cancelling an edit leaves the saved rule untouched', async ({ page }) => {
		const rule = await api.send('POST', `/boards/${state.boardId}/archive-rules`, {
			stackId: state.doneStackId,
			condition: 0,
			thresholdSeconds: 5 * 86400,
		})

		const ui = await openAutoArchive(page)

		await ui.edit.click()
		await ui.scope.selectOption({ label: 'Whole board' })
		await ui.days.fill('99')
		await ui.cancel.click()

		await expect(ui.add).toBeVisible()

		const saved = (await api.send('GET', `/boards/${state.boardId}/archive-rules`))
			.find((r) => r.id === rule.id)
		expect(saved.stackId).toBe(state.doneStackId)
		expect(saved.thresholdSeconds).toBe(5 * 86400)

		await api.send('DELETE', `/archive-rules/${rule.id}`).catch(() => {})
	})
})
