// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

function deferred() {
	let resolve
	const promise = new Promise((done) => { resolve = done })
	return { promise, resolve }
}

async function fixture() {
	const board = await api.post('/boards', { title: `Hierarchy races ${Date.now()}` })
	const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
	const cards = []
	for (const title of ['Race child', 'Race parent A', 'Race parent B']) cards.push(await api.post('/cards', { stackId: stack.id, title }))
	return { board, child: cards[0], a: cards[1], b: cards[2], cleanup: () => api.delete(`/boards/${board.id}`) }
}

async function setParent(page, id) {
	await page.locator('.card-modal__actions-menu button').first().click()
	await page.getByRole('menuitem', { name: 'Make this a sub-card of…', exact: true }).click()
	await page.locator('.card-modal__relation-target').selectOption(String(id))
	await page.getByRole('button', { name: 'Set parent', exact: true }).click()
}

async function editTitle(page) {
	await page.locator('.card-modal__title').click()
	await page.locator('.card-modal__title-input').fill('Edited during hierarchy failure')
	const saved = page.waitForResponse((response) => response.request().method() === 'PATCH' && /\/cards\/\d+$/.test(response.url()) && response.ok())
	await page.locator('.card-modal__title-input').press('Enter')
	await saved
	await expect(page.locator('.card-modal__title')).toHaveText('Edited during hierarchy failure')
	await expect(page.locator('.card-tile').filter({ hasText: 'Edited during hierarchy failure' })).toBeAttached()
}

async function observe(page, f) {
	await page.evaluate(({ boardId, cardId }) => {
		const client = document.querySelector('#kanso').__vue_app__._context.provides.VUE_QUERY_CLIENT
		window.hierarchySamples = []
		client.getQueryCache().subscribe(() => {
			const card = client.getQueryData(['board', String(boardId)])?.cards.find((card) => card.id === cardId)
			window.hierarchySamples.push({ parent: card?.parentCardId, title: card?.title })
		})
	}, { boardId: f.board.id, cardId: f.child.id })
}

async function drag(page, source, target) {
	const from = await source.boundingBox()
	const to = await target.boundingBox()
	if (!from || !to) throw new Error('Missing card drag target')
	await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2)
	await page.mouse.down()
	for (let step = 1; step <= 15; step++) {
		await page.mouse.move(from.x + from.width / 2 + (to.x + to.width / 2 - from.x - from.width / 2) * step / 15, from.y + from.height / 2 + (to.y + to.height / 2 - from.y - from.height / 2) * step / 15)
		await page.evaluate(() => new Promise(requestAnimationFrame))
	}
	for (const offset of [-1, 1, -1, 1]) {
		await page.mouse.move(to.x + to.width / 2 + offset, to.y + to.height / 2)
		await page.evaluate(() => new Promise(requestAnimationFrame))
	}
	await expect(target).toHaveClass(/card-tile--nest-target/)
	await page.mouse.up()
	await expect(page.locator('.card-tile-wrap--dragging')).toHaveCount(0)
}

test('a rejected parent change preserves a title saved while the request is delayed', async ({ page }) => {
	const f = await fixture()
	const started = deferred()
	const release = deferred()
	const errors = []
	page.on('pageerror', (error) => errors.push(error.message))
	try {
		await page.route(new RegExp(`/cards/${f.child.id}/parent$`), async (route) => {
			started.resolve()
			await release.promise
			await route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ error: 'Refused parent change' }) })
		})
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${f.board.id}/card/${f.child.id}`)
		await expect(page.locator('.card-modal__title')).toHaveText('Race child')
		await setParent(page, f.a.id)
		await started.promise
		await expect(page.locator('.card-modal__parent-link')).toHaveText('Race parent A')
		await editTitle(page)
		await observe(page, f)
		release.resolve()
		await expect(page.locator('.card-modal__save-error', { hasText: 'Refused parent change' })).toBeVisible()
		const samples = await page.evaluate(() => window.hierarchySamples)
		expect(samples.length).toBeGreaterThan(0)
		expect(samples.every((sample) => sample.title === 'Edited during hierarchy failure'), JSON.stringify(samples)).toBe(true)
		await expect(page.locator('.card-modal__title')).toHaveText('Edited during hierarchy failure')
		await expect(page.locator('.card-modal__parent-link')).toHaveCount(0)
		const stored = await api.get(`/cards/${f.child.id}`)
		expect(stored.title).toBe('Edited during hierarchy failure')
		expect(stored.parentCardId).toBe(null)
		expect(errors).toEqual([])
	} finally {
		release.resolve()
		await f.cleanup()
	}
})

test('a detach requested during a pending drag executes after its delayed failure', async ({ page }) => {
	const f = await fixture()
	const started = deferred()
	const release = deferred()
	const errors = []
	let writes = 0
	page.on('pageerror', (error) => errors.push(error.message))
	try {
		await page.route(new RegExp(`/cards/${f.child.id}/parent$`), async (route) => {
			writes++
			if (writes !== 1) return route.continue()
			started.resolve()
			await release.promise
			await route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ error: 'Refused first drag' }) })
		})
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${f.board.id}`)
		const tile = (title) => page.locator('.card-tile').filter({ hasText: title })
		await expect(tile('Race child')).toBeVisible()
		await drag(page, tile('Race child'), tile('Race parent A'))
		await started.promise
		await expect(tile('Race child').locator('.card-tile__subcard')).toBeVisible()
		await tile('Race child').click()
		await expect(page.locator('.card-modal__parent-link')).toHaveText('Race parent A')
		await page.getByRole('button', { name: 'Detach', exact: true }).click()
		await expect(page.locator('.card-modal__parent-link')).toHaveCount(0)
		await editTitle(page)
		expect(writes).toBe(1)
		await observe(page, f)
		const saved = page.waitForResponse((response) => response.url().endsWith(`/cards/${f.child.id}/parent`) && response.ok())
		release.resolve()
		await saved
		await page.waitForFunction(() => {
			const client = document.querySelector('#kanso').__vue_app__._context.provides.VUE_QUERY_CLIENT
			return client.isMutating() === 0
		})
		const samples = await page.evaluate(() => window.hierarchySamples)
		expect(samples.length).toBeGreaterThan(0)
		expect(samples.every((sample) => sample.parent === null && sample.title === 'Edited during hierarchy failure'), JSON.stringify(samples)).toBe(true)
		expect(writes).toBe(2)
		const stored = await api.get(`/cards/${f.child.id}`)
		expect(stored.parentCardId).toBe(null)
		expect(stored.title).toBe('Edited during hierarchy failure')
		await expect(page.locator('.card-modal__parent-link')).toHaveCount(0)
		expect(errors).toEqual([])
	} finally {
		release.resolve()
		await f.cleanup()
	}
})
