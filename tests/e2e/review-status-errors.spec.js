// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { randomUUID } from 'node:crypto'
import { test, expect, api, me, BASE, OCS, adminAuth, ncLogin, provisionUser, deleteUser } from './helpers.js'

const genericReviewError = 'All requested reviews must be approved before this card can be marked done.'
const names = ['Ann & Bo', 'Same name', 'Same name', 'Dana', 'Eli', 'Fran', 'Gio', 'Hana']
const boards = []
const reviewers = []

async function fixture(people) {
	const board = await api.post('/boards', { title: `Review status errors ${randomUUID()}` })
	boards.push(board.id)
	const todo = await api.post('/stacks', { boardId: board.id, title: 'To do' })
	const done = await api.post('/stacks', { boardId: board.id, title: 'Finished' })
	await api.patch(`/stacks/${done.id}`, { role: 5 })
	for (const person of people) {
		await api.post(`/boards/${board.id}/acl`, {
			participant: person.user, participantType: 'user', permission: 3,
		})
	}
	const card = await api.post('/cards', { stackId: todo.id, title: 'Review status error target' })
	return { board, todo, done, card }
}

async function openCard(page, state, user) {
	await ncLogin(page, user)
	await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.board.id}/card/${state.card.id}`)
	await expect(page.locator('.card-modal__title')).toHaveText(state.card.title)
}

async function holdWrite(page, cardId, move = false) {
	let release
	let held
	const released = new Promise((resolve) => { release = resolve })
	const arrived = new Promise((resolve) => { held = resolve })
	const path = `/cards/${cardId}${move ? '/move' : ''}`
	const method = move ? 'POST' : 'PATCH'
	await page.route(new RegExp(`/apps/kanso/api${path}$`), async (route) => {
		if (route.request().method() !== method) return route.fallback()
		held()
		await released
		await route.continue()
	})
	return {
		release, arrived,
		response: page.waitForResponse((response) => response.request().method() === method
			&& response.url().endsWith(path), { timeout: 20_000 }),
	}
}

async function clickDone(page, move) {
	if (move) {
		await page.locator('.card-modal__status-chip--btn').click()
		await page.locator('.card-modal__popover-opt--column').filter({ hasText: /^Done \(Finished\)$/ }).click()
	} else {
		await page.locator('.card-modal__done-btn').click()
	}
}

test.describe('Review status error reasons and names', () => {
	test.use({ storageState: { cookies: [], origins: [] }, locale: 'en-US' })
	let pageErrors

	test.beforeAll(async () => {
		const nonce = randomUUID().slice(0, 8)
		for (const [index, displayName] of names.entries()) {
			reviewers.push(await provisionUser(`review-errors-${nonce}-${index}`, 'Kanso-review-test!2026', { displayName }))
		}
	})

	test.beforeEach(async ({ page }) => {
		pageErrors = []
		page.on('pageerror', (error) => pageErrors.push(error.message))
	})

	test.afterEach(async () => {
		expect(pageErrors).toEqual([])
	})

	test.afterAll(async () => {
		for (const id of boards) await api.delete(`/boards/${id}`)
		for (const reviewer of reviewers) await deleteUser(reviewer.user)
	})

	for (const move of [false, true]) {
		test(`a real permission downgrade stays a permission error for ${move ? 'a done-column move' : 'Mark done'}`, async ({ page, peer }) => {
			const state = await fixture([peer])
			await api.put(`/cards/${state.card.id}/reviews/${me}`)
			await openCard(page, state, peer)
			await expect(page.locator('.card-modal__review-pill')).toHaveCount(1)
			const held = await holdWrite(page, state.card.id, move)
			try {
				await clickDone(page, move)
				await held.arrived
				const acl = (await api.get(`/boards/${state.board.id}`)).acl.find((entry) => entry.participant === peer.user)
				await api.patch(`/boards/${state.board.id}/acl/${acl.id}`, { permission: 1 })
			} finally {
				held.release()
			}
			const response = await held.response
			expect(response.status()).toBe(403)
			expect((await response.json()).error).toBe('Operation not allowed on this board')
			await expect(page.locator('[data-status-error]')).toHaveText('Operation not allowed on this board')
			const card = await api.get(`/cards/${state.card.id}`)
			expect(Number(card.doneAt)).toBe(0)
			expect(card.stackId).toBe(state.todo.id)
		})
	}

	for (const count of [4, 8]) {
		test(`names three people with ${count - 3} remaining without double-counting typed reviews`, async ({ page, user }) => {
			const people = reviewers.slice(0, count)
			const state = await fixture(people)
			for (const person of people) await api.put(`/cards/${state.card.id}/reviews/${person.user}`)
			const type = await api.post('/review-types', { boardId: state.board.id, title: 'Second review' })
			await api.put(`/cards/${state.card.id}/reviews/${people[0].user}`, { reviewTypeId: type.id })
			await openCard(page, state, user)
			await page.locator('.card-modal__done-btn').click()
			const remaining = count - 3
			const list = await page.evaluate(({ names, remaining }) => new Intl.ListFormat(
				globalThis._nc_l10n_locale.replaceAll('_', '-'), { type: 'conjunction' },
			).format([...names.slice(0, 3), `${remaining} other reviewer${remaining === 1 ? '' : 's'}`]), { names, remaining })
			await expect(page.locator('[data-status-error]')).toHaveText(`${genericReviewError} Waiting on ${list}.`)
			await expect(page.locator('[data-status-error]')).not.toContainText('Dana')
			expect(Number((await api.get(`/cards/${state.card.id}`)).doneAt)).toBe(0)
		})
	}

	test('formats the bounded list in the Chinese account locale with an English browser', async ({ page }) => {
		const viewer = reviewers[0]
		for (const [key, value] of [['language', 'zh_CN'], ['locale', 'zh_Hans_CN']]) {
			const response = await fetch(`${OCS}/users/${viewer.user}?format=json`, {
				method: 'PUT',
				headers: { 'OCS-APIREQUEST': 'true', Authorization: adminAuth, 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams({ key, value }),
			})
			const result = await response.json()
			expect(response.ok, `${key}: ${response.status}: ${JSON.stringify(result.ocs.meta)}`).toBe(true)
			expect(result.ocs.meta.status).toBe('ok')
		}
		const state = await fixture(reviewers)
		for (const person of reviewers) await api.put(`/cards/${state.card.id}/reviews/${person.user}`)
		await openCard(page, state, viewer)
		expect(await page.evaluate(() => navigator.language)).toBe('en-US')
		expect(await page.evaluate(() => globalThis._nc_l10n_locale)).toBe('zh_Hans_CN')
		expect(await page.evaluate(() => globalThis._nc_l10n_language)).toBe('zh-CN')
		await page.locator('.card-modal__done-btn').click()
		const list = new Intl.ListFormat('zh-CN', { type: 'conjunction' }).format([...names.slice(0, 3), '5 位其他评审者'])
		await expect(page.locator('[data-status-error]')).toHaveText(`所有已请求的评审通过后才能将此卡片标记为已完成。等待 ${list}。`)
		await expect(page.locator('[data-status-error]')).toContainText('、')
		await expect(page.locator('[data-status-error]')).not.toContainText('Dana')
	})

	for (const stale of [false, true]) {
		test(`uses the generic review reason when cached reviewers are ${stale ? 'outdated' : 'missing'}`, async ({ page, user }) => {
			const state = await fixture(reviewers.slice(0, 2))
			if (stale) await api.put(`/cards/${state.card.id}/reviews/${reviewers[0].user}`)
			const before = await api.get(`/cards/${state.card.id}`)
			await openCard(page, state, user)
			let releaseReads
			const readsReleased = new Promise((resolve) => { releaseReads = resolve })
			await page.route(new RegExp(`/apps/kanso/api/cards/${state.card.id}$`), async (route) => {
				if (route.request().method() !== 'GET') return route.fallback()
				await readsReleased
				await route.continue()
			})
			const held = await holdWrite(page, state.card.id)
			try {
				await clickDone(page, false)
				await held.arrived
				if (stale) await api.delete(`/cards/${state.card.id}/reviews/${before.reviews[0].id}`)
				await api.put(`/cards/${state.card.id}/reviews/${reviewers[1].user}`)
				held.release()
				const response = await held.response
				expect(response.status()).toBe(403)
				expect((await response.json()).error).toContain(`Blocked by unapproved review(s): ${reviewers[1].user} `)
				await expect(page.locator('[data-status-error]')).toHaveText(genericReviewError)
			} finally {
				held.release()
				releaseReads()
			}
			expect(Number((await api.get(`/cards/${state.card.id}`)).doneAt)).toBe(0)
		})
	}
})
