// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, me } from './helpers.js'

const ROLE_REVIEW = 4
const ROLE_IN_PROGRESS = 3
const ROLE_DONE = 5

test.describe('Automation rules (#3400)', () => {
	const state = { boardId: 0, todoStackId: 0, reviewStackId: 0, progStackId: 0, doneStackId: 0, labelId: 0 }

	test.beforeAll(async () => {
		const board = await api.send('POST', '/boards', { title: 'Automation ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.todoStackId = (await api.send('POST', '/stacks', { boardId: board.id, title: 'To do' })).id
		state.reviewStackId = (await api.send('POST', '/stacks', { boardId: board.id, title: 'Review' })).id
		state.progStackId = (await api.send('POST', '/stacks', { boardId: board.id, title: 'Doing' })).id
		state.doneStackId = (await api.send('POST', '/stacks', { boardId: board.id, title: 'Done' })).id
		await api.send('PATCH', `/stacks/${state.reviewStackId}`, { role: ROLE_REVIEW })
		await api.send('PATCH', `/stacks/${state.progStackId}`, { role: ROLE_IN_PROGRESS })
		await api.send('PATCH', `/stacks/${state.doneStackId}`, { role: ROLE_DONE })
		state.labelId = (await api.send('POST', '/labels', { boardId: board.id, title: 'Auto-tagged', color: 'e74c3c' })).id

		// The timer rules live here, not in the body of the test that first used
		// them: the idempotency test reuses them, and a retry re-runs only the
		// failing test, so it would meet a board with no timer rules at all and
		// fail on every attempt. Both timer tests still prove what they always
		// did — that the moves start/stop the clock and write the entries.
		await api.send('POST', `/boards/${board.id}/automation-rules`, {
			trigger: 'card_entered_role', action: 'start_timer', params: { role: ROLE_IN_PROGRESS },
		})
		await api.send('POST', `/boards/${board.id}/automation-rules`, {
			trigger: 'card_entered_role', action: 'stop_timer', params: { role: ROLE_DONE },
		})
	})

	test.afterAll(async () => {
		if (state.boardId) await api.send('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('entering a review-role column fires the request_review rule', async () => {
		await api.send('POST', `/boards/${state.boardId}/automation-rules`, {
			trigger: 'card_entered_role',
			action: 'request_review',
			params: { role: ROLE_REVIEW, reviewer: me },
		})
		const cardId = (await api.send('POST', '/cards', { stackId: state.todoStackId, title: 'Needs review' })).id

		let card = await api.send('GET', `/cards/${cardId}`)
		expect(card.reviews ?? []).toHaveLength(0)

		await api.send('POST', `/cards/${cardId}/move`, { targetStackId: state.reviewStackId, afterCardId: null })

		card = await api.send('GET', `/cards/${cardId}`)
		expect(card.reviews.some((rv) => rv.reviewer === me)).toBe(true)
	})

	test('entering an in-progress-role column fires the add_label rule', async () => {
		await api.send('POST', `/boards/${state.boardId}/automation-rules`, {
			trigger: 'card_entered_role',
			action: 'add_label',
			params: { role: ROLE_IN_PROGRESS, label: state.labelId },
		})
		const cardId = (await api.send('POST', '/cards', { stackId: state.todoStackId, title: 'Gets a label' })).id

		let card = await api.send('GET', `/cards/${cardId}`)
		expect(card.labelIds ?? []).not.toContain(state.labelId)

		await api.send('POST', `/cards/${cardId}/move`, { targetStackId: state.progStackId, afterCardId: null })

		card = await api.send('GET', `/cards/${cardId}`)
		expect(card.labelIds).toContain(state.labelId)
	})

	test('start_timer on entry, then stop_timer, writes an elapsed time entry (#73)', async () => {
		// The start/stop rules come from beforeAll — see the note there.
		const cardId = (await api.send('POST', '/cards', { stackId: state.todoStackId, title: 'Timed work' })).id

		// No time entries before any timer runs.
		expect(await api.send('GET', `/cards/${cardId}/time-entries`)).toHaveLength(0)

		// Entering the in-progress column starts a RUNNING timer — no finished
		// entry is written yet (the clock is still ticking).
		await api.send('POST', `/cards/${cardId}/move`, { targetStackId: state.progStackId, afterCardId: null })
		expect(await api.send('GET', `/cards/${cardId}/time-entries`)).toHaveLength(0)

		// The board summary must expose timerRunning:true while the timer ticks.
		const boardWhileRunning = await api.send('GET', `/boards/${state.boardId}`)
		expect(boardWhileRunning.cards.find((c) => c.id === cardId).timerRunning).toBe(true)

		// Let at least a whole second elapse so the stop records a non-zero span.
		await new Promise((resolve) => setTimeout(resolve, 1500))

		// Entering the done column stops the timer → exactly one finished entry
		// carrying the elapsed seconds, tagged as automatic.
		await api.send('POST', `/cards/${cardId}/move`, { targetStackId: state.doneStackId, afterCardId: null })
		const entries = await api.send('GET', `/cards/${cardId}/time-entries`)
		expect(entries).toHaveLength(1)
		expect(entries[0].seconds).toBeGreaterThanOrEqual(1)
		expect(entries[0].note).toBe('Tracked automatically')

		// After stopping, timerRunning must be false on the board summary.
		const boardAfterStop = await api.send('GET', `/boards/${state.boardId}`)
		expect(boardAfterStop.cards.find((c) => c.id === cardId).timerRunning).toBe(false)
	})

	test('re-entering the start column does not start a second timer (idempotent) (#73)', async () => {
		// Uses the start/stop rules created in beforeAll.
		const cardId = (await api.send('POST', '/cards', { stackId: state.todoStackId, title: 'Idempotent timer' })).id

		// Start, bounce out to a role-less column and back in — the second entry
		// into the start column must be a no-op, not a fresh timer.
		await api.send('POST', `/cards/${cardId}/move`, { targetStackId: state.progStackId, afterCardId: null })
		await new Promise((resolve) => setTimeout(resolve, 1200))
		await api.send('POST', `/cards/${cardId}/move`, { targetStackId: state.todoStackId, afterCardId: null })
		await api.send('POST', `/cards/${cardId}/move`, { targetStackId: state.progStackId, afterCardId: null })

		// Stop → exactly ONE entry, spanning from the ORIGINAL start (idempotent).
		await api.send('POST', `/cards/${cardId}/move`, { targetStackId: state.doneStackId, afterCardId: null })
		const entries = await api.send('GET', `/cards/${cardId}/time-entries`)
		expect(entries).toHaveLength(1)
		expect(entries[0].seconds).toBeGreaterThanOrEqual(1)
	})

	test('a label from another board is rejected at rule creation', async () => {
		const other = await api.send('POST', '/boards', { title: 'Other ' + Math.floor(Date.now() / 1000) })
		const foreignLabel = (await api.send('POST', '/labels', { boardId: other.id, title: 'Foreign', color: null })).id
		const r = await api.raw('POST', `/boards/${state.boardId}/automation-rules`, { trigger: 'card_entered_role', action: 'add_label', params: { role: ROLE_REVIEW, label: foreignLabel } })
		expect(r.ok).toBe(false)
		await api.send('DELETE', `/boards/${other.id}`).catch(() => {})
	})

	test('a rule created in the settings panel persists and lists', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)

		// Open board settings → Automation tab (settings now in the ⋯ More menu).
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /automation/i }).click()

		// The card-rules "Add rule" form: pick In-progress role + add-label, then submit.
		const roleSelect = page.locator(`#auto-role-${state.boardId}`)
		await expect(roleSelect).toBeVisible()
		const form = page.locator('form').filter({ has: roleSelect })
		const actionSelect = page.locator(`#auto-action-${state.boardId}`)
		const translations = {
			'When a card enters role': 'Wenn eine Karte in eine Spalte mit der folgenden festgelegten Rolle verschoben wird',
			'Do': 'Anschließend die folgende Automatisierungsaktion ausführen',
			'Reviewer': 'Für die Überprüfung verantwortliche Person',
			'Label': 'Die folgende Bezeichnung zur Karte hinzufügen',
		}
		for (const translated of [false, true]) {
			if (translated) {
				await page.evaluate(translations => Object.assign(globalThis._oc_l10n_registry_translations.kanso ||= {}, translations), translations)
			}
			for (const width of [1280, 900, 375]) {
				await page.setViewportSize({ width, height: 900 })
				for (const action of ['add_label', 'request_review']) {
					await actionSelect.selectOption(action)
					await expect(page.locator(`#auto-${action === 'add_label' ? 'label' : 'reviewer'}-${state.boardId}`)).toBeVisible()
					await expect(form.locator('.automation__form-label').first()).toHaveText(translated ? translations['When a card enters role'] : 'When a card enters role')
					const formWidth = await form.evaluate(el => el.getBoundingClientRect().width)
					if (width === 900) expect(formWidth).toBeLessThan(400)
					if (width === 375) expect(formWidth).toBeLessThan(200)
					const rows = await form.locator('.automation__form-row').evaluateAll(rows => rows.map(row => {
						const label = row.querySelector('label')
						const labelBox = label.getBoundingClientRect()
						const control = row.querySelector('select').getBoundingClientRect()
						const rowBox = row.getBoundingClientRect()
						const range = document.createRange()
						range.selectNodeContents(label)
						const text = [...range.getClientRects()].filter(rect => rect.width > 0)
						return {
							label: label.textContent.trim(),
							contained: text.length > 0 && text.every(rect => rect.left >= labelBox.left - 1 && rect.right <= labelBox.right + 1 && rect.top >= labelBox.top - 1 && rect.bottom <= labelBox.bottom + 1),
							overlaps: text.some(rect => rect.left < control.right && rect.right > control.left && rect.top < control.bottom && rect.bottom > control.top),
							controlFits: control.left >= rowBox.left - 1 && control.right <= rowBox.right + 1,
							controlWidth: control.width,
						}
					}))
					expect(rows).toHaveLength(3)
					for (const row of rows) {
						expect(row.contained, row.label).toBe(true)
						expect(row.overlaps, row.label).toBe(false)
						expect(row.controlFits, row.label).toBe(true)
						expect(row.controlWidth, row.label).toBeGreaterThanOrEqual(100)
					}
					for (const select of await form.locator('select').all()) {
						await select.scrollIntoViewIfNeeded()
						expect(await select.evaluate(el => {
							const box = el.getBoundingClientRect()
							return document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2) === el
						})).toBe(true)
					}
				}
			}
		}
		await roleSelect.selectOption(String(ROLE_IN_PROGRESS))
		await actionSelect.selectOption('add_label')
		await page.locator(`#auto-label-${state.boardId}`).selectOption(String(state.labelId))
		await page.getByRole('button', { name: /^Add rule$/ }).last().click()

		// It shows up in the rules list with the readable description.
		await expect(page.locator('.automation__rule-desc', { hasText: /add label "Auto-tagged"/ }).first())
			.toBeVisible()

		// And it survives a reload (server round-trip via GET automation-rules).
		const rules = await api.send('GET', `/boards/${state.boardId}/automation-rules`)
		expect(rules.some((rl) => rl.action === 'add_label' && rl.params.label === state.labelId)).toBe(true)
	})
})
