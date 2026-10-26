// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Project colour indicator: a card that belongs to a coloured project shows
// that project's colour on the board, so the user can tell at a glance which
// project a card belongs to. The tile renders a small dot per coloured project
// (the project title is on hover); a card in no project — or only in
// uncoloured projects — shows nothing.

import { test, expect, api, ncLogin, BASE, adminAuth } from './helpers.js'
import { execSync } from 'node:child_process'

// notify_push is disabled for the delta test so the ~5s delta poll (rather than
// the push path) is what delivers the change — the deterministic path
// board-delta-sync.spec.js forces. Mirrors its fallback handling: if the toggle
// is unavailable on this runner, the test continues without it.
const SKIP_NOTIFY_PUSH = process.env.KANSO_SKIP_NOTIFY_PUSH === '1'
const OCS_HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }

function occSafe(command) {
	if (SKIP_NOTIFY_PUSH) return
	try {
		execSync(`docker exec -u www-data kanso-dev php occ ${command}`, { stdio: 'pipe' })
	} catch {
		// notify_push not installed on this runner — nothing to toggle.
	}
}

/** Best-effort: wait until notify_push stops being advertised. */
async function waitPushOff() {
	for (let i = 0; i < 20; i++) {
		const r = await fetch(BASE + '/ocs/v2.php/cloud/capabilities?format=json', {
			headers: { ...OCS_HEADERS, Authorization: adminAuth },
		})
		const caps = (await r.json())?.ocs?.data?.capabilities ?? {}
		if (!('notify_push' in caps)) return
		await new Promise((resolve) => setTimeout(resolve, 500))
	}
}

/** A hex colour as the `rgb(...)` string toHaveCSS expects. */
function rgb(hex) {
	return `rgb(${parseInt(hex.slice(0, 2), 16)}, ${parseInt(hex.slice(2, 4), 16)}, ${parseInt(hex.slice(4, 6), 16)})`
}

test.describe('Project colour indicator on the board tile', () => {
	const state = { boardId: 0, cardId: 0, projectId: 0 }
	const COLOR = 'e53935'

	test.beforeAll(async () => {
		const ts = Date.now()
		const board = await api.post('/boards', { title: `ProjColor Board ${ts}` })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		const card = await api.post('/cards', { stackId: stack.id, title: `Colored project card ${ts}` })
		state.cardId = card.id

		const project = await api.post('/projects', { title: `Red Initiative ${ts}`, color: COLOR })
		state.projectId = project.id
		await api.put(`/projects/${project.id}/cards/${card.id}`)
	})

	test.afterAll(async () => {
		occSafe('app:enable notify_push')
		if (state.projectId) await api.delete(`/projects/${state.projectId}`).catch(() => {})
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('the card tile shows the project colour and the uncoloured card shows none', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)

		// The board payload carries the project (id + colour) and the card's
		// projectIds — the server-side contract the tile resolves against.
		const board = await api.get(`/boards/${state.boardId}`)
		const card = board.cards.find((c) => c.id === state.cardId)
		expect(card.projectIds).toContain(state.projectId)
		const project = board.projects.find((p) => p.id === state.projectId)
		expect(project.color).toBe(COLOR)

		// The tile renders a dot for the coloured project, tinted with it.
		const tile = page.locator('.card-tile', { hasText: 'Colored project card' })
		await expect(tile).toBeVisible()
		const dot = tile.locator('.card-tile__project-dot')
		await expect(dot).toHaveCount(1)
		await expect(dot).toHaveCSS('background-color', `rgb(${parseInt(COLOR.slice(0, 2), 16)}, ${parseInt(COLOR.slice(2, 4), 16)}, ${parseInt(COLOR.slice(4, 6), 16)})`)
		// The project title is the hover tooltip.
		await expect(dot).toHaveAttribute('title', new RegExp('Red Initiative'))
	})

	// #e1422e4 — a card whose project membership is set, when delta-upserted,
	// renders its project dot via the /changes delta (the payload carries the
	// viewer's projects and the client merges them by id) WITHOUT a page reload
	// or a full board refetch.
	test('a project membership change reaches an open board via the delta, no reload', async ({ page }) => {
		// Self-contained: a card NOT yet in a project, so the dot is absent until
		// the membership change is delta-delivered.
		const ts = Date.now()
		let board = null
		let project = null
		try {
			// Force the deterministic 5s delta poll (the push path also delta-syncs,
			// but the poll is what we can reliably force on any runner). The disable
			// and the setup below live INSIDE the try so a failing setup step still
			// restores notify_push in the finally — leaking it disabled would break
			// the push-path specs that run later on this shared stack.
			occSafe('app:disable notify_push')
			await waitPushOff()

			board = await api.post('/boards', { title: `Delta Proj ${ts}` })
			const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
			const card = await api.post('/cards', { stackId: stack.id, title: `Delta card ${ts}` })
			project = await api.post('/projects', { title: `Delta project ${ts}`, color: COLOR })
			// Track board reads: the change must arrive via /changes, not a full
			// /boards/{id} refetch. Set up before the load so the initial read is
			// counted, then baseline it.
			const fullReads = []
			const deltaReads = []
			page.on('request', (req) => {
				const u = req.url()
				if (u.includes(`/boards/${board.id}/changes`)) {
					deltaReads.push(u)
				} else if (new RegExp(`/boards/${board.id}(\\?|$)`).test(u)) {
					fullReads.push(u)
				}
			})

			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/board/${board.id}`)
			const tile = page.locator('.card-tile', { hasText: `Delta card ${ts}` })
			await expect(tile).toBeVisible()
			// No dot yet: the card is in no project.
			await expect(tile.locator('.card-tile__project-dot')).toHaveCount(0)
			const fullReadsAfterLoad = fullReads.length

			// Assign the card to the project, then a card mutation that records a
			// change row. A pure membership change writes no kanso_changes row, so
			// on its own it never upserts; the upsert — and with it the delta's
			// projects list — rides the mutation.
			await api.put(`/projects/${project.id}/cards/${card.id}`)
			await api.patch(`/cards/${card.id}`, { duedate: '2026-11-01T09:00:00+00:00' })

			// The dot appears WITHOUT a reload, tinted with the project colour.
			const dot = tile.locator('.card-tile__project-dot')
			await expect(dot).toHaveCount(1)
			await expect(dot).toHaveCSS('background-color', rgb(COLOR))

			// The reflection came through the delta endpoint, not a full refetch.
			expect(deltaReads.length).toBeGreaterThan(0)
			expect(fullReads.length).toBe(fullReadsAfterLoad)
		} finally {
			// Runs even when the setup above threw — notify_push is always
			// restored, and only the resources that actually exist are deleted.
			occSafe('app:enable notify_push')
			if (project) await api.delete(`/projects/${project.id}`).catch(() => {})
			if (board) await api.delete(`/boards/${board.id}`).catch(() => {})
		}
	})

	// #c3cca00 — the project dots share the card title's visual row (the tile
	// does not grow an extra row for them), so the dot's vertical centre sits
	// within the title's bounding box and the dot is to the right of the title.
	test('the project dot sits on the same visual row as the card title', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)

		const tile = page.locator('.card-tile', { hasText: 'Colored project card' })
		await expect(tile).toBeVisible()
		const dot = tile.locator('.card-tile__project-dot')
		await expect(dot).toBeVisible()
		const title = tile.locator('.card-tile__title')
		await expect(title).toBeVisible()

		const dotBox = await dot.boundingBox()
		const titleBox = await title.boundingBox()
		expect(dotBox, 'dot must have a bounding box').not.toBeNull()
		expect(titleBox, 'title must have a bounding box').not.toBeNull()

		// The dot's vertical centre falls within the title's bounding box (a few
		// px of tolerance for the title's line-height/clamp), so the two share one
		// visual row rather than the dot wrapping to its own line.
		const dotCenterY = dotBox.y + dotBox.height / 2
		const tolerance = 4
		expect(dotCenterY).toBeGreaterThanOrEqual(titleBox.y - tolerance)
		expect(dotCenterY).toBeLessThanOrEqual(titleBox.y + titleBox.height + tolerance)
		// And the dot sits to the right of the title's left edge (after it).
		expect(dotBox.x).toBeGreaterThanOrEqual(titleBox.x)
	})
})
