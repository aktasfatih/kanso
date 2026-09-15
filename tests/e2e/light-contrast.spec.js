// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * WCAG contrast of Kanso's status colours, in BOTH themes (#136).
 *
 * Nextcloud 32 redefined --color-error / --color-warning / --color-success from
 * FOREGROUND colours into near-white background TINTS (#FFE7E7 / #FFEEC5 /
 * #D8F3DA in light), with --color-*-text taking over as the readable pair.
 * Every badge, pill and inline error that kept painting text or a border with
 * the bare token therefore renders at roughly 1.2:1 on a light surface —
 * legally invisible. This spec pins the measured ratio of each of those
 * surfaces so the regression cannot come back silently.
 *
 * It measures COMPUTED STYLE, not pixels: no screenshot baselines, so it runs
 * anywhere and reports a number a reviewer can act on ("2.1:1, #e9322d on
 * #ffffff") rather than "images differ".
 *
 * Theme forcing: the suite shares ONE Nextcloud instance and one admin
 * storageState, so a server-side theme write (occ theming:config,
 * Settings → Appearance, an OCS write) would re-colour every OTHER spec in the
 * run. The only safe lever is per-context `colorScheme` emulation: Nextcloud's
 * "default" theme ships its light and dark stylesheets behind
 * `media="(prefers-color-scheme: …)"`, so emulating the preference flips the
 * whole palette for THIS browser context and nothing else. The config-level
 * storageState is deliberately left alone so the session stays logged in.
 */

import { test, expect, api, ncLogin, boardUrl, BASE } from './helpers.js'

/** Board fixture shared by both theme blocks (created once per worker). */
const state = {
	boardId: 0,
	boardUrl: '',
	cardAUrl: '',
	cardBUrl: '',
	cardAId: 0,
	cardBId: 0,
}

const BOARD = 'Contrast Test Board'
const LABEL_RENAME = 'ContrastRenameLabel'
const LABEL_DELETE = 'ContrastDeleteLabel'

test.beforeAll(async () => {
	for (const b of await api.get('/boards')) {
		if (b.title === BOARD) await api.delete(`/boards/${b.id}`)
	}
	const board = await api.post('/boards', { title: BOARD })
	state.boardId = board.id
	state.boardUrl = boardUrl(board.id)

	const stack = await api.post('/stacks', { boardId: board.id, title: 'Contrast' })

	// Card A carries the three red/urgent surfaces: urgent priority, bug type,
	// and a due date three days in the past so the overdue styling applies.
	const cardA = await api.post('/cards', { stackId: stack.id, title: 'Contrast A' })
	await api.patch(`/cards/${cardA.id}`, {
		priority: 4,
		type: 'bug',
		duedate: new Date(Date.now() - 3 * 24 * 60 * 60 * 1000).toISOString(),
	})

	// Card B carries the amber/high surfaces. Priority 3 specifically: the
	// priority-4 pill in the card modal already resolves to a legible token, so
	// asserting on it would prove nothing about this regression.
	const cardB = await api.post('/cards', { stackId: stack.id, title: 'Contrast B' })
	await api.patch(`/cards/${cardB.id}`, { priority: 3 })

	state.cardAId = cardA.id
	state.cardBId = cardB.id
	state.cardAUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${cardA.id}`
	state.cardBUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${cardB.id}`

	// Two labels so the label rows in Board settings exist: one to drive the
	// inline rename error, one to open the destructive delete confirm on.
	await api.post('/labels', { boardId: board.id, title: LABEL_RENAME, color: 'e74c3c' })
	await api.post('/labels', { boardId: board.id, title: LABEL_DELETE, color: '3498db' })
})

/**
 * Browser-side contrast probe. Runs inside the page against ONE element and
 * returns the WCAG 2.x ratio of its text against the background actually
 * painted behind it.
 *
 * The backdrop search starts at the element ITSELF, so this measures both a
 * bare text node on an inherited surface (an inline error) and a button-like
 * element that carries its own background AND its own colour (the destructive
 * "Delete" confirm button) with the same code path. Translucent layers are
 * composited, top-down, over whatever is behind them.
 *
 * Serialized to the page by Playwright — it must stay closure-free.
 *
 * @param {Element} el
 * @return {{ratio: number, fg: string, bg: string}}
 */
function contrastProbe(el) {
	const parse = (c) => {
		const n = String(c || '').match(/[\d.]+/g)
		if (!n || n.length < 3) return { r: 0, g: 0, b: 0, a: 0 } // transparent / unset
		return { r: +n[0], g: +n[1], b: +n[2], a: n.length > 3 ? +n[3] : 1 }
	}
	const over = (top, bot) => ({
		r: top.r * top.a + bot.r * (1 - top.a),
		g: top.g * top.a + bot.g * (1 - top.a),
		b: top.b * top.a + bot.b * (1 - top.a),
		a: 1,
	})
	const lum = (c) => {
		const f = (v) => { const s = v / 255; return s <= 0.04045 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4) }
		return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b)
	}
	// Collect every painted background from the element up to <html>, stopping
	// at the first fully opaque one; anything above it cannot show through.
	const layers = []
	for (let n = el; n; n = n.parentElement) {
		const bg = parse(getComputedStyle(n).backgroundColor)
		if (bg.a > 0) layers.push(bg)
		if (bg.a >= 1) break
	}
	// If every layer up to <html> is translucent, the canvas underneath is the
	// theme's own paper: white in light, black in dark.
	const dark = window.matchMedia('(prefers-color-scheme: dark)').matches
	let bg = dark ? { r: 0, g: 0, b: 0, a: 1 } : { r: 255, g: 255, b: 255, a: 1 }
	for (let i = layers.length - 1; i >= 0; i--) bg = over(layers[i], bg)
	const fg = over(parse(getComputedStyle(el).color), bg)
	const [hi, lo] = [lum(fg), lum(bg)].sort((a, b) => b - a)
	const fmt = (c) => `rgb(${Math.round(c.r)}, ${Math.round(c.g)}, ${Math.round(c.b)})`
	return { ratio: (hi + 0.05) / (lo + 0.05), fg: fmt(fg), bg: fmt(bg) }
}

/** WCAG relative luminance of an `rgb()/rgba()` string, Node side. */
function relativeLuminance(rgb) {
	const n = String(rgb || '').match(/[\d.]+/g)?.map(Number) ?? []
	const f = (v) => { const s = v / 255; return s <= 0.04045 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4) }
	return 0.2126 * f(n[0] ?? 0) + 0.7152 * f(n[1] ?? 0) + 0.0722 * f(n[2] ?? 0)
}

/**
 * Assert one element clears `min`:1. The element must be visible first — an
 * absent element is a failure, never a silent pass.
 *
 * @param {import('@playwright/test').Locator} locator
 * @param {string} selector human-readable selector, for the failure message
 * @param {number} min WCAG minimum (4.5 for text, 3.0 for a graphical object)
 */
async function expectContrast(locator, selector, min) {
	await expect(locator, `${selector}: never rendered, so its contrast was never measured`)
		.toBeVisible()
	const m = await locator.evaluate(contrastProbe)
	expect(
		m.ratio,
		`${selector}: ${m.ratio.toFixed(2)}:1 — below the ${min.toFixed(1)}:1 minimum `
		+ `(foreground ${m.fg} on background ${m.bg})`,
	).toBeGreaterThanOrEqual(min)
}

/**
 * Precondition guard. Without it the whole spec could pass hollowly: if the CI
 * user has an explicit theme pinned, colorScheme emulation is a no-op and both
 * blocks would silently measure the SAME palette. Fails loudly — never skips.
 *
 * @param {import('@playwright/test').Page} page
 * @param {'light'|'dark'} mode
 */
async function assertThemeBaseline(page, mode) {
	const probe = await page.evaluate(() => {
		const raw = getComputedStyle(document.documentElement)
			.getPropertyValue('--color-main-background').trim()
		// Resolve the token to rgb() through the CSS parser. The sentinel stays
		// put if `raw` is not a colour the parser accepts, which the caller
		// treats as a failed read rather than as a dark background.
		const el = document.createElement('span')
		el.style.color = 'rgb(1, 2, 3)'
		el.style.color = raw
		document.body.appendChild(el)
		const resolved = getComputedStyle(el).color
		el.remove()
		return {
			themes: document.body.dataset.themes ?? '',
			raw,
			resolved,
			prefersDark: window.matchMedia('(prefers-color-scheme: dark)').matches,
		}
	})

	const themes = probe.themes.split(',').map((s) => s.trim()).filter(Boolean)
	expect(
		themes,
		`this user has an explicit theme pinned (data-themes="${probe.themes}"), so `
		+ 'colorScheme emulation is a no-op and every assertion below would be vacuous',
	).toContain('default')

	expect(
		probe.prefersDark,
		`colorScheme emulation did not reach the page: prefers-color-scheme dark = `
		+ `${probe.prefersDark} while the block asked for "${mode}"`,
	).toBe(mode === 'dark')

	expect(probe.raw, '--color-main-background resolved to nothing').not.toBe('')
	expect(
		probe.resolved,
		`--color-main-background ("${probe.raw}") is not a colour the CSS parser accepts`,
	).not.toBe('rgb(1, 2, 3)')

	const lum = relativeLuminance(probe.resolved)
	const detail = `--color-main-background = ${probe.resolved} (luminance ${lum.toFixed(3)})`
	if (mode === 'light') {
		expect(lum, `expected a LIGHT surface but got ${detail}`).toBeGreaterThan(0.5)
	} else {
		expect(lum, `expected a DARK surface but got ${detail}`).toBeLessThan(0.2)
	}
}

/** Open Board settings from the consolidated ⋯ More overflow menu, Labels tab. */
async function openLabelSettings(page) {
	await page.getByRole('button', { name: 'More' }).click()
	await page.getByRole('menuitem', { name: /board settings/i }).click()
	await page.locator('#bs-rail-tab-labels').click()
	await expect(page.locator('.label-settings__list')).toBeVisible()
}

/**
 * The identical assertion table, run once per theme.
 *
 * @param {'light'|'dark'} mode
 */
function contrastSuite(mode) {
	test.describe(`WCAG contrast (${mode} theme)`, () => {
		// Context-level emulation only. No server-side theme write: that would
		// re-colour every other spec sharing this Nextcloud instance.
		test.use({ colorScheme: mode })

		test('board tile status badges are legible', async ({ page }) => {
			await ncLogin(page)
			await page.goto(state.boardUrl)

			const tileA = page.locator(`.card-tile[data-card-id="${state.cardAId}"]`)
			const tileB = page.locator(`.card-tile[data-card-id="${state.cardBId}"]`)
			await expect(tileA).toBeVisible()
			await expect(tileB).toBeVisible()

			await assertThemeBaseline(page, mode)

			await expectContrast(
				tileA.locator('.card-tile__priority--4'),
				'.card-tile__priority--4 (Urgent badge, card A)',
				4.5,
			)
			await expectContrast(
				tileB.locator('.card-tile__priority--3'),
				'.card-tile__priority--3 (High badge, card B)',
				4.5,
			)
			await expectContrast(
				tileA.locator('.card-tile__due--overdue'),
				'.card-tile__due--overdue (overdue due-date chip, card A)',
				4.5,
			)
			// Icon-only: a graphical object, so WCAG 1.4.11 sets the bar at 3:1.
			await expectContrast(
				tileA.locator('.card-tile__type--bug'),
				'.card-tile__type--bug (type icon, card A — WCAG 1.4.11 non-text)',
				3.0,
			)
		})

		test('card modal attribute pills are legible', async ({ page }) => {
			await ncLogin(page)
			await page.goto(state.cardAUrl)
			await expect(page.locator('.card-modal')).toBeVisible()

			await assertThemeBaseline(page, mode)

			await expectContrast(
				page.locator('.card-modal__pill--type-bug'),
				'.card-modal__pill--type-bug (card A modal)',
				4.5,
			)

			// Card B: priority-3 deliberately, not priority-4 — the urgent pill
			// already resolves to a legible token, so it would prove nothing.
			await page.goto(state.cardBUrl)
			await expect(page.locator('.card-modal')).toBeVisible()
			await expectContrast(
				page.locator('.card-modal__pill--priority-3'),
				'.card-modal__pill--priority-3 (card B modal)',
				4.5,
			)
		})

		test('board settings label error and destructive confirm are legible', async ({ page }) => {
			await ncLogin(page)
			await page.goto(state.boardUrl)
			await expect(page.locator(`.card-tile[data-card-id="${state.cardAId}"]`)).toBeVisible()

			await assertThemeBaseline(page, mode)
			await openLabelSettings(page)

			// ── Inline rename error ────────────────────────────────────────────
			// Renaming to an EMPTY name returns early client-side (no error is
			// rendered), and the server has no uniqueness constraint on label
			// titles, so a duplicate name is accepted. The one inline-error path
			// a user can actually reach is the server's 100-character cap.
			// Addressed by the swatch button's aria-label, NOT by row text: the
			// inline rename swaps the label's <span> for an <input>, and an input's
			// value is not text content — a hasText filter stops matching the moment
			// the row enters edit mode.
			const renameRow = page.locator(
				`.label-settings__item:has(button[aria-label="Change color of label ${LABEL_RENAME}"])`,
			)
			await expect(renameRow).toBeVisible()
			await renameRow.locator('.label-settings__name').click()
			const input = renameRow.locator('.label-settings__rename-input')
			await expect(input).toBeVisible()
			// maxlength="100" caps what a user can TYPE, so the value is set
			// through the native setter (and an input event for v-model) to get a
			// rejected title onto the wire.
			await input.evaluate((el) => {
				const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set
				setter.call(el, 'x'.repeat(120))
				el.dispatchEvent(new Event('input', { bubbles: true }))
			})
			await input.press('Enter')

			await expectContrast(
				renameRow.locator('.label-settings__error').first(),
				'.label-settings__error (rejected label rename)',
				4.5,
			)

			// ── Destructive confirm ────────────────────────────────────────────
			// Opens the inline confirm bar; the label is never actually deleted.
			const deleteRow = page.locator(
				`.label-settings__item:has(button[aria-label="Change color of label ${LABEL_DELETE}"])`,
			)
			await expect(deleteRow).toBeVisible()
			await deleteRow.locator('.label-settings__action-btn--danger').click()
			await expectContrast(
				deleteRow.locator('.label-settings__confirm-yes'),
				'.label-settings__confirm-yes (destructive confirm button)',
				4.5,
			)
		})
	})
}

contrastSuite('light')
contrastSuite('dark')
