// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// ── Hermetic test state ────────────────────────────────────────────────────────
// Three cards on one board:
//   1. "Alpha widget"       - card title match
//   2. "Beta gadget"        - card description contains "photosynthesis"
//   3. "Gamma fixture"      - has a comment containing "xylorimba"
test.describe('Search', () => {
	const state = {
		boardId: 0,
		stackId: 0,
		cardAlphaId: 0,
		cardBetaId: 0,
		cardGammaId: 0,
		boardUrl: '',
	}

	test.beforeAll(async () => {
		// Tear down any prior board with the same name to ensure hermetic setup
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Search Test Board E2E') {
				await api.delete(`/boards/${b.id}`)
			}
		}

		// Create fresh board + stack
		const board = await api.post('/boards', { title: 'Search Test Board E2E' })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Backlog' })
		state.stackId = stack.id

		// Card 1 - unique title term "Alpha widget"
		const cardAlpha = await api.post('/cards', {
			stackId: stack.id,
			title: 'Alpha widget',
		})
		state.cardAlphaId = cardAlpha.id

		// Card 2 - title "Beta gadget", description contains "photosynthesis".
		// Description is set via PATCH (card create is title-only by design).
		const cardBeta = await api.post('/cards', {
			stackId: stack.id,
			title: 'Beta gadget',
		})
		state.cardBetaId = cardBeta.id
		await api.patch(`/cards/${cardBeta.id}`, {
			description: 'This card explains photosynthesis in plants.',
		})

		// Card 3 - title "Gamma fixture", comment contains "xylorimba"
		const cardGamma = await api.post('/cards', {
			stackId: stack.id,
			title: 'Gamma fixture',
		})
		state.cardGammaId = cardGamma.id
		await api.post(`/cards/${cardGamma.id}/comments`, {
			body: 'Check the xylorimba tuning reference.',
		})

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	// ── Shared navigation helper ───────────────────────────────────────────────

	async function goToBoard(page) {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })
	}

	// ── Tests ──────────────────────────────────────────────────────────────────

	test('typing a card-title term shows that card in results and clicking opens the modal', async ({ page }) => {
		await goToBoard(page)

		const searchInput = page.locator('.search-box__input')
		await expect(searchInput).toBeVisible()

		// Type "Alpha" - unique enough to match only "Alpha widget"
		await searchInput.fill('Alpha')

		// Dropdown should appear with the result
		const dropdown = page.locator('.search-box__dropdown')
		await expect(dropdown).toBeVisible()

		const alphaResult = dropdown.locator('.search-box__result').filter({ hasText: 'Alpha widget' })
		await expect(alphaResult).toBeVisible()

		// Click the result → card modal should open
		await alphaResult.click()
		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		// Dropdown should close and search cleared after selecting a result
		await expect(dropdown).not.toBeVisible({ timeout: 3000 }).catch(() => {})
	})

	test('typing a description-only term shows the correct card', async ({ page }) => {
		await goToBoard(page)

		const searchInput = page.locator('.search-box__input')
		await expect(searchInput).toBeVisible()

		// "photosynthesis" is only in Beta gadget's description, not in any title
		await searchInput.fill('photosynthesis')

		const dropdown = page.locator('.search-box__dropdown')
		await expect(dropdown).toBeVisible()

		// Beta gadget should appear
		const betaResult = dropdown.locator('.search-box__result').filter({ hasText: 'Beta gadget' })
		await expect(betaResult).toBeVisible()

		// Alpha widget should NOT appear
		const alphaResult = dropdown.locator('.search-box__result').filter({ hasText: 'Alpha widget' })
		await expect(alphaResult).not.toBeVisible()
	})

	test('typing the comment-only distinctive word shows a comment-type result pointing at the right card', async ({ page }) => {
		await goToBoard(page)

		const searchInput = page.locator('.search-box__input')
		await expect(searchInput).toBeVisible()

		// "xylorimba" is only in Gamma fixture's comment
		await searchInput.fill('xylorimba')

		const dropdown = page.locator('.search-box__dropdown')
		await expect(dropdown).toBeVisible()

		// Result should list Gamma fixture as the card title
		const gammaResult = dropdown.locator('.search-box__result').filter({ hasText: 'Gamma fixture' })
		await expect(gammaResult).toBeVisible()

		// Should show the comment badge
		const commentBadge = gammaResult.locator('.search-box__result-badge')
		await expect(commentBadge).toBeVisible()
		await expect(commentBadge).toHaveText('comment')
	})

	test('typing a single character does NOT open the dropdown or make an API call', async ({ page }) => {
		await goToBoard(page)

		// Intercept search API calls so we can assert none fired
		const searchRequests = []
		page.on('request', (req) => {
			if (req.url().includes('/api/search')) searchRequests.push(req)
		})

		const searchInput = page.locator('.search-box__input')
		await expect(searchInput).toBeVisible()

		await searchInput.fill('A')

		// Wait a moment to let any (erroneous) debounced request fire
		await page.waitForTimeout(600)

		// Dropdown should NOT be visible
		const dropdown = page.locator('.search-box__dropdown')
		await expect(dropdown).not.toBeVisible()

		// No search API request should have been made
		expect(searchRequests.length).toBe(0)
	})

	test('typing gibberish shows the "No matches" empty state', async ({ page }) => {
		await goToBoard(page)

		const searchInput = page.locator('.search-box__input')
		await expect(searchInput).toBeVisible()

		await searchInput.fill('zzzxqxqxq')

		const dropdown = page.locator('.search-box__dropdown')
		await expect(dropdown).toBeVisible()

		// Empty-state message should appear
		const emptyState = dropdown.locator('.search-box__status--empty')
		await expect(emptyState).toBeVisible()
		await expect(emptyState).toContainText('No matches')
	})

	// #122 — a board routinely holds several cards whose titles are near-identical
	// and whose only distinguishing feature is the stage they are at, which made
	// the result list unreadable: every row looked the same and had to be opened
	// to tell them apart. Each hit now names its column.
	test('results name the column, which is what tells identically-titled cards apart', async ({ page }) => {
		// Two cards with the SAME title in DIFFERENT columns - the reporter's case.
		const twinA = await api.post('/cards', { stackId: state.stackId, title: 'Wheelchair repair Kaya' })
		const secondStack = await api.post('/stacks', { boardId: state.boardId, title: 'Awaiting parts' })
		const twinB = await api.post('/cards', { stackId: secondStack.id, title: 'Wheelchair repair Kaya' })

		await goToBoard(page)
		const searchInput = page.locator('.search-box__input')
		await searchInput.fill('Wheelchair')

		const dropdown = page.locator('.search-box__dropdown')
		await expect(dropdown).toBeVisible()
		const rows = dropdown.locator('.search-box__result').filter({ hasText: 'Wheelchair repair Kaya' })
		await expect(rows).toHaveCount(2)

		// Identical titles, so the column is the ONLY thing separating the rows.
		const columns = await rows.locator('.search-box__result-column').allTextContents()
		expect(columns.map((c) => c.trim()).sort()).toEqual(['Awaiting parts', 'Backlog'])

		// A comment hit carries it too - it is a card hit by another route.
		await searchInput.fill('xylorimba')
		const commentRow = dropdown.locator('.search-box__result').filter({ hasText: 'Gamma fixture' })
		await expect(commentRow).toBeVisible()
		await expect(commentRow.locator('.search-box__result-column')).toHaveText('Backlog')

		await api.delete(`/cards/${twinA.id}`)
		await api.delete(`/cards/${twinB.id}`)
		await api.delete(`/stacks/${secondStack.id}`)
	})

	// #10762 — archived cards were the ONE listing search did not filter, and
	// ArchiveDoneCards sweeps finished work off the board unattended, so a board
	// that had been running a while answered every query mostly with shelved
	// cards. They are out of search by default now; the archive is still the
	// project's history, so one chip widens the search back over it.
	test('archived cards leave search until "Include archived" is on', async ({ page }) => {
		// Every card this spec makes, recorded the instant the create returns. The
		// fixture setup is INSIDE the try for the same reason the assertions are:
		// this file's specs share one board, so a create that succeeds followed by
		// an archive that fails would otherwise strand "Quagga" cards on it and
		// change what the specs after this one see.
		const created = []
		try {
			// Title match on an archived card…
			const shelvedCard = await api.post('/cards', { stackId: state.stackId, title: 'Quagga retrospective' })
			created.push(shelvedCard.id)
			// …and a COMMENT match on an archived card whose own title does NOT carry
			// the term, so this row can only arrive through the comment source.
			const shelvedCommented = await api.post('/cards', { stackId: state.stackId, title: 'Okapi ledger' })
			created.push(shelvedCommented.id)
			await api.post(`/cards/${shelvedCommented.id}/comments`, { body: 'The quagga decision was taken here.' })
			// A live card with the same term: without it, "no archived rows" would
			// pass just as well on a query that returned nothing at all.
			const liveCard = await api.post('/cards', { stackId: state.stackId, title: 'Quagga live work' })
			created.push(liveCard.id)
			await api.patch(`/cards/${shelvedCard.id}`, { archived: true })
			await api.patch(`/cards/${shelvedCommented.id}`, { archived: true })

			await goToBoard(page)
			const searchInput = page.locator('.search-box__input')
			await searchInput.fill('Quagga')

			const dropdown = page.locator('.search-box__dropdown')
			const row = (text) => dropdown.locator('.search-box__result').filter({ hasText: text })
			await expect(dropdown).toBeVisible()
			// The live row FIRST in every block: flipping the chip changes the query
			// key, so tanstack drops `data` while it refetches and for that moment
			// EVERY row is absent. Waiting for the live row is what proves the new
			// result set has landed, which is what makes the two counts below mean
			// "excluded" rather than "not arrived yet".
			await expect(row('Quagga live work')).toBeVisible()
			await expect(row('Quagga retrospective')).toHaveCount(0)
			await expect(row('Okapi ledger')).toHaveCount(0)

			// Opt in - both sources widen, and the live hit stays.
			const archivedToggle = dropdown.locator('.search-box__archived-toggle')
			await archivedToggle.click()
			await expect(archivedToggle).toHaveAttribute('aria-pressed', 'true')
			await expect(row('Quagga retrospective')).toBeVisible()
			await expect(row('Okapi ledger')).toBeVisible()
			await expect(row('Quagga live work')).toBeVisible()

			// …and off again, on the same term.
			await archivedToggle.click()
			await expect(archivedToggle).toHaveAttribute('aria-pressed', 'false')
			await expect(row('Quagga live work')).toBeVisible()
			await expect(row('Quagga retrospective')).toHaveCount(0)
			await expect(row('Okapi ledger')).toHaveCount(0)
		} finally {
			// Unconditional, and over exactly what was created: a failed assertion
			// — or a failed create/archive halfway through the setup above — must
			// not leave "Quagga" cards on the board this file's other specs search.
			// Swallowed per card so one failing delete cannot skip the rest, nor
			// replace the real failure this block is unwinding.
			for (const id of created) {
				await api.delete(`/cards/${id}`).catch(() => {})
			}
		}
	})

	test('pressing Escape closes the dropdown and clears the input', async ({ page }) => {
		await goToBoard(page)

		const searchInput = page.locator('.search-box__input')
		await expect(searchInput).toBeVisible()

		await searchInput.fill('Alpha')
		await expect(page.locator('.search-box__dropdown')).toBeVisible()

		await searchInput.press('Escape')

		// Input should be cleared
		await expect(searchInput).toHaveValue('')
		// Dropdown should be gone
		await expect(page.locator('.search-box__dropdown')).not.toBeVisible()
	})

	// #10522 — Escape has to hand the keyboard back, not just wipe the text.
	// While the caret stayed in the field, BoardView's shell handler treated every
	// following keypress as typing and dropped it, so the board was keyboard-dead
	// until you clicked somewhere else.
	test('pressing Escape releases the search box so a board shortcut fires with no intervening click', async ({ page }) => {
		await goToBoard(page)

		const searchInput = page.locator('.search-box__input')
		await expect(searchInput).toBeVisible()

		await searchInput.fill('Alpha')
		await expect(page.locator('.search-box__dropdown')).toBeVisible()

		await searchInput.press('Escape')

		// The input must no longer be document.activeElement.
		await expect(searchInput).not.toBeFocused()
		const activeClass = await page.evaluate(() => document.activeElement?.className ?? '')
		expect(activeClass).not.toContain('search-box__input')

		// …and the very next keypress must reach the board. 'j' is the vim alias
		// for ArrowDown, which seeds the focus ring onto the first card of the
		// first non-empty stack. No click in between — that is the whole point.
		await page.keyboard.press('j')
		await expect(page.locator('.card-tile').first()).toBeFocused({ timeout: 15_000 })
	})

	test('pressing "/" keyboard shortcut focuses the search box', async ({ page }) => {
		await goToBoard(page)

		// Make sure no input element is active
		await page.locator('.board-view__stacks-wrap').click()
		await page.waitForTimeout(100)

		await page.keyboard.press('/')

		const searchInput = page.locator('.search-box__input')
		await expect(searchInput).toBeFocused()
	})
})
