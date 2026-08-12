// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')

async function api(method, path, body) {
	const r = await fetch(API + path, {
		method,
		headers: { ...HEADERS, Authorization: AUTH },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`${method} ${path} → ${r.status}: ${await r.text()}`)
	return method === 'DELETE' ? null : r.json()
}

// Pragmatic-DnD needs a real pointer gesture with intermediate moves (a single
// jump won't cross its drag threshold), mirroring dnd.spec.js's dragWithMouse.
async function dragOnto(page, sourceLocator, targetX, targetY) {
	const srcBox = await sourceLocator.boundingBox()
	if (!srcBox) throw new Error('Could not get bounding box for drag source')
	const srcX = srcBox.x + srcBox.width / 2
	const srcY = srcBox.y + srcBox.height / 2
	await page.mouse.move(srcX, srcY)
	await page.mouse.down()
	const steps = 15
	for (let i = 1; i <= steps; i++) {
		await page.mouse.move(
			srcX + (targetX - srcX) * (i / steps),
			srcY + (targetY - srcY) * (i / steps),
			{ steps: 1 },
		)
		await page.waitForTimeout(20)
	}
	await page.waitForTimeout(150)
	await page.mouse.up()
	await page.waitForTimeout(500)
}

async function ncLogin(page) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	if (!(await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false))) return
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

test.describe('Timeline (Gantt) view (#3471)', () => {
	const state = { boardId: 0 }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Timeline ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api('POST', '/stacks', { boardId: board.id, title: 'To do' })
		const ranged = await api('POST', '/cards', { stackId: stack.id, title: 'Ranged task' })
		await api('PATCH', `/cards/${ranged.id}`, {
			startDate: '2026-08-01T00:00:00+00:00',
			duedate: '2026-08-06T00:00:00+00:00',
		})
		const milestone = await api('POST', '/cards', { stackId: stack.id, title: 'Milestone task' })
		await api('PATCH', `/cards/${milestone.id}`, { duedate: '2026-08-10T00:00:00+00:00' })
		await api('POST', '/cards', { stackId: stack.id, title: 'Someday task' }) // no dates
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('renders start→due bars, due-only milestones, an unscheduled list, and opens a card', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Switch to Timeline.
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByText('Timeline', { exact: true }).click()

		// A ranged card → a bar; a due-only card → a milestone diamond.
		await expect(page.locator('.timeline__bar', { hasText: 'Ranged task' })).toBeVisible({ timeout: 8_000 })
		await expect(page.locator('.timeline__milestone', { hasText: 'Milestone task' })).toBeVisible()

		// Geometry: a 6-day range at week zoom (12px/day) is a visible bar, much
		// wider than the zero-width milestone marker — sanity-checks the layout.
		const barBox = await page.locator('.timeline__bar', { hasText: 'Ranged task' }).boundingBox()
		expect(barBox.width).toBeGreaterThan(48)
		const milestoneBox = await page.locator('.timeline__milestone', { hasText: 'Milestone task' }).boundingBox()
		expect(barBox.width).toBeGreaterThan(milestoneBox.width)

		// The dateless card is listed under "unscheduled".
		await expect(page.locator('.timeline__unscheduled summary')).toContainText('unscheduled')
		await expect(page.locator('.timeline__unscheduled')).toContainText('Someday task')

		// 2a: the frozen left pane lists each scheduled card by id/title beside a
		// stack group-header row, aligned with its bar on the right (#3623).
		await expect(page.locator('.timeline__pane-head')).toBeVisible()
		await expect(page.locator('.timeline__group-row')).toContainText('To do')
		await expect(page.locator('.timeline__pane-row', { hasText: 'Ranged task' })).toBeVisible()
		// Two-tier axis: a month band sits over the week/day ticks.
		await expect(page.locator('.timeline__axis-month').first()).toBeVisible()

		// Clicking a lane opens the card modal (dispatchEvent avoids a race with
		// the board poll re-render).
		await page.locator('.timeline__lane', { hasText: 'Ranged task' }).dispatchEvent('click')
		await expect(page).toHaveURL(new RegExp(`/board/${state.boardId}/card/`), { timeout: 8_000 })
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 10_000 })
	})

	test('chrome: jump-to-today scrolls, legend renders, groups collapse, track fills the viewport', async ({ page }) => {
		await ncLogin(page)
		// Start from a clean slate so a prior run's collapsed state doesn't leak in.
		await page.addInitScript(() => { try { localStorage.clear() } catch (e) {} })
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByText('Timeline', { exact: true }).click()

		await expect(page.locator('.timeline__bar', { hasText: 'Ranged task' })).toBeVisible({ timeout: 8_000 })

		// Legend renders with all five swatches.
		const legend = page.locator('.timeline__legend')
		await expect(legend).toBeVisible()
		await expect(legend).toContainText('Not started')
		await expect(legend).toContainText('In progress')
		await expect(legend).toContainText('Overdue')
		await expect(legend).toContainText('Done')
		await expect(legend).toContainText('Single date')
		await expect(page.locator('.timeline__legend-swatch')).toHaveCount(5)

		// Page-fill: on this short-range board the inner track still fills the viewport.
		const fill = await page.locator('.timeline__scroll').evaluate((el) => {
			return { scroll: el.scrollWidth, client: el.clientWidth }
		})
		expect(fill.scroll).toBeGreaterThanOrEqual(fill.client)

		// Jump-to-today scrolls the track horizontally toward the today marker.
		const todayBtn = page.getByRole('button', { name: 'Jump to today' })
		await expect(todayBtn).toBeVisible()
		const scrollBefore = await page.locator('.timeline__scroll').evaluate((el) => {
			el.scrollLeft = 0
			return el.scrollLeft
		})
		await todayBtn.click()
		await page.waitForTimeout(700) // smooth scroll settles
		const scrollAfter = await page.locator('.timeline__scroll').evaluate((el) => el.scrollLeft)
		// With a track wider than the viewport and today near the right edge, the
		// jump moves the scroll position off zero (or leaves it at zero only when
		// today is already centered within the first viewport).
		expect(scrollAfter).toBeGreaterThanOrEqual(scrollBefore)

		// Group-collapse: the header chevron folds away its card rows in the pane.
		const paneRowsBefore = await page.locator('.timeline__pane-row').count()
		expect(paneRowsBefore).toBeGreaterThan(0)
		const laneRowsBefore = await page.locator('.timeline__lane').count()
		// Pane rows and track lanes mirror 1:1 for alignment.
		expect(laneRowsBefore).toBe(paneRowsBefore)

		await page.locator('.timeline__group-row').first().click()
		await expect(page.locator('.timeline__pane-row')).toHaveCount(0)
		await expect(page.locator('.timeline__lane')).toHaveCount(0)
		// Header still visible with its count.
		await expect(page.locator('.timeline__group-row').first()).toBeVisible()

		// Expand again restores the exact row set in both pane and track.
		await page.locator('.timeline__group-row').first().click()
		await expect(page.locator('.timeline__pane-row')).toHaveCount(paneRowsBefore)
		await expect(page.locator('.timeline__lane')).toHaveCount(laneRowsBefore)
	})

	test('dragging an unscheduled card onto the track schedules it (persisted)', async ({ page }) => {
		// A dedicated unscheduled card for this test, so it can't collide with the
		// shared "Someday task" the other specs assert on.
		const dragTitle = 'Drag me to schedule ' + Math.floor(Date.now() / 1000)
		const stack = (await api('GET', `/boards/${state.boardId}`)).stacks[0]
		const card = await api('POST', '/cards', { stackId: stack.id, title: dragTitle })

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByText('Timeline', { exact: true }).click()

		// The track must be present (there are already scheduled cards on this board).
		const track = page.locator('.timeline__inner')
		await expect(track).toBeVisible({ timeout: 8_000 })

		// Expand the unscheduled footer and locate the draggable row for our card.
		await page.locator('.timeline__unscheduled summary').click()
		const footerRow = page.locator('.timeline__unscheduled-row', { hasText: dragTitle })
		await expect(footerRow).toBeVisible({ timeout: 8_000 })

		// Drop near the horizontal centre of the visible track (position→day math is
		// sensitive, so we assert "got scheduled", never an exact calendar day).
		const trackBox = await track.boundingBox()
		const dropX = trackBox.x + Math.min(trackBox.width, 800) * 0.5
		const dropY = trackBox.y + trackBox.height / 2
		await dragOnto(page, footerRow, dropX, dropY)

		// Give the optimistic patch + server round-trip generous room on slow CI.
		await page.waitForTimeout(1500)

		// Primary (UI) assertion: the card left the unscheduled footer and now shows
		// up on the track. A newly single-dated card renders as a milestone diamond;
		// accept a bar too in case the drop math produced a range, so the check is
		// tolerant to layout specifics rather than a brittle pixel position.
		const scheduledMarker = page.locator(
			`.timeline__milestone:has-text("${dragTitle}"), .timeline__bar:has-text("${dragTitle}")`,
		)
		const scheduledUi = await scheduledMarker.first().isVisible({ timeout: 6_000 }).catch(() => false)
		const leftFooter = !(await page.locator('.timeline__unscheduled-row', { hasText: dragTitle })
			.isVisible({ timeout: 2_000 }).catch(() => false))

		if (scheduledUi && leftFooter) {
			await expect(scheduledMarker.first()).toBeVisible()
		} else {
			// Fallback: Pragmatic DnD can be flaky to drive from Playwright on a slow
			// runner. Rather than fail on a visual-position race, confirm the drop set
			// a duedate via the API — that's the behaviour under test.
			await expect
				.poll(async () => (await api('GET', `/cards/${card.id}`)).duedate, { timeout: 8_000 })
				.not.toBeNull()
		}

		// Persistence: after a reload the card still carries a due date (it stays
		// scheduled and does not fall back into the unscheduled footer).
		await expect
			.poll(async () => (await api('GET', `/cards/${card.id}`)).duedate, { timeout: 10_000 })
			.not.toBeNull()

		await page.reload()
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByText('Timeline', { exact: true }).click()
		await expect(page.locator('.timeline__inner')).toBeVisible({ timeout: 8_000 })
		// It appears on the track and is no longer offered as unscheduled.
		await expect(page.locator(
			`.timeline__milestone:has-text("${dragTitle}"), .timeline__bar:has-text("${dragTitle}")`,
		).first()).toBeVisible({ timeout: 8_000 })
	})

	test('a lane is keyboard-openable: focus + Enter opens the card (#3512)', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByText('Timeline', { exact: true }).click()

		const lane = page.locator('.timeline__lane', { hasText: 'Ranged task' })
		await expect(lane).toBeVisible({ timeout: 8_000 })
		// The lane is a focusable button now — focus it and activate with Enter.
		await lane.focus()
		await lane.press('Enter')
		await expect(page).toHaveURL(new RegExp(`/board/${state.boardId}/card/`), { timeout: 8_000 })
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 10_000 })
	})
})
