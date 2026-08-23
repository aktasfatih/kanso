// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = {
	'OCS-APIREQUEST': 'true',
	'Content-Type': 'application/json',
}
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')

async function apiGet(path) {
	const r = await fetch(API + path, { headers: { ...HEADERS, Authorization: AUTH } })
	if (!r.ok) throw new Error(`GET ${path} → ${r.status}`)
	return r.json()
}

async function apiPost(path, body) {
	const r = await fetch(API + path, {
		method: 'POST',
		headers: { ...HEADERS, Authorization: AUTH },
		body: JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`POST ${path} → ${r.status}: ${await r.text()}`)
	return r.json()
}

async function apiPatch(path, body) {
	const r = await fetch(API + path, {
		method: 'PATCH',
		headers: { ...HEADERS, Authorization: AUTH },
		body: JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`PATCH ${path} → ${r.status}: ${await r.text()}`)
	return r.json()
}

async function apiPut(path, body) {
	const r = await fetch(API + path, {
		method: 'PUT',
		headers: { ...HEADERS, Authorization: AUTH },
		body: JSON.stringify(body ?? {}),
	})
	if (!r.ok) throw new Error(`PUT ${path} → ${r.status}: ${await r.text()}`)
	return r.json()
}

async function apiDelete(path) {
	const r = await fetch(API + path, {
		method: 'DELETE',
		headers: { ...HEADERS, Authorization: AUTH },
	})
	if (!r.ok) throw new Error(`DELETE ${path} → ${r.status}`)
}

async function ncLogin(page) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})

	const userInput = page.locator('#user')
	const isLoginPage = await userInput.isVisible({ timeout: 3000 }).catch(() => false)
	if (!isLoginPage) return // Already logged in

	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

test.describe('Card modal two-column layout', () => {
	// Unique board title to avoid collision with parallel test runs
	const BOARD_TITLE = 'Modal Layout E2E Board ' + Date.now()
	const state = {
		boardId: 0,
		stackId: 0,
		cardId: 0,
		cardUrl: '',
	}

	test.beforeAll(async () => {
		// Tear down any stale board with the same name
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === BOARD_TITLE) {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		// Create board + stack + card via API
		const board = await apiPost('/boards', { title: BOARD_TITLE })
		state.boardId = board.id

		const stack = await apiPost('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id

		const card = await apiPost('/cards', {
			stackId: stack.id,
			title: 'Layout Test Card',
			description: 'This is the card description used to verify left column placement.',
		})
		state.cardId = card.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`

		// Set a due date via API
		await apiPatch(`/cards/${card.id}`, {
			duedate: new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString(),
		})

		// Set priority to High (3) via API
		await apiPatch(`/cards/${card.id}`, { priority: 3 })
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiDelete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('description and composer are in .card-modal__content / .card-modal__discussion', async ({ page }) => {
		// Use a wide viewport so the two-pane body layout is active
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)

		// Wait for the card modal content pane to be visible
		await page.waitForSelector('.card-modal__content', { timeout: 15_000 })

		// Description section lives in the left content pane
		const contentLocator = page.locator('.card-modal__content')
		await expect(contentLocator.locator('.card-modal__desc-view, .card-modal__desc-placeholder').first()).toBeVisible()

		// The new-thread composer (now a Tiptap WYSIWYG editor) lives in the right discussion pane.
		// The composer contains a .kanso-md-editor wrapping a .ProseMirror contenteditable.
		const discussion = page.locator('.card-modal__discussion')
		await expect(discussion.locator('.card-modal__composer .kanso-md-editor').first()).toBeVisible()
	})

	test('due-date pill and priority pill live in the attribute bar', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)

		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		const attrbar = page.locator('.card-modal__attrbar')

		// Priority is set to High (3) via API in beforeAll - the pill carries the modifier
		await expect(attrbar.locator('.card-modal__pill--priority-3')).toBeVisible()

		// The due-date pill is targeted by its stable data-pill hook (robust to
		// other attribute pills being added/reordered). Opening it reveals the
		// padded date popover with the date input.
		await attrbar.locator('button.card-modal__pill[data-pill="due"]').click()
		await expect(page.locator('.card-modal__popover--pad .card-modal__date-input').first()).toBeVisible({ timeout: 5_000 })
	})

	test('.card-modal__content is to the LEFT of .card-modal__discussion on wide viewport', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)

		await page.waitForSelector('.card-modal__body', { timeout: 15_000 })

		const contentBox = await page.locator('.card-modal__content').boundingBox()
		const discussionBox = await page.locator('.card-modal__discussion').boundingBox()

		expect(contentBox).not.toBeNull()
		expect(discussionBox).not.toBeNull()

		// The content pane's left edge must be strictly to the left of the discussion pane
		expect(contentBox.x).toBeLessThan(discussionBox.x)
		// And they must not overlap horizontally
		expect(contentBox.x + contentBox.width).toBeLessThanOrEqual(discussionBox.x + 4) // 4px tolerance for rounding
	})

	test('priority pill opens a popover and selecting High marks the option active', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)

		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		const attrbar = page.locator('.card-modal__attrbar')
		// The priority pill is the first pill in the attribute bar (has the flag icon).
		const priorityPill = attrbar.locator('button.card-modal__pill[data-pill="priority"]')
		await expect(priorityPill).toBeVisible()

		// Open the priority popover
		await priorityPill.click()
		const popover = page.locator('.card-modal__popover')
		await expect(popover.first()).toBeVisible({ timeout: 5_000 })

		// Set None first
		await popover.locator('.card-modal__popover-opt', { hasText: /^None$/ }).click()
		await expect(attrbar.locator('.card-modal__pill--priority-3')).toHaveCount(0, { timeout: 5_000 })

		// Now set High - the pill picks up the --priority-3 modifier
		await priorityPill.click()
		await page.locator('.card-modal__popover .card-modal__popover-opt', { hasText: /^High$/ }).click()
		await expect(attrbar.locator('.card-modal__pill--priority-3')).toBeVisible({ timeout: 5_000 })
	})

	test('body stacks to a single column on narrow viewport', async ({ page }) => {
		await page.setViewportSize({ width: 500, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)

		await page.waitForSelector('.card-modal__body', { timeout: 15_000 })

		// On narrow viewports the modal switches to a tabbed layout: only the
		// active pane is shown. The "Card" tab is active by default → the content
		// pane is visible and the discussion pane is hidden.
		await expect(page.locator('.card-modal__content')).toBeVisible()
		await expect(page.locator('.card-modal__tabbar')).toBeVisible()

		// Switching to the Discussion tab reveals the discussion pane.
		await page.locator('.card-modal__tab', { hasText: 'Discussion' }).click()
		await expect(page.locator('.card-modal__discussion')).toBeVisible({ timeout: 5_000 })
	})

	// #60 / #4057 — on a phone-width viewport the card attribute bar stays at the
	// TOP and is always visible (like desktop), with the pills WRAPPING onto
	// multiple lines instead of scrolling horizontally. Only the two original tabs
	// (Card / Discussion) remain; there is no separate "Details" tab.
	test('mobile: card attributes stay at the top and the pills wrap (#4057)', async ({ page }) => {
		// ~360px is a common phone width, below the 680px reflow breakpoint.
		await page.setViewportSize({ width: 360, height: 780 })
		await ncLogin(page)
		await page.goto(state.cardUrl)

		await page.waitForSelector('.card-modal__tabbar', { timeout: 15_000 })

		// The tab bar has exactly two tabs: Card / Discussion (no "Details").
		const tabs = page.locator('.card-modal__tabbar .card-modal__tab')
		await expect(tabs).toHaveCount(2)
		await expect(tabs.nth(0)).toHaveText(/Card/)
		await expect(tabs.nth(1)).toHaveText(/Discussion/)
		await expect(page.locator('.card-modal__tab', { hasText: 'Details' })).toHaveCount(0)

		// Tabs expose the ARIA tab role and the tablist container is a tablist.
		await expect(page.locator('.card-modal__tabbar[role="tablist"]')).toBeVisible()
		await expect(tabs.nth(0)).toHaveAttribute('role', 'tab')

		// Default lands on the Card tab: the content is shown AND the attribute bar
		// is visible at the top (no separate tap needed).
		const attrbar = page.locator('.card-modal__attrbar')
		await expect(attrbar).toBeVisible()
		await expect(page.locator('.card-modal__content')).toBeVisible()
		await expect(tabs.nth(0)).toHaveAttribute('aria-selected', 'true')

		// The attribute bar sits ABOVE the tab bar (top of the modal, like desktop).
		const attrBox = await attrbar.boundingBox()
		const tabbarBox = await page.locator('.card-modal__tabbar').boundingBox()
		expect(attrBox).not.toBeNull()
		expect(tabbarBox).not.toBeNull()
		expect(attrBox.y).toBeLessThan(tabbarBox.y)

		// The pills WRAP: the attribute bar must not overflow horizontally
		// (scrollWidth must not meaningfully exceed clientWidth).
		const overflow = await attrbar.evaluate((el) => el.scrollWidth - el.clientWidth)
		expect(overflow).toBeLessThanOrEqual(2)

		// Switch to Discussion → the composer is shown AND the attribute bar stays
		// visible at the top on this tab too.
		await tabs.nth(1).click()
		await expect(page.locator('.card-modal__discussion')).toBeVisible({ timeout: 5_000 })
		await expect(attrbar).toBeVisible()

		// Back to Card → content returns, attribute bar still visible.
		await tabs.nth(0).click()
		await expect(page.locator('.card-modal__content')).toBeVisible({ timeout: 5_000 })
		await expect(attrbar).toBeVisible()
	})

	// #4058 — on a phone-width viewport the review "Request" picker popover and the
	// pending-review verdict banner must stay FULLY within the viewport. #4057 removed
	// the mobile attrbar's `overflow-x: auto` (which silently forced overflow-y and
	// clipped the absolutely-positioned popovers); this guard asserts neither surface
	// escapes the viewport so that regression can't return.
	test('mobile: review Request popover + verdict banner stay within the viewport (#4058)', async ({ page }) => {
		// A dedicated card so requesting a review of admin doesn't perturb other tests.
		const card = await apiPost('/cards', {
			stackId: state.stackId,
			title: 'Review popover clip guard',
			description: 'Card used to verify the review picker/verdict stay on-screen on mobile.',
		})
		try {
			const cardUrl = `${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${card.id}`
			// ~360px is a common phone width, below the 680px reflow breakpoint.
			await page.setViewportSize({ width: 360, height: 780 })
			await ncLogin(page)

			// 1) BEFORE any review is requested, admin is an unrequested participant, so
			//    the review "Request" picker button is available in the attribute bar,
			//    which stays visible at the top on mobile (no separate Details tab).
			await page.goto(cardUrl)
			await page.waitForSelector('.card-modal__tabbar', { timeout: 15_000 })
			await expect(page.locator('.card-modal__attrbar')).toBeVisible({ timeout: 5_000 })

			const requestBtn = page.locator('.card-modal__attr-right button.card-modal__pill--dashed', { hasText: 'Request' })
			await expect(requestBtn).toBeVisible({ timeout: 5_000 })
			await requestBtn.click()

			// The custom (absolutely-positioned) review picker popover must render fully
			// on-screen — no clipping by an overflow ancestor, no spilling past either
			// viewport edge.
			const popover = page.locator('.card-modal__popover--right')
			await expect(popover).toBeVisible({ timeout: 5_000 })
			const vw = page.viewportSize().width
			const popBox = await popover.boundingBox()
			expect(popBox).not.toBeNull()
			expect(popBox.x).toBeGreaterThanOrEqual(0) // not clipped off the left edge
			expect(popBox.x + popBox.width).toBeLessThanOrEqual(vw + 1) // not past the right edge
			expect(popBox.height).toBeGreaterThan(0) // actually rendered (not overflow-clipped to 0)

			// 2) Now request a review of admin so the pending-review VERDICT banner
			//    ("Your review is requested" → Approve / Request changes) appears. It sits
			//    at the top of the Card tab as normal block flow, but guard it anyway so a
			//    future overflow ancestor can't clip it off-screen.
			await apiPut(`/cards/${card.id}/reviews/admin`, {})
			await page.goto(cardUrl)
			const verdict = page.locator('.card-modal__verdict').first()
			await expect(verdict).toBeVisible({ timeout: 15_000 })
			const verdictBox = await verdict.boundingBox()
			expect(verdictBox).not.toBeNull()
			expect(verdictBox.x).toBeGreaterThanOrEqual(0)
			expect(verdictBox.x + verdictBox.width).toBeLessThanOrEqual(vw + 1)

			// The verdict actions (Approve / Request changes) must also be fully on-screen.
			const actions = page.locator('.card-modal__verdict-actions').first()
			await expect(actions).toBeVisible()
			const actionsBox = await actions.boundingBox()
			expect(actionsBox).not.toBeNull()
			expect(actionsBox.x).toBeGreaterThanOrEqual(0)
			expect(actionsBox.x + actionsBox.width).toBeLessThanOrEqual(vw + 1)
		} finally {
			await apiDelete(`/cards/${card.id}`).catch(() => {})
		}
	})

	// Desktop regression guard: on a wide viewport there is NO tab bar and the
	// attribute bar stays visible above the side-by-side content|discussion.
	test('desktop: attribute bar is always visible and there is no tab bar (#4057)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)

		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		await expect(page.locator('.card-modal__attrbar')).toBeVisible()
		// The mobile tab bar exists in the DOM but is display:none on desktop.
		await expect(page.locator('.card-modal__tabbar')).toBeHidden()
	})

	test('round clear/× buttons are circles, not ovals (#3492)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)

		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		// Open the due-date pill (targeted by its stable data-pill hook; the card
		// has a due date set in beforeAll) to reveal its round clear (×) button.
		await page.locator('.card-modal__attrbar button.card-modal__pill[data-pill="due"]').click()
		const clearBtn = page.locator('.card-modal__field-clear').first()
		await expect(clearBtn).toBeVisible({ timeout: 5_000 })

		// A circle: width and height must be equal (±1px), never squished into an oval.
		const box = await clearBtn.boundingBox()
		expect(box).not.toBeNull()
		expect(Math.abs(box.width - box.height)).toBeLessThanOrEqual(1)
	})

	test('Escape closes an open attribute popover without closing the modal (#3509)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		// Open an attribute popover (the due-date pill).
		await page.locator('.card-modal__attrbar button.card-modal__pill[data-pill="due"]').click()
		await expect(page.locator('.card-modal__popover').first()).toBeVisible({ timeout: 5_000 })

		// First Escape closes ONLY the popover — the modal stays open.
		await page.locator('.card-modal').press('Escape')
		await expect(page.locator('.card-modal__popover')).toHaveCount(0)
		await expect(page.locator('.card-modal')).toBeVisible()
		await expect(page).toHaveURL(new RegExp(`/card/${state.cardId}`))

		// Second Escape (no popover open) closes the modal.
		await page.locator('.card-modal').press('Escape')
		await expect(page).not.toHaveURL(new RegExp(`/card/${state.cardId}`), { timeout: 8_000 })
	})

	// The dark backdrop is the strip of the .modal-wrapper to the LEFT of the centred
	// .modal-container. The wrapper's own top band is covered by NcModal's .modal-header,
	// so the corner is NOT backdrop — compute a point midway between the wrapper's left
	// edge and the container's left edge, at the container's vertical centre. That point
	// resolves to the .modal-wrapper itself (the element our close-on-backdrop handler
	// keys off), which is what a user clicking "outside the card" actually hits.
	async function backdropPoint(page) {
		return page.evaluate(() => {
			const wrapper = document.querySelector('.modal-wrapper')
			const container = document.querySelector('.modal-container')
			const wb = wrapper.getBoundingClientRect()
			const cb = container.getBoundingClientRect()
			return { x: (wb.x + cb.x) / 2, y: cb.y + cb.height / 2 }
		})
	}

	test('clicking the backdrop closes the card modal (#3656)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })
		await expect(page).toHaveURL(new RegExp(`/card/${state.cardId}`))

		// A genuine backdrop press closes the card, same effect as the X.
		const pt = await backdropPoint(page)
		await page.mouse.click(pt.x, pt.y)

		await expect(page).not.toHaveURL(new RegExp(`/card/${state.cardId}`), { timeout: 8_000 })
	})

	test('with a popover open, a backdrop click closes only the popover, not the modal (#3656)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal__attrbar', { timeout: 15_000 })

		// Open an attribute popover (the due-date pill).
		await page.locator('.card-modal__attrbar button.card-modal__pill[data-pill="due"]').click()
		await expect(page.locator('.card-modal__popover').first()).toBeVisible({ timeout: 5_000 })

		// A backdrop press while a popover is open dismisses the popover first,
		// mirroring the Escape precedence — the modal must stay open.
		let pt = await backdropPoint(page)
		await page.mouse.click(pt.x, pt.y)

		await expect(page.locator('.card-modal__popover')).toHaveCount(0)
		await expect(page.locator('.card-modal')).toBeVisible()
		await expect(page).toHaveURL(new RegExp(`/card/${state.cardId}`))

		// A second backdrop press (no popover open) now closes the modal.
		pt = await backdropPoint(page)
		await page.mouse.click(pt.x, pt.y)
		await expect(page).not.toHaveURL(new RegExp(`/card/${state.cardId}`), { timeout: 8_000 })
	})

	// #60 — on a phone-width viewport the card-detail header must reflow: the
	// title must NOT wrap one character per line (its box must be wider than it is
	// tall), and the action cluster / teleported close (X) must not overlap the
	// title. A long, unbroken-ish title is used so a broken layout is unmissable.
	test('mobile: card header reflows — title stays horizontal and actions do not overlap it (#60)', async ({ page }) => {
		const longTitle = 'Refactor the notification delivery pipeline for realtime updates'
		const card = await apiPost('/cards', {
			stackId: state.stackId,
			title: longTitle,
			description: 'Long-title card used to verify the mobile header does not break.',
		})
		try {
			const cardUrl = `${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${card.id}`
			// ~360px is a common phone width and below the 680px reflow breakpoint.
			await page.setViewportSize({ width: 360, height: 780 })
			await ncLogin(page)
			await page.goto(cardUrl)

			const title = page.locator('.card-modal__title')
			await expect(title).toBeVisible({ timeout: 15_000 })

			// 1) The title renders horizontally, not one letter per line. A vertical
			//    (one-char-per-line) wrap makes the box taller than it is wide.
			const titleBox = await title.boundingBox()
			expect(titleBox).not.toBeNull()
			expect(titleBox.width).toBeGreaterThan(titleBox.height)

			// 2) The action cluster must not horizontally overlap the title column.
			//    After the reflow it sits on its own row below the title, so their
			//    bounding boxes must not intersect.
			const actions = page.locator('.card-modal__header-actions')
			await expect(actions).toBeVisible()
			const actionsBox = await actions.boundingBox()
			expect(actionsBox).not.toBeNull()
			const intersects = (a, b) => (
				a.x < b.x + b.width && a.x + a.width > b.x
				&& a.y < b.y + b.height && a.y + a.height > b.y
			)
			expect(intersects(titleBox, actionsBox)).toBe(false)

			// 3) The teleported NcModal close (X) must not overlap the title either —
			//    the header reserves right padding so the breadcrumb/title clear it.
			const closeBtn = page.locator('.modal-container .modal-header button.modal-close, .modal-container button.header-close').first()
			if (await closeBtn.count()) {
				const closeBox = await closeBtn.boundingBox()
				if (closeBox) {
					expect(intersects(titleBox, closeBox)).toBe(false)
				}
			}
		} finally {
			await apiDelete(`/cards/${card.id}`).catch(() => {})
		}
	})

	test('a text-selection drag that ends on the backdrop does NOT close the modal (#3656)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal__content', { timeout: 15_000 })
		await expect(page).toHaveURL(new RegExp(`/card/${state.cardId}`))

		// Press down INSIDE the modal content (on the title), drag out to the
		// backdrop, and release there — as when selecting text. The close-on-backdrop
		// handler keys off the mousedown TARGET being the wrapper, so a press that
		// STARTED inside the content never triggers a close.
		const titleBox = await page.locator('.card-modal__title').boundingBox()
		expect(titleBox).not.toBeNull()
		const pt = await backdropPoint(page)

		await page.mouse.move(titleBox.x + 4, titleBox.y + titleBox.height / 2)
		await page.mouse.down()
		await page.mouse.move(pt.x, pt.y, { steps: 12 })
		await page.mouse.up()

		// The modal must still be open — the drag started inside, not on the backdrop.
		await expect(page.locator('.card-modal')).toBeVisible()
		await expect(page).toHaveURL(new RegExp(`/card/${state.cardId}`))
	})
})
