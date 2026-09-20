// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Board settings — the "Add …" create rows must fit the drawer.
 *
 * The board settings panel is a fixed 500px right-side drawer: a 164px section
 * rail plus a pane area, and each pane carries 20px of padding, leaving a
 * content column of roughly 296px. Every "Add <thing>" form at the bottom of a
 * pane is a flex row (`.label-settings__create-row`) whose text input is
 * `flex: 1`. An <input> has an intrinsic minimum width (the UA `size` box,
 * ~200px), and `flex: 1` alone does NOT let a flex item shrink below that —
 * `min-width: 0` is required. Without it the row's minimum content width is the
 * sum of the fixed children plus that ~200px floor, which on the Review types
 * pane (swatch + input + Stage spinner + Add) exceeds the pane and pushes the
 * Add button off-screen behind a horizontal scroll.
 *
 * These tests assert the geometry rather than eyeballing it: the Add button's
 * right edge must sit inside the pane's right edge, the pane area must not
 * scroll horizontally, and the button must be hit-testable where it renders.
 */
import { test, expect, api, ncLogin, BASE } from './helpers.js'

const BOARD_TITLE = 'Create Row Layout E2E Board'

test.describe('Board settings create rows fit the drawer', () => {
	const state = { boardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === BOARD_TITLE) await api.delete(`/boards/${b.id}`)
		}
		const board = await api.post('/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		await api.post('/stacks', { boardId: board.id, title: 'Backlog' })
		// A pre-existing review type so the list above the form is populated too.
		await api.post('/review-types', { boardId: board.id, title: 'QA', color: '3498db' })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	/** Open the board and the board-settings drawer. */
	async function openSettings(page) {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await expect(page.locator('.bs-modal')).toBeVisible({ timeout: 10_000 })
	}

	/**
	 * Measure an "Add" button against the pane that contains it, and against the
	 * scroll container the drawer actually overflows into.
	 */
	async function measure(page, paneSelector, btnSelector) {
		const btn = page.locator(btnSelector)
		await expect(btn).toBeVisible({ timeout: 8_000 })
		return page.evaluate(([paneSel, bSel]) => {
			const paneEl = document.querySelector(paneSel)
			const btnEl = document.querySelector(bSel)
			const rowEl = btnEl.closest('.label-settings__create-row')
			const inputEl = rowEl.querySelector('.label-settings__create-input')
			const panes = document.querySelector('.bs-panes')
			const p = paneEl.getBoundingClientRect()
			const b = btnEl.getBoundingClientRect()
			const i = inputEl.getBoundingClientRect()
			const cs = getComputedStyle(paneEl)
			// Inner content edge: the pane's padding box minus its own padding.
			const innerRight = p.right - parseFloat(cs.paddingRight)
			const innerLeft = p.left + parseFloat(cs.paddingLeft)
			return {
				innerLeft,
				innerRight,
				contentWidth: innerRight - innerLeft,
				btnLeft: b.left,
				btnRight: b.right,
				overflow: b.right - innerRight,
				inputWidth: i.width,
				// How far the button sits below the input's line — 0 when the row
				// stays on a single line, one row-height when the button wrapped.
				btnLineOffset: Math.abs(b.top - i.top),
				scrollWidth: panes.scrollWidth,
				clientWidth: panes.clientWidth,
				hScroll: panes.scrollWidth - panes.clientWidth,
			}
		}, [paneSelector, btnSelector])
	}

	test('the "Add review type" row keeps its Add button inside the drawer', async ({ page }) => {
		await openSettings(page)
		await page.getByRole('tab', { name: /review types/i }).click()

		const m = await measure(
			page,
			'#bs-pane-review-types',
			'#bs-pane-review-types .label-settings__create-btn',
		)
		// Useful when this fails: the exact geometry, not just a boolean.
		console.log('[review-types] ' + JSON.stringify(m))

		// The content column really is the narrow drawer column, not a wide
		// viewport — otherwise this test would pass vacuously.
		expect(m.contentWidth).toBeLessThan(400)
		// The Add button must end inside the pane's content box (1px for
		// sub-pixel rounding), and the pane area must not scroll sideways.
		expect(m.overflow).toBeLessThanOrEqual(1)
		expect(m.hScroll).toBeLessThanOrEqual(1)

		// And it must actually be clickable where it renders: hit-test the
		// button's own centre point.
		const hit = await page.evaluate(() => {
			const el = document.querySelector('#bs-pane-review-types .label-settings__create-btn')
			const r = el.getBoundingClientRect()
			const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2)
			return !!top && (top === el || el.contains(top))
		})
		expect(hit).toBe(true)

		// It fits by SHRINKING the name field, not by dumping the button onto a
		// second line: the row stays one line and the input stays usable.
		expect(m.btnLineOffset).toBeLessThanOrEqual(2)
		expect(m.inputWidth).toBeGreaterThan(60)
	})

	test('the sibling "Add label" and "Add custom field" rows stay inside too', async ({ page }) => {
		await openSettings(page)

		await page.getByRole('tab', { name: /labels/i }).click()
		const labels = await measure(
			page,
			'#bs-pane-labels',
			'#bs-pane-labels .label-settings__create-btn',
		)
		console.log('[labels] ' + JSON.stringify(labels))
		expect(labels.contentWidth).toBeLessThan(400)
		expect(labels.overflow).toBeLessThanOrEqual(1)
		expect(labels.hScroll).toBeLessThanOrEqual(1)

		await page.getByRole('tab', { name: /card fields/i }).click()
		const fields = await measure(
			page,
			'#bs-pane-card-fields',
			'#bs-pane-card-fields [data-test="cf-create-form"] .label-settings__create-btn',
		)
		console.log('[card-fields] ' + JSON.stringify(fields))
		expect(fields.contentWidth).toBeLessThan(400)
		expect(fields.overflow).toBeLessThanOrEqual(1)
		expect(fields.hScroll).toBeLessThanOrEqual(1)
	})

	test('the Add review type button still creates a type when clicked in place', async ({ page }) => {
		await openSettings(page)
		await page.getByRole('tab', { name: /review types/i }).click()

		await page.getByLabel(/new review type name/i).fill('Security')
		// Real click at the rendered position — no scrollIntoView rescue.
		await page.getByRole('button', { name: /create review type/i }).click()

		const item = page.locator('.rt-settings__list .label-settings__item', { hasText: 'Security' })
		await expect(item).toHaveCount(1, { timeout: 8_000 })
		await expect(page.locator('#bs-pane-review-types .label-settings__error')).toHaveCount(0)
	})
})
