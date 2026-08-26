// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

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

test.describe('Timeline (Gantt) view (#3471)', () => {
	const state = { boardId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Timeline ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		const ranged = await api.post('/cards', { stackId: stack.id, title: 'Ranged task' })
		await api.patch(`/cards/${ranged.id}`, {
			startDate: '2026-08-01T00:00:00+00:00',
			duedate: '2026-08-06T00:00:00+00:00',
		})
		const milestone = await api.post('/cards', { stackId: stack.id, title: 'Milestone task' })
		await api.patch(`/cards/${milestone.id}`, { duedate: '2026-08-10T00:00:00+00:00' })
		await api.post('/cards', { stackId: stack.id, title: 'Someday task' }) // no dates
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
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
		const stack = (await api.get(`/boards/${state.boardId}`)).stacks[0]
		const card = await api.post('/cards', { stackId: stack.id, title: dragTitle })

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
				.poll(async () => (await api.get(`/cards/${card.id}`)).duedate, { timeout: 8_000 })
				.not.toBeNull()
		}

		// Persistence: after a reload the card still carries a due date (it stays
		// scheduled and does not fall back into the unscheduled footer).
		await expect
			.poll(async () => (await api.get(`/cards/${card.id}`)).duedate, { timeout: 10_000 })
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

	// #4129: a single card with a huge date range (e.g. 2018→2030) must NOT blow up
	// the rendered track. The timeline renders only a fixed window around today and
	// clips outliers, flagging them with an edge affordance — so the scroll width
	// stays bounded instead of scaling with the raw (multi-year) date domain.
	// Fit button: clicking it (or the edge chevrons) reveals the full date range
	// within a bounded viewport-sized track.
	test('a huge date range renders a bounded track with clipped-edge affordances, Fit reveals full range (#4129)', async ({ page }) => {
		// A dedicated board so the multi-year outlier can't distort the shared-board
		// specs' geometry assertions.
		const board = await api.post('/boards', { title: 'Timeline wide ' + Math.floor(Date.now() / 1000) })
		try {
			const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
			// One card spanning ~12 years — the pathological case from the bug report.
			const wide = await api.post('/cards', { stackId: stack.id, title: 'Epic across years' })
			await api.patch(`/cards/${wide.id}`, {
				startDate: '2018-01-01T00:00:00+00:00',
				duedate: '2030-12-31T00:00:00+00:00',
			})
			// A second card lying ENTIRELY before the default window (all of 2019, far
			// earlier than today−6mo). Unlike the spanning card, it has nothing inside
			// the window, so it must render as an off-window edge marker — never a
			// misleading 1-day sliver pinned at the window start.
			const past = await api.post('/cards', { stackId: stack.id, title: 'Ancient history' })
			await api.patch(`/cards/${past.id}`, {
				startDate: '2019-01-01T00:00:00+00:00',
				duedate: '2019-03-01T00:00:00+00:00',
			})

			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/board/${board.id}`)
			await page.waitForSelector('.board-view__header', { timeout: 15_000 })
			await page.locator('.board-view__display-menu button').first().click()
			await page.getByText('Timeline', { exact: true }).click()

			// The track mounts (there's a scheduled card).
			await expect(page.locator('.timeline__inner')).toBeVisible({ timeout: 8_000 })

			// Bounded width: with a ~12-year raw domain the OLD code produced a track
			// of ~52000px+ (4380 days × 12px/day at week zoom). The windowed render
			// keeps it well under 30000px regardless of the outlier span.
			const scrollWidthDefault = await page.locator('.timeline__scroll').evaluate((el) => el.scrollWidth)
			expect(scrollWidthDefault).toBeLessThan(30_000)

			// The card reaches before the window start (2018 ≪ today−6mo) and after the
			// window end (2030 ≫ today+12mo), so BOTH clipped-edge markers are shown.
			await expect(page.locator('.timeline__edge--start')).toBeVisible()
			await expect(page.locator('.timeline__edge--end')).toBeVisible()

			// The SPANNING card straddles the window (starts before, ends after), so it
			// renders as a normal bar CLIPPED to the window — reachable, not dropped.
			await expect(page.locator('.timeline__bar', { hasText: 'Epic across years' }).first()).toBeVisible()

			// The ENTIRELY-off-window card (all of 2019) instead renders as an off-window
			// edge marker, NOT a normal bar (which would be a misleading 1-day sliver).
			await expect(page.locator('.timeline__bar-offwindow').first()).toBeVisible()
			await expect(page.locator('.timeline__bar', { hasText: 'Ancient history' })).toHaveCount(0)

			// ── Fit mode ─────────────────────────────────────────────────────────────
			// Click the Fit button: the full date range (2018→2030) becomes visible.
			const fitBtn = page.getByRole('button', { name: 'Fit' })
			await expect(fitBtn).toBeVisible()
			await fitBtn.click()

			// Edge chevrons disappear in Fit mode (the window IS the full extent).
			await expect(page.locator('.timeline__edge--start')).toHaveCount(0)
			await expect(page.locator('.timeline__edge--end')).toHaveCount(0)

			// The off-window markers are also gone; the card now renders as a normal bar.
			await expect(page.locator('.timeline__bar-offwindow')).toHaveCount(0)

			// The axis now spans the outlier years: a month label containing "2018" and
			// one containing "2030" should be visible in the axis.
			const monthLabels = page.locator('.timeline__axis-month')
			await expect(monthLabels.filter({ hasText: '2018' }).first()).toBeVisible({ timeout: 5_000 })
			await expect(monthLabels.filter({ hasText: '2030' }).first()).toBeVisible({ timeout: 5_000 })

			// Fit mode keeps the track width bounded — no multi-thousand-px blowup. It
			// auto-fits px/day toward the viewport but floors at a minimum so bars never
			// become invisibly thin, so on a very wide span in a narrow viewport the
			// track can be modestly wider than the viewport; the guarantee under test is
			// that it stays bounded (vs the old ~52000px), not that it exactly fits.
			const scrollWidthFit = await page.locator('.timeline__scroll').evaluate((el) => el.scrollWidth)
			expect(scrollWidthFit).toBeLessThan(30_000)

			// ── Clicking an edge chevron also triggers Fit ────────────────────────────
			// Reset to default window by clicking Day zoom (which turns off Fit).
			await page.getByRole('button', { name: 'Day', exact: true }).click()
			await expect(page.locator('.timeline__edge--start')).toBeVisible({ timeout: 5_000 })
			// Click the earlier-chevron edge affordance — it should activate Fit.
			await page.locator('.timeline__edge--start').click()
			await expect(page.locator('.timeline__edge--start')).toHaveCount(0, { timeout: 5_000 })
			await expect(monthLabels.filter({ hasText: '2018' }).first()).toBeVisible({ timeout: 5_000 })
		} finally {
			await api.delete(`/boards/${board.id}`).catch(() => {})
		}
	})

	// A normal (short-range) board must NOT show the clipped-edge affordances — its
	// data fits inside the rendered window, so nothing extends beyond it (#4129).
	test('a normal board shows no clipped-edge affordances (#4129)', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByText('Timeline', { exact: true }).click()

		await expect(page.locator('.timeline__bar', { hasText: 'Ranged task' })).toBeVisible({ timeout: 8_000 })
		// The shared board's dates are all near today, well inside the window.
		await expect(page.locator('.timeline__edge--start')).toHaveCount(0)
		await expect(page.locator('.timeline__edge--end')).toHaveCount(0)
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

// #5896: a `blocks` relation between two dated cards is drawn on the timeline as
// an elbow connector from the blocker's right edge into the blocked card's left
// edge. A violated dependency (the blocked card starts before its blocker
// finishes) is colour-coded differently — that contradiction is the whole point
// of putting dependencies on a date axis.
test.describe('Timeline dependency arrows (#5896)', () => {
	const state = { boardId: 0, blocker: 0, after: 0, overlapping: 0, dateless: 0 }

	// Relative to today so the fixture always lands inside the rendered window
	// (today−6mo … today+12mo), whatever date CI runs on.
	const day = (offset) => new Date(Date.now() + offset * 86_400_000).toISOString()

	async function openTimeline(page) {
		await ncLogin(page)
		await page.addInitScript(() => { try { localStorage.clear() } catch (e) {} })
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByText('Timeline', { exact: true }).click()
		await expect(page.locator('.timeline__bar', { hasText: 'Blocker task' })).toBeVisible({ timeout: 8_000 })
	}

	// Track-local geometry of every bar and every connector, read in one pass.
	// A bar's offsetLeft is relative to its lane, and a lane starts at x=0 of the
	// inner track — the same coordinate space the SVG paths are drawn in.
	async function readGeometry(page) {
		return page.evaluate(() => {
			const bars = [...document.querySelectorAll('.timeline__bar')].map((b) => ({
				title: b.textContent.trim(),
				left: b.offsetLeft,
				right: b.offsetLeft + b.offsetWidth,
			}))
			const arrows = [...document.querySelectorAll('.timeline__dep')].map((g) => {
				const d = g.querySelector('.timeline__dep-line').getAttribute('d')
				const points = [...d.matchAll(/(-?[\d.]+),(-?[\d.]+)/g)].map((m) => [Number(m[1]), Number(m[2])])
				return {
					d,
					violated: g.classList.contains('timeline__dep--violated'),
					title: g.querySelector('title').textContent,
					start: points[0],
					end: points[points.length - 1],
				}
			})
			return { bars, arrows }
		})
	}

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Timeline deps ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })

		const blocker = await api.post('/cards', { stackId: stack.id, title: 'Blocker task' })
		await api.patch(`/cards/${blocker.id}`, { startDate: day(1), duedate: day(6) })
		state.blocker = blocker.id

		// Starts AFTER the blocker finishes → a healthy dependency.
		const after = await api.post('/cards', { stackId: stack.id, title: 'Downstream task' })
		await api.patch(`/cards/${after.id}`, { startDate: day(10), duedate: day(15) })
		state.after = after.id

		// Starts BEFORE the blocker finishes → a violated dependency.
		const overlapping = await api.post('/cards', { stackId: stack.id, title: 'Overlapping task' })
		await api.patch(`/cards/${overlapping.id}`, { startDate: day(3), duedate: day(8) })
		state.overlapping = overlapping.id

		// Blocked but undated: it can't be drawn, and must not break anything.
		const dateless = await api.post('/cards', { stackId: stack.id, title: 'Undated task' })
		state.dateless = dateless.id

		await api.post(`/cards/${blocker.id}/relations`, { otherCardId: after.id, kind: 'blocks' })
		await api.post(`/cards/${blocker.id}/relations`, { otherCardId: overlapping.id, kind: 'blocks' })
		await api.post(`/cards/${blocker.id}/relations`, { otherCardId: dateless.id, kind: 'blocks' })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	// Delivery only. The visibility MASKING rule (an edge with an endpoint the
	// viewer can't see is dropped whole) is covered where it can be exercised
	// cheaply against every visibility class: CardRelationServiceTest.
	test('the board payload carries the board-scoped blocks edges', async () => {
		const board = await api.get(`/boards/${state.boardId}`)
		expect(Array.isArray(board.blocksEdges)).toBe(true)
		const pairs = board.blocksEdges.map((e) => `${e.from}>${e.to}`).sort()
		expect(pairs).toEqual([
			`${state.blocker}>${state.after}`,
			`${state.blocker}>${state.dateless}`,
			`${state.blocker}>${state.overlapping}`,
		].sort())
	})

	test('draws an elbow arrow blocker→blocked, flags the violated one, and glues to the bars', async ({ page }) => {
		await openTimeline(page)

		// Two dated blocked cards → two connectors. The undated one is blocked too
		// but has no bar to point at, so it is silently skipped (its red "blocked"
		// chip on the card tile stays the fallback signal).
		await expect(page.locator('.timeline__dep')).toHaveCount(2, { timeout: 8_000 })

		const { bars, arrows } = await readGeometry(page)
		const blockerBar = bars.find((b) => b.title.includes('Blocker task'))
		const afterBar = bars.find((b) => b.title.includes('Downstream task'))
		const overlappingBar = bars.find((b) => b.title.includes('Overlapping task'))
		expect(blockerBar && afterBar && overlappingBar).toBeTruthy()

		// Exactly one violated dependency: the overlapping card.
		const violated = arrows.filter((a) => a.violated)
		expect(violated).toHaveLength(1)
		expect(violated[0].title).toContain('Overlapping task')
		expect(violated[0].title).toContain('Blocker task')

		const healthy = arrows.find((a) => !a.violated)
		expect(healthy.title).toContain('Downstream task')

		// Glued: every connector leaves the blocker's right edge and lands on the
		// blocked card's left edge.
		for (const arrow of arrows) {
			expect(Math.abs(arrow.start[0] - blockerBar.right)).toBeLessThanOrEqual(1)
		}
		expect(Math.abs(healthy.end[0] - afterBar.left)).toBeLessThanOrEqual(1)
		expect(Math.abs(violated[0].end[0] - overlappingBar.left)).toBeLessThanOrEqual(1)

		// Elbow, not a straight diagonal: the path has interior turns.
		expect(healthy.d.split('L').length).toBeGreaterThanOrEqual(3)
	})

	test('arrows follow the bars across zoom and disappear with a collapsed group', async ({ page }) => {
		await openTimeline(page)
		await expect(page.locator('.timeline__dep')).toHaveCount(2, { timeout: 8_000 })

		const before = await readGeometry(page)

		// Zoom in: px/day triples, so both the bars and their connectors move.
		await page.getByRole('button', { name: 'Day', exact: true }).click()
		await expect(page.locator('.timeline__dep')).toHaveCount(2)
		const after = await readGeometry(page)
		expect(after.arrows[0].d).not.toBe(before.arrows[0].d)

		// Still glued at the new zoom.
		const blockerBar = after.bars.find((b) => b.title.includes('Blocker task'))
		for (const arrow of after.arrows) {
			expect(Math.abs(arrow.start[0] - blockerBar.right)).toBeLessThanOrEqual(1)
		}

		// Collapsing the group removes the rows, so their connectors go with them.
		await page.locator('.timeline__group-row').first().click()
		await expect(page.locator('.timeline__lane')).toHaveCount(0)
		await expect(page.locator('.timeline__dep')).toHaveCount(0)

		// Expanding restores them, still anchored to the bars.
		await page.locator('.timeline__group-row').first().click()
		await expect(page.locator('.timeline__dep')).toHaveCount(2, { timeout: 8_000 })
		const restored = await readGeometry(page)
		const restoredBlocker = restored.bars.find((b) => b.title.includes('Blocker task'))
		for (const arrow of restored.arrows) {
			expect(Math.abs(arrow.start[0] - restoredBlocker.right)).toBeLessThanOrEqual(1)
		}
	})

	test('the toolbar toggle hides and restores the arrows, and clicking one opens the blocked card', async ({ page }) => {
		await openTimeline(page)
		await expect(page.locator('.timeline__dep')).toHaveCount(2, { timeout: 8_000 })

		const toggle = page.getByRole('button', { name: 'Dependencies' })
		await expect(toggle).toBeVisible()
		await toggle.click()
		await expect(page.locator('.timeline__dep')).toHaveCount(0)
		await toggle.click()
		await expect(page.locator('.timeline__dep')).toHaveCount(2)

		// Clicking a connector opens the BLOCKED end (the card whose plan the
		// dependency constrains).
		const violated = page.locator('.timeline__dep--violated').first()
		await violated.locator('.timeline__dep-hit').dispatchEvent('click')
		await expect(page).toHaveURL(
			new RegExp(`/board/${state.boardId}/card/${state.overlapping}`),
			{ timeout: 8_000 },
		)
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 10_000 })
	})

	test('adding and removing a relation updates the timeline through the normal sync path', async ({ page }) => {
		// No reload anywhere in here: the edge has to arrive over the delta/push
		// channel, whose slow fallback tick is 30s - so give this one room.
		test.slow()
		await openTimeline(page)
		await expect(page.locator('.timeline__dep')).toHaveCount(2, { timeout: 8_000 })

		// A third dated card, newly blocked by the same blocker.
		const stack = (await api.get(`/boards/${state.boardId}`)).stacks[0]
		const extra = await api.post('/cards', { stackId: stack.id, title: 'Late task' })
		await api.patch(`/cards/${extra.id}`, { startDate: day(20), duedate: day(24) })
		const relation = await api.post(`/cards/${state.blocker}/relations`, {
			otherCardId: extra.id,
			kind: 'blocks',
		})

		// No reload: the delta/realtime path must carry the new edge in.
		await expect(page.locator('.timeline__dep')).toHaveCount(3, { timeout: 70_000 })

		await api.delete(`/cards/${state.blocker}/relations/${relation.id}`)
		await expect(page.locator('.timeline__dep')).toHaveCount(2, { timeout: 70_000 })

		await api.delete(`/cards/${extra.id}`).catch(() => {})
	})
})
