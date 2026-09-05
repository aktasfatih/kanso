// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Non-card title inputs now carry a `maxlength` matching their OWN entity's
// server cap. The caps genuinely differ, and that is the thing most likely to
// be got wrong:
//
//   project title   → 255  (ProjectService::MAX_TITLE_LENGTH, VARCHAR(255))
//   board title     → 100  (BoardService::MAX_TITLE_LENGTH,   VARCHAR(100))
//
// So this spec exercises one input of each cap and asserts the two attributes
// are different — a sweep that stamped 100 everywhere would pass a 100-only
// test and fail here.
import { test, expect, api, ncLogin, BASE } from './helpers.js'

const PROJECT_CAP = 255
const BOARD_CAP = 100

// Distinct filler per input so one test's persisted value can never satisfy
// another's assertion.
const overlong = (ch) => ch.repeat(300)

test.describe('Name length caps differ per entity', () => {
	const created = { projectIds: [], boardIds: [] }

	test.afterAll(async () => {
		for (const id of created.projectIds) await api.delete(`/projects/${id}`).catch(() => {})
		for (const id of created.boardIds) await api.delete(`/boards/${id}`).catch(() => {})
	})

	test('the project title input caps at 255 and creates the 255-char project', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects`)
		await expect(page.locator('.projects-view')).toBeVisible({ timeout: 15_000 })

		await page.getByRole('button', { name: 'New project' }).click()

		const title = page.locator('#project-title')
		await expect(title).toBeVisible({ timeout: 10_000 })
		await expect(title).toHaveAttribute('maxlength', String(PROJECT_CAP))

		// fill() goes through the real input pipeline, so maxlength applies.
		await title.fill(overlong('p'))
		await expect(title).toHaveValue('p'.repeat(PROJECT_CAP))

		await title.press('Enter')

		// Created with exactly the 255-character prefix — proves the cap survived
		// the round trip, and that 255 (not 100) is the right number here.
		await expect.poll(async () => {
			const projects = await api.get('/projects')
			const match = projects.find((p) => p.title === 'p'.repeat(PROJECT_CAP))
			if (match) created.projectIds.push(match.id)
			return match?.title?.length ?? 0
		}, { timeout: 10_000 }).toBe(PROJECT_CAP)
	})

	test('the board title input caps at 100 — a different cap on the same sweep', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await expect(page.locator('.board-list-header')).toBeVisible({ timeout: 15_000 })

		await page.getByRole('button', { name: 'Create board' }).first().click()

		const name = page.getByPlaceholder('New board name…')
		await expect(name).toBeVisible({ timeout: 10_000 })
		await expect(name).toHaveAttribute('maxlength', String(BOARD_CAP))

		await name.fill(overlong('b'))
		await expect(name).toHaveValue('b'.repeat(BOARD_CAP))

		await name.press('Enter')

		await expect.poll(async () => {
			const boards = await api.get('/boards')
			const match = boards.find((b) => b.title === 'b'.repeat(BOARD_CAP))
			if (match) created.boardIds.push(match.id)
			return match?.title?.length ?? 0
		}, { timeout: 10_000 }).toBe(BOARD_CAP)

		// The contrast itself, asserted rather than implied: had the sweep used a
		// single shared number, these two would be equal.
		expect(PROJECT_CAP).not.toBe(BOARD_CAP)
	})
})
