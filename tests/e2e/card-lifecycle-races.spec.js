// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, toast } from './helpers.js'

function deferred() {
	let resolve
	const promise = new Promise((done) => { resolve = done })
	return { promise, resolve }
}

async function fixture() {
	const a = await api.post('/boards', { title: `Lifecycle origin ${Date.now()}` })
	const b = await api.post('/boards', { title: `Lifecycle destination ${Date.now()}` })
	const stackA = await api.post('/stacks', { boardId: a.id, title: 'To do' })
	const stackB = await api.post('/stacks', { boardId: b.id, title: 'To do' })
	const cardA = await api.post('/cards', { stackId: stackA.id, title: 'Lifecycle origin card' })
	const cardB = await api.post('/cards', { stackId: stackB.id, title: 'Lifecycle destination card' })
	return { a, b, cardA, cardB, cleanup: () => Promise.all([api.delete(`/boards/${a.id}`), api.delete(`/boards/${b.id}`)]) }
}

async function navigate(page, boardId, cardId) {
	await page.evaluate(({ boardId, cardId }) => {
		location.hash = `#/board/${boardId}${cardId ? `/card/${cardId}` : ''}`
	}, { boardId, cardId })
}

async function openMenu(page) {
	await expect(page.locator('.card-modal')).toBeVisible()
	await page.locator('.card-modal__actions-menu button').first().click()
}

async function act(page, kind) {
	await openMenu(page)
	if (kind === 'archive') await page.locator('[data-test="card-archived-toggle"] label').click()
	else await page.getByRole('menuitem', { name: 'Delete', exact: true }).click()
}

async function observeDestination(page, boardId, cardId) {
	await page.evaluate(({ boardId, cardId }) => {
		const client = document.querySelector('#kanso').__vue_app__._context.provides.VUE_QUERY_CLIENT
		window.lifecycleSamples = []
		client.getQueryCache().subscribe(() => {
			const board = client.getQueryData(['board', String(boardId)])
			window.lifecycleSamples.push({
				boardId: board?.board.id,
				title: board?.cards.find((card) => card.id === cardId)?.title,
			})
		})
	}, { boardId, cardId })
}

for (const kind of ['archive', 'delete']) {
	test(`a delayed ${kind} failure never replaces the destination board, even transiently`, async ({ page }) => {
		const f = await fixture()
		const started = deferred()
		const release = deferred()
		try {
			await page.route(new RegExp(`/cards/${f.cardA.id}$`), async (route) => {
				if (route.request().method() !== (kind === 'archive' ? 'PATCH' : 'DELETE')) return route.continue()
				started.resolve()
				await release.promise
				await route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ error: 'Refused lifecycle action' }) })
			})
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/board/${f.a.id}/card/${f.cardA.id}`)
			await act(page, kind)
			await started.promise
			await navigate(page, f.b.id, f.cardB.id)
			await expect(page.locator('.card-modal__title')).toHaveText('Lifecycle destination card')
			await page.locator('.card-modal__title').click()
			await page.locator('.card-modal__title-input').fill('Destination edited during failure')
			await page.locator('.card-modal__title-input').press('Enter')
			await expect(page.locator('.card-tile').filter({ hasText: 'Destination edited during failure' })).toBeAttached()
			await observeDestination(page, f.b.id, f.cardB.id)
			release.resolve()
			await page.waitForFunction(() => {
				const client = document.querySelector('#kanso').__vue_app__._context.provides.VUE_QUERY_CLIENT
				return client.getMutationCache().getAll().some((mutation) => mutation.state.status === 'error')
			})
			const samples = await page.evaluate(() => window.lifecycleSamples)
			expect(samples.length).toBeGreaterThan(0)
			expect(samples.every((sample) => sample.boardId === f.b.id && sample.title === 'Destination edited during failure'), JSON.stringify(samples)).toBe(true)
			await expect(page.locator('.card-modal__title')).toHaveText('Destination edited during failure')
			await expect(page.locator('.card-modal__action-error')).toHaveCount(0)
			await navigate(page, f.a.id)
			await expect(page.locator('.card-tile').filter({ hasText: 'Lifecycle origin card' })).toBeVisible()
			expect((await api.get(`/cards/${f.cardA.id}`)).archived).toBe(false)
		} finally {
			release.resolve()
			await f.cleanup()
		}
	})

	test(`a delayed ${kind} success keeps the newly opened card and Undo targets the original card`, async ({ page }) => {
		const f = await fixture()
		const started = deferred()
		const release = deferred()
		try {
			await page.route(new RegExp(`/cards/${f.cardA.id}$`), async (route) => {
				if (route.request().method() !== (kind === 'archive' ? 'PATCH' : 'DELETE')) return route.continue()
				const response = await route.fetch()
				started.resolve()
				await release.promise
				await route.fulfill({ response })
			})
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/board/${f.a.id}/card/${f.cardA.id}`)
			await act(page, kind)
			await started.promise
			await navigate(page, f.b.id, f.cardB.id)
			await expect(page.locator('.card-modal__title')).toHaveText('Lifecycle destination card')
			release.resolve()
			const undo = toast(page, kind === 'archive' ? 'Card archived' : 'Card deleted')
			// short-budget-ok: the Undo toast expires after ten seconds.
			await expect(undo).toBeVisible({ timeout: 5000 })
			await expect(page.locator('.card-modal__title')).toHaveText('Lifecycle destination card')
			const restored = page.waitForResponse((response) => response.url().endsWith(`/cards/${f.cardA.id}${kind === 'delete' ? '/restore' : ''}`)
				&& response.request().method() === (kind === 'delete' ? 'POST' : 'PATCH') && response.ok())
			// short-budget-ok: click within the Undo toast's lifetime.
			await undo.getByRole('button', { name: 'Undo', exact: true }).click({ timeout: 3000 })
			await restored
			expect((await api.get(`/cards/${f.cardA.id}`)).archived).toBe(false)
			expect((await api.get(`/cards/${f.cardB.id}`)).archived).toBe(false)
			await expect(page.locator('.card-modal__title')).toHaveText('Lifecycle destination card')
		} finally {
			release.resolve()
			await f.cleanup()
		}
	})
}

test('deleting a recurring card keeps its target while stopping the rule is delayed', async ({ page }) => {
	const f = await fixture()
	const started = deferred()
	const release = deferred()
	try {
		const rule = await api.post(`/boards/${f.a.id}/recur-rules`, {
			templateCardId: f.cardA.id, targetStackId: f.cardA.stackId, mode: 1, rrule: 'FREQ=WEEKLY',
		})
		await page.route(new RegExp(`/recur-rules/${rule.id}$`), async (route) => {
			if (route.request().method() !== 'DELETE') return route.continue()
			const response = await route.fetch()
			started.resolve()
			await release.promise
			await route.fulfill({ response })
		})
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${f.a.id}/card/${f.cardA.id}`)
		await act(page, 'delete')
		await page.getByRole('button', { name: 'Delete and stop recurrence', exact: true }).click()
		await started.promise
		await navigate(page, f.b.id, f.cardB.id)
		await expect(page.locator('.card-modal__title')).toHaveText('Lifecycle destination card')
		const deleted = page.waitForResponse((response) => response.url().endsWith(`/cards/${f.cardA.id}`)
			&& response.request().method() === 'DELETE' && response.ok())
		release.resolve()
		await deleted
		// short-budget-ok: the Undo toast expires after ten seconds.
		await expect(toast(page, 'Card deleted')).toBeVisible({ timeout: 5000 })
		await expect(page.locator('.card-modal__title')).toHaveText('Lifecycle destination card')
		expect((await api.get(`/cards/${f.cardB.id}`)).title).toBe('Lifecycle destination card')
		expect((await api.get(`/boards/${f.a.id}/trash`)).map((card) => card.id)).toContain(f.cardA.id)
	} finally {
		release.resolve()
		await f.cleanup()
	}
})

test('returning to the original card does not revive an older modal-close continuation', async ({ page }) => {
	const f = await fixture()
	const started = deferred()
	const release = deferred()
	try {
		await page.route(new RegExp(`/cards/${f.cardA.id}$`), async (route) => {
			if (route.request().method() !== 'PATCH') return route.continue()
			const response = await route.fetch()
			started.resolve()
			await release.promise
			await route.fulfill({ response })
		})
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${f.a.id}/card/${f.cardA.id}`)
		await act(page, 'archive')
		await started.promise
		await navigate(page, f.b.id, f.cardB.id)
		await expect(page.locator('.card-modal__title')).toHaveText('Lifecycle destination card')
		await navigate(page, f.a.id, f.cardA.id)
		await expect(page.locator('.card-modal__title')).toHaveText('Lifecycle origin card')
		release.resolve()
		// short-budget-ok: the Undo toast expires after ten seconds.
		await expect(toast(page, 'Card archived')).toBeVisible({ timeout: 5000 })
		await expect(page.locator('.card-modal__title')).toHaveText('Lifecycle origin card')
	} finally {
		release.resolve()
		await f.cleanup()
	}
})
