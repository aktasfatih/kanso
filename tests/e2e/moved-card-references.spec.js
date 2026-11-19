// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

async function fixture(count = 2) {
	const boards = []
	const stacks = []
	for (let index = 0; index < count; index++) {
		const board = await api.post('/boards', { title: `Rendered references ${index} ${Date.now()}` })
		boards.push(board.id)
		await api.patch(`/boards/${board.id}`, { prefix: index === 0 ? 'ORIG' : 'NEXT' })
		stacks.push((await api.post('/stacks', { boardId: board.id, title: 'Tasks' })).id)
	}
	const target = await api.post('/cards', { stackId: stacks[0], title: 'Original referenced card' })
	const reference = `ORIG-${target.boardSeq}`
	const holder = await api.post('/cards', { stackId: stacks[0], title: 'Text containing references' })
	await api.patch(`/cards/${holder.id}`, { description: `See ${reference}.\n\n\`${reference}\` [${reference}](https://example.com)\n\n\`\`\`\n${reference}\n\`\`\`` })
	await api.post(`/cards/${holder.id}/comments`, { body: `Discuss ${reference} and ${reference}.` })
	return { boards, stacks, target, reference, holder,
		url: `${BASE}/index.php/apps/kanso#/board/${boards[0]}/card/${holder.id}`,
		cleanup: async () => { for (const id of boards) await api.delete(`/boards/${id}`).catch(() => {}) },
	}
}

test('rendered descriptions and comments follow moved references after restoration and source purge', async ({ page }) => {
	const f = await fixture(3)
	try {
		await api.post(`/cards/${f.target.id}/move-to-board`, { targetStackId: f.stacks[1] })
		await api.post(`/cards/${f.target.id}/restore`)
		await ncLogin(page)
		await page.goto(f.url)
		const restoredLink = page.locator('.card-modal__desc-rendered > p:first-child a.kanso-cardref')
		await expect(restoredLink).toHaveText(f.target.title)
		await expect(restoredLink).toHaveAttribute('data-kanso-card-id', String(f.target.id))

		const first = await api.post(`/cards/${f.target.id}/move-to-board`, { targetStackId: f.stacks[1] })
		const latest = await api.post(`/cards/${first.id}/move-to-board`, { targetStackId: f.stacks[2] })
		await api.patch(`/cards/${latest.id}`, { title: 'Live destination after two moves' })
		await api.delete(`/cards/${f.target.id}/purge`)
		await api.delete(`/cards/${first.id}/purge`)
		let lookups = 0
		page.on('request', (request) => {
			if (request.url().endsWith(`/boards/${f.boards[0]}/cards/by-ref/${f.reference}`)) lookups++
		})
		await page.reload()
		const description = page.locator('.card-modal__desc-rendered')
		const link = description.locator('a.kanso-cardref')
		await expect(link).toHaveText('Live destination after two moves')
		await expect(link).toHaveAttribute('data-kanso-card-id', String(latest.id))
		await expect(description.locator('a[href="https://example.com"]')).toHaveText(f.reference)
		await expect(description.locator('code')).toHaveCount(2)
		await expect(page.locator('.card-modal__comment-body a.kanso-cardref')).toHaveCount(2)
		expect(lookups).toBe(1)
		expect((await api.get(`/cards/${f.holder.id}`)).description).toContain(`See ${f.reference}.`)
		await link.click()
		await expect(page).toHaveURL(new RegExp(`/board/${f.boards[2]}/card/${latest.id}$`))
		await expect(page.locator('.card-modal__title')).toHaveText('Live destination after two moves')
	} finally { await f.cleanup() }
})

test('moved reference titles obey destination access and private card visibility', async ({ browser, peer }) => {
	const f = await fixture()
	const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
	try {
		const moved = await api.post(`/cards/${f.target.id}/move-to-board`, { targetStackId: f.stacks[1] })
		await api.delete(`/cards/${f.target.id}/purge`)
		await api.post(`/boards/${f.boards[0]}/acl`, { participant: peer.user, participantType: 'user', permission: 1, role: 'external' })
		const page = await context.newPage()
		await ncLogin(page, peer)
		await page.goto(f.url)
		await expect(page.locator('.card-modal__desc-rendered')).toContainText(`See ${f.reference}.`)
		await expect(page.locator('.card-modal__desc-rendered a.kanso-cardref')).toHaveCount(0)
		await api.post(`/boards/${f.boards[1]}/acl`, { participant: peer.user, participantType: 'user', permission: 1, role: 'external' })
		await page.reload()
		await expect(page.locator('.card-modal__desc-rendered a.kanso-cardref')).toHaveText(f.target.title)
		const revalidated = page.waitForResponse((response) => response.status() === 404
			&& response.url().endsWith(`/boards/${f.boards[0]}/cards/by-ref/${f.reference}`), { timeout: 45_000 })
		await api.patch(`/cards/${moved.id}`, { visibility: 'private' })
		expect((await revalidated).status()).toBe(404)
		await expect(page.locator('.card-modal__desc-rendered')).toContainText(`See ${f.reference}.`, { timeout: 45_000 })
		await expect(page.locator('.card-modal__desc-rendered a.kanso-cardref')).toHaveCount(0)
	} finally { await context.close(); await f.cleanup() }
})

test('a delayed reference response is discarded after navigation to a different card', async ({ page }) => {
	const f = await fixture()
	let release
	const held = new Promise((resolve) => { release = resolve })
	try {
		const moved = await api.post(`/cards/${f.target.id}/move-to-board`, { targetStackId: f.stacks[1] })
		await api.delete(`/cards/${f.target.id}/purge`)
		const other = await api.post('/cards', { stackId: f.stacks[0], title: 'Different reference context' })
		let arrived
		const entered = new Promise((resolve) => { arrived = resolve })
		await page.route((url) => url.pathname.endsWith(`/boards/${f.boards[0]}/cards/by-ref/${f.reference}`), async (route) => {
			arrived()
			await held
			await route.fulfill({ json: { cardId: moved.id, title: 'Stale resolved title' } })
		})
		await ncLogin(page)
		await page.goto(f.url)
		await entered
		await page.evaluate(({ boardId, cardId }) => { location.hash = `#/board/${boardId}/card/${cardId}` }, { boardId: f.boards[0], cardId: other.id })
		await expect(page.locator('.card-modal__title')).toHaveText(other.title)
		release()
		await expect(page.locator('.card-modal')).not.toContainText('Stale resolved title')
		await expect(page.locator('.card-modal a.kanso-cardref')).toHaveCount(0)
	} finally { release(); await page.unrouteAll({ behavior: 'wait' }); await f.cleanup() }
})
