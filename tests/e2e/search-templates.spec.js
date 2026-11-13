// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Template search exclusion', () => {
	const token = Date.now()
	const cardTerm = `templatecards${token}`
	const commentTerm = `templatecomments${token}`
	const state = { boards: [], stacks: [], cards: [], projectId: 0 }

	async function addCard(boardIndex, title, { description = '', body = '', template = false, archived = false } = {}) {
		const card = await api.post('/cards', { stackId: state.stacks[boardIndex].id, title })
		const fixture = {
			...card, template, archived,
			prefix: state.boards[boardIndex].prefix,
			matchesCard: title.includes(cardTerm) || description.includes(cardTerm),
			matchesComment: body.includes(commentTerm),
		}
		state.cards.push(fixture)
		if (description || archived) await api.patch(`/cards/${card.id}`, { description, archived })
		if (body) await api.post(`/cards/${card.id}/comments`, { body })
		if (template) await api.put(`/cards/${card.id}/template`, { isTemplate: true })
		return fixture
	}

	test.beforeAll(async () => {
		for (const prefix of ['TSRCH', 'TCMNT']) {
			const board = await api.post('/boards', { title: `Template search ${prefix} ${token}` })
			state.boards.push({ ...board, prefix })
			await api.patch(`/boards/${board.id}`, { prefix })
			state.stacks.push(await api.post('/stacks', { boardId: board.id, title: 'Search fixtures' }))
		}
		state.liveTitle = await addCard(0, `Ordinary ${cardTerm}`)
		state.templateTitle = await addCard(0, `Blueprint ${cardTerm}`, { template: true })
		await addCard(0, `Ordinary description ${token}`, { description: cardTerm })
		state.templateDescription = await addCard(0, `Blueprint description ${token}`, { description: cardTerm, template: true })
		state.liveComment = await addCard(1, `Ordinary discussion ${token}`, { body: commentTerm })
		state.templateComment = await addCard(1, `Blueprint discussion ${token}`, { body: commentTerm, template: true })
		await addCard(1, `Archived ordinary ${cardTerm}`, { body: commentTerm, archived: true })
		await addCard(1, `Archived blueprint ${cardTerm}`, { body: commentTerm, template: true, archived: true })
	})

	test.afterAll(async () => {
		if (state.projectId) await api.delete(`/projects/${state.projectId}`).catch(() => {})
		for (const board of state.boards) await api.delete(`/boards/${board.id}`).catch(() => {})
	})

	async function search(q, boardId, includeArchived) {
		const params = new URLSearchParams({ q, includeArchived: String(includeArchived) })
		if (boardId != null) params.set('boardId', boardId)
		return api.get(`/search?${params}`)
	}

	for (const [source, term, property] of [['title and description', cardTerm, 'matchesCard'], ['comment', commentTerm, 'matchesComment']]) {
		test(`templates are excluded from ${source} search`, async () => {
			for (const boardId of [null, ...state.boards.map((b) => b.id)]) {
				for (const includeArchived of [false, true]) {
					const result = await search(term, boardId, includeArchived)
					const expected = state.cards.filter((c) => c[property] && !c.template
						&& (!c.archived || includeArchived) && (boardId == null || c.boardId === boardId))
					expect(result.results.map((r) => r.cardId).sort()).toEqual(expected.map((c) => c.id).sort())
					expect(result.total).toBe(expected.length)
				}
			}
		})
	}

	for (const mode of ['numeric ID', 'board reference']) {
		test(`templates are excluded from exact ${mode} search`, async () => {
			for (const card of state.cards) {
				const term = mode === 'numeric ID' ? String(card.id).padStart(2, '0') : `${card.prefix}-${card.boardSeq}`
				for (const boardId of [null, card.boardId]) {
					for (const includeArchived of [false, true]) {
						const result = await search(term, boardId, includeArchived)
						const matches = result.results.filter((r) => r.type === 'card' && r.cardId === card.id)
						expect(matches, `${term}, board ${boardId}, archived ${includeArchived}`).toHaveLength(
							!card.template && (!card.archived || includeArchived) ? 1 : 0,
						)
					}
				}
			}
		})
	}

	test('explicit template listing and creation still work', async () => {
		for (const board of state.boards) {
			const templates = await api.get(`/boards/${board.id}/cards/templates`)
			expect(templates.map((c) => c.id).sort()).toEqual(
				state.cards.filter((c) => c.template && c.boardId === board.id).map((c) => c.id).sort(),
			)
		}
		const copy = await api.post(`/cards/${state.templateTitle.id}/create-from-template`, { targetStackId: state.stacks[0].id })
		try {
			expect(copy.isTemplate).toBe(false)
			const title = `Created from blueprint ${token}`
			await api.patch(`/cards/${copy.id}`, { title })
			const result = await search(title, copy.boardId, false)
			expect(result.results.map((r) => r.cardId)).toEqual([copy.id])
		} finally {
			await api.delete(`/cards/${copy.id}`)
		}
	})

	async function fillSearch(page, input, term, boardId) {
		const pending = page.waitForResponse((response) => {
			const url = new URL(response.url())
			return url.pathname.endsWith('/api/search') && url.searchParams.get('q') === term
				&& url.searchParams.get('boardId') === (boardId == null ? null : String(boardId))
		}, { timeout: 15_000 })
		await input.fill(term)
		const response = await pending
		expect(response.ok()).toBe(true)
		await response.finished()
	}

	test('board search omits template cards and their comments', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boards[0].id}`)
		await expect(page.locator('.card-tile', { hasText: state.liveTitle.title })).toBeVisible()
		await fillSearch(page, page.locator('.search-box__input'), cardTerm, state.boards[0].id)
		const rows = page.locator('.search-box__result')
		await expect(rows.filter({ hasText: state.liveTitle.title })).toBeVisible()
		await expect(rows.filter({ hasText: state.templateTitle.title })).toHaveCount(0)
		await expect(rows.filter({ hasText: state.templateDescription.title })).toHaveCount(0)

		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boards[1].id}`)
		await expect(page.locator('.card-tile', { hasText: state.liveComment.title })).toBeVisible()
		await fillSearch(page, page.locator('.search-box__input'), commentTerm, state.boards[1].id)
		await expect(rows.filter({ hasText: state.liveComment.title })).toBeVisible()
		await expect(rows.filter({ hasText: state.templateComment.title })).toHaveCount(0)
	})

	test('cross-board card picker excludes templates and selects ordinary work', async ({ page }) => {
		const project = await api.post('/projects', { title: `Template search picker ${token}` })
		state.projectId = project.id
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/projects/${project.id}`)
		await page.locator('.project-view__add-btn').click()
		await fillSearch(page, page.locator('.card-search-picker__input'), cardTerm, null)
		const rows = page.locator('.card-search-picker__item')
		const ordinary = rows.filter({ hasText: state.liveTitle.title })
		await expect(ordinary).toBeVisible()
		await expect(rows.filter({ hasText: state.templateTitle.title })).toHaveCount(0)
		await expect(rows.filter({ hasText: state.templateDescription.title })).toHaveCount(0)
		await ordinary.click()
		await expect(page.locator('.project-view__row', { hasText: state.liveTitle.title })).toBeVisible()
		expect((await api.get(`/projects/${project.id}/cards`)).map((c) => c.id)).toEqual([state.liveTitle.id])
	})
})
