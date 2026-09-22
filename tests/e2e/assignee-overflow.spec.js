// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Card #10655 — a card with four assignees must not silently show three.
//
// Every board surface caps the avatar stack at 3, but only the kanban tile used
// to say so: the list row and the timeline pane sliced to 3 and rendered no
// "+N" badge, so the 4th assignee vanished with nothing prompting the user to
// open the card. All three now mount the same AssigneeAvatars component; this
// spec pins the badge AND its count on each of them, so dropping the badge (or
// letting one surface drift back to a hand-rolled slice) goes red.
//
// It also closes a standing coverage gap: nothing anywhere asserted that a card
// can carry MORE THAN ONE assignee. Multi-assign is supported at every layer
// (kanso_card_assignees is a join table, AssigneeService::assign() is a pure
// append), but it was never tested — the "Paired task" card below is the pin.
//
// Card #10659 adds the two a11y pins at the bottom of this file:
//   1. the stack's group label is actually COMPUTED as an accessible name (it
//      was `aria-label` on a bare <span>, i.e. on role="generic", where ARIA
//      prohibits it — the label was dead code);
//   2. the "+N" label stays inside its badge when the user raises their browser
//      font size (the circle is sized in px off `size`, the label in rem, so
//      they never scaled together and the text spilled over the neighbouring
//      avatar).

import { test, expect, api, ncLogin, provisionUser, deleteUser, BASE, me } from './helpers.js'

// The stack shows this many avatars; everyone past it collapses into "+N".
const MAX_VISIBLE = 3

// Root font sizes to probe: the app's own default, and a plausible
// accessibility setting (browser/OS "large text").
const ROOT_FONT_SIZES = [15, 20]

// The label widths that exist in practice: a single digit (the common case,
// which must stay a circle) and two digits (which may widen into a pill). Both
// the narrowest and the widest digit, since the font stack is not guaranteed to
// be tabular — "+9" is the one that decides whether the circle still holds.
const LABELS = ['+1', '+9', '+12', '+99']

/**
 * Assert an avatar stack inside `scope` renders exactly `avatars` avatars and,
 * when `badge` is a string, an overflow badge with exactly that text.
 *
 * The badge text is asserted, not just its presence: "3 avatars render" is true
 * with or without the badge, so only the count discriminates the bug.
 *
 * @param {import('@playwright/test').Locator} scope Row/tile to look inside.
 * @param {object} expected What the stack should show.
 * @param {number} expected.avatars How many avatars must render.
 * @param {string|null} expected.badge Badge text, or null for "no badge at all".
 */
async function expectAvatarStack(scope, { avatars, badge }) {
	await expect(scope.locator('.assignee-stack__avatar')).toHaveCount(avatars)
	const overflow = scope.locator('.assignee-stack__overflow')
	if (badge === null) {
		await expect(overflow).toHaveCount(0)
	} else {
		await expect(overflow).toHaveCount(1)
		await expect(overflow).toHaveText(badge)
	}
}

/**
 * Measure a rendered "+N" badge with `label` as its text, at `rootFontSize`.
 *
 * Only the TEXT is faked — every box the measurement reads (the badge, its
 * border, its padding) is the real component rendered by the real app with the
 * real Nextcloud font stack, so a fixed-width badge genuinely overflows here
 * exactly as it does for a user who turned their font size up. The badge's own
 * geometry is never touched, which is what makes this non-vacuous: the only way
 * to pass is for the CSS to accommodate the wider label.
 *
 * Returns px of horizontal spill past each inner edge (> 0 means the label is
 * outside its badge) plus the badge's own box.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {string} selector CSS selector for the badge on the surface under test.
 * @param {string} label Badge text to measure, e.g. '+12'.
 * @param {number} rootFontSize Root font size in px to simulate.
 * @return {Promise<object>} width/height/textWidth/overflowLeft/overflowRight/fontSize.
 */
async function measureBadge(page, selector, label, rootFontSize) {
	return page.evaluate(({ selector, label, rootFontSize }) => {
		const badge = document.querySelector(selector)
		if (!badge) throw new Error(`no badge matched ${selector}`)
		const previousRoot = document.documentElement.style.fontSize
		const previousText = badge.textContent
		document.documentElement.style.fontSize = `${rootFontSize}px`
		badge.textContent = label
		const style = getComputedStyle(badge)
		const box = badge.getBoundingClientRect()
		const range = document.createRange()
		range.selectNodeContents(badge)
		const text = range.getBoundingClientRect()
		const borderLeft = parseFloat(style.borderLeftWidth)
		const borderRight = parseFloat(style.borderRightWidth)
		const result = {
			width: box.width,
			height: box.height,
			textWidth: text.width,
			overflowLeft: (box.left + borderLeft) - text.left,
			overflowRight: text.right - (box.right - borderRight),
			fontSize: parseFloat(style.fontSize),
		}
		badge.textContent = previousText
		document.documentElement.style.fontSize = previousRoot
		return result
	}, { selector, label, rootFontSize })
}

test.describe('Assignee overflow badge on every board surface (#10655)', () => {
	const state = { boardId: 0, extras: [] }
	const EXTRA_PASS = 'Kanso#Assignee2026'

	test.beforeAll(async ({}, workerInfo) => {
		// Three extra identities beyond `me`, named off the worker index so
		// parallel workers never fight over the same accounts.
		const names = ['a', 'b', 'c'].map((s) => `kanso_assignee_w${workerInfo.workerIndex}_${s}`)
		for (const name of names) {
			await provisionUser(name, EXTRA_PASS, { displayName: name })
			state.extras.push(name)
		}

		const board = await api.post('/boards', { title: 'Assignee overflow ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		// AssigneeService refuses a participant who cannot read the board, so
		// share it with each extra user first (READ is enough).
		for (const uid of state.extras) {
			await api.post(`/boards/${board.id}/acl`, { participant: uid, participantType: 'user', permission: 1 })
		}

		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })

		// 4 assignees → 3 avatars + "+1". Dated so the timeline schedules it
		// (undated cards land in the collapsed "unscheduled" list instead).
		const crowded = await api.post('/cards', { stackId: stack.id, title: 'Crowded task' })
		await api.patch(`/cards/${crowded.id}`, { duedate: '2026-08-10T00:00:00+00:00' })
		await api.put(`/cards/${crowded.id}/assignees/${me}`)
		for (const uid of state.extras) {
			await api.put(`/cards/${crowded.id}/assignees/${uid}`)
		}

		// 2 assignees → 2 avatars, no badge. This is also the only coverage
		// anywhere that a card holds more than one assignee at all.
		const paired = await api.post('/cards', { stackId: stack.id, title: 'Paired task' })
		await api.patch(`/cards/${paired.id}`, { duedate: '2026-08-11T00:00:00+00:00' })
		await api.put(`/cards/${paired.id}/assignees/${me}`)
		await api.put(`/cards/${paired.id}/assignees/${state.extras[0]}`)

		// Multi-assign is real on the server, not just in the UI cache.
		const seeded = await api.get(`/cards/${crowded.id}`)
		expect(seeded.assigneeIds.length).toBe(MAX_VISIBLE + 1)
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
		for (const uid of state.extras) await deleteUser(uid).catch(() => {})
	})

	/**
	 * Open the board and switch it to the named display mode.
	 *
	 * @param {import('@playwright/test').Page} page The page.
	 * @param {string} view 'Board', 'List' or 'Timeline'.
	 */
	async function openBoard(page, view) {
		await ncLogin(page)
		// The view mode is remembered per board in localStorage — start clean so
		// the menu click is what decides the mode.
		await page.addInitScript(() => { try { localStorage.clear() } catch (e) { /* private mode */ } })
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		if (view === 'Board') return
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByRole('menuitemradio', { name: view, exact: true }).click()
		await page.keyboard.press('Escape')
	}

	test('kanban tile shows 3 avatars + "+1"', async ({ page }) => {
		await openBoard(page, 'Board')
		const crowded = page.locator('.card-tile', { hasText: 'Crowded task' })
		await expect(crowded).toBeVisible({ timeout: 15_000 })
		await expectAvatarStack(crowded, { avatars: MAX_VISIBLE, badge: '+1' })

		const paired = page.locator('.card-tile', { hasText: 'Paired task' })
		await expectAvatarStack(paired, { avatars: 2, badge: null })
	})

	test('list view shows 3 avatars + "+1", and no badge under the cap', async ({ page }) => {
		await openBoard(page, 'List')
		await page.waitForSelector('.board-list-row', { timeout: 10_000 })

		const crowded = page.locator('.board-list-row', { hasText: 'Crowded task' })
		await expect(crowded).toBeVisible()
		await expectAvatarStack(crowded, { avatars: MAX_VISIBLE, badge: '+1' })

		const paired = page.locator('.board-list-row', { hasText: 'Paired task' })
		await expect(paired).toBeVisible()
		await expectAvatarStack(paired, { avatars: 2, badge: null })
	})

	test('timeline view shows 3 avatars + "+1", and no badge under the cap', async ({ page }) => {
		await openBoard(page, 'Timeline')
		await page.waitForSelector('.timeline__pane-row', { timeout: 10_000 })

		const crowded = page.locator('.timeline__pane-row', { hasText: 'Crowded task' })
		await expect(crowded).toBeVisible()
		await expectAvatarStack(crowded, { avatars: MAX_VISIBLE, badge: '+1' })

		const paired = page.locator('.timeline__pane-row', { hasText: 'Paired task' })
		await expect(paired).toBeVisible()
		await expectAvatarStack(paired, { avatars: 2, badge: null })
	})

	// ── #10659 ──────────────────────────────────────────────────────────────

	test('the stack exposes "Assignees" as a computed accessible name', async ({ page }) => {
		await openBoard(page, 'Board')
		const crowded = page.locator('.card-tile', { hasText: 'Crowded task' })
		await expect(crowded).toBeVisible({ timeout: 15_000 })

		// Resolved by role + NAME, not by attribute presence: Playwright computes
		// the accessible name per the ARIA spec, so this only matches if the name
		// is actually exposed on a role that may carry one.
		await expect(crowded.getByRole('group', { name: 'Assignees', exact: true })).toHaveCount(1)

		// And the browser's own accessibility engine agrees. This is the assertion
		// that has teeth: the markup carried `aria-label` for months on a bare
		// <span>, which maps to role="generic", and ARIA prohibits a name there —
		// Chrome computed it away, so the label shipped to nobody. Reading the
		// real AX node is the only way to see that.
		const cdp = await page.context().newCDPSession(page)
		await cdp.send('Accessibility.enable')
		const { root } = await cdp.send('DOM.getDocument')
		const { nodeId } = await cdp.send('DOM.querySelector', {
			nodeId: root.nodeId,
			selector: '.card-tile .assignee-stack',
		})
		expect(nodeId, 'no .assignee-stack in the DOM').toBeTruthy()
		const { node } = await cdp.send('DOM.describeNode', { nodeId })
		const { nodes } = await cdp.send('Accessibility.getPartialAXTree', { nodeId, fetchRelatives: false })
		const ax = nodes.find((n) => n.backendDOMNodeId === node.backendNodeId)
		expect(ax, 'the stack has no accessibility node at all').toBeTruthy()
		expect(ax.role.value).toBe('group')
		expect(ax.name && ax.name.value).toBe('Assignees')
		await cdp.detach()
	})

	test('the "+N" label stays inside its badge at every size and root font size', async ({ page }) => {
		// Every avatar size the app actually uses is covered, each on a surface
		// that really uses it: 24 on the comfortable kanban tile and the list
		// row, 22 on the timeline pane, 20 on the compact tile. Everything is
		// measured first and asserted afterwards, so a failure report still
		// carries the whole table rather than stopping at the first bad row.
		const measured = []

		/**
		 * Measure the badge now on screen across both root font sizes and both
		 * label widths.
		 *
		 * @param {object} surface The surface descriptor (name, size, selector).
		 */
		async function sweep(surface) {
			for (const rootFontSize of ROOT_FONT_SIZES) {
				for (const label of LABELS) {
					const box = await measureBadge(page, surface.selector, label, rootFontSize)
					measured.push({ surface, rootFontSize, label, box })
				}
			}
		}

		await openBoard(page, 'Board')
		await expect(page.locator('.card-tile', { hasText: 'Crowded task' })).toBeVisible({ timeout: 15_000 })
		await sweep({ name: 'kanban tile', size: 24, selector: '.card-tile .assignee-stack__overflow' })

		// Compact density drops the stack to size 20 — the tightest case.
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByRole('menuitemradio', { name: 'Compact', exact: true }).click()
		await page.keyboard.press('Escape')
		await expect(page.locator('.card-tile').first()).toHaveClass(/card-tile--compact/, { timeout: 6_000 })
		await sweep({ name: 'compact kanban tile', size: 20, selector: '.card-tile .assignee-stack__overflow' })

		await openBoard(page, 'Timeline')
		await page.waitForSelector('.timeline__pane-row', { timeout: 10_000 })
		await sweep({ name: 'timeline pane', size: 22, selector: '.timeline__pane-row .assignee-stack__overflow' })

		await openBoard(page, 'List')
		await page.waitForSelector('.board-list-row', { timeout: 10_000 })
		await sweep({ name: 'list row', size: 24, selector: '.board-list-row .assignee-stack__overflow' })

		// Printed so a regression report carries the numbers, not just a verdict.
		for (const { surface, rootFontSize, label, box } of measured) {
			console.log(`${surface.name} size=${surface.size} root=${rootFontSize}px "${label}" → `
				+ `font ${box.fontSize.toFixed(2)}px, text ${box.textWidth.toFixed(2)}px, `
				+ `badge ${box.width.toFixed(2)}×${box.height.toFixed(2)}, `
				+ `spill L ${box.overflowLeft.toFixed(2)} R ${box.overflowRight.toFixed(2)}`)
		}

		for (const { surface, rootFontSize, label, box } of measured) {
			const where = `${surface.name} (size ${surface.size}), root ${rootFontSize}px, "${label}"`
			// The label must not spill past the badge's inner edge on either side.
			// 0.5px of slack absorbs sub-pixel text shaping, nothing more.
			expect(box.overflowLeft, `${where}: label spills off the left`).toBeLessThanOrEqual(0.5)
			expect(box.overflowRight, `${where}: label spills off the right`).toBeLessThanOrEqual(0.5)
			// The badge keeps the avatar's height, so the stack's vertical rhythm
			// is unchanged — only the width is allowed to give.
			expect(box.height, `${where}: badge height drifted off the avatar size`).toBeCloseTo(surface.size, 0)
			// …and it never shrinks below the avatar diameter.
			expect(box.width, `${where}: badge narrower than the avatar`).toBeGreaterThanOrEqual(surface.size - 0.5)
			// The common case stays a circle: a single-digit "+N" at the default
			// root font size must not widen into a pill.
			if (label.length === 2 && rootFontSize === 15) {
				expect(Math.abs(box.width - box.height), `${where}: single-digit badge is not circular`).toBeLessThanOrEqual(0.5)
			}
		}
	})
})
