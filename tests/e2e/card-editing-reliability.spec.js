// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

async function seedCard(title) {
	const board = await api.post('/boards', { title: `${title} ${Date.now()}` })
	const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
	const card = await api.post('/cards', { stackId: stack.id, title })
	return { board, card, url: `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}` }
}

test('read-only descriptions and label chips keep their content without offering writes', async ({ browser, peer }) => {
	const { board, card, url } = await seedCard('Read-only description')
	const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
	try {
		await api.patch(`/cards/${card.id}`, { description: 'Read this [reference](https://example.com/guide).' })
		const label = await api.post('/labels', { boardId: board.id, title: 'Keep visible', color: '0082c9' })
		await api.put(`/cards/${card.id}/labels/${label.id}`)
		await api.post(`/boards/${board.id}/acl`, { participant: peer.user, participantType: 'user', permission: 1 })
		const page = await context.newPage()
		await ncLogin(page, { user: peer.user, pass: peer.pass })
		await page.goto(url)
		const section = page.locator('.card-modal__section', { has: page.locator('.card-modal__desc-rendered') })
		await expect(section.locator('.card-modal__desc-rendered')).toContainText('Read this reference.')
		await expect(section.getByRole('button', { name: 'Edit', exact: true })).toHaveCount(0)
		await section.locator('.card-modal__desc-view').click()
		await expect(section.locator('.kanso-md-editor')).toHaveCount(0)
		await expect(section.getByRole('link', { name: 'reference' })).toHaveAttribute('href', 'https://example.com/guide')
		const chip = page.locator('.card-modal__label-chip', { hasText: 'Keep visible' })
		await expect(chip).toBeVisible()
		expect(await chip.evaluate((element) => element.tagName)).toBe('SPAN')
		await chip.click()
		expect((await api.get(`/cards/${card.id}`)).labelIds).toEqual([label.id])
		expect((await peer.api.raw('PATCH', `/cards/${card.id}`, { description: 'Forbidden' })).status).toBe(403)
		await api.patch(`/cards/${card.id}`, { description: '' })
		await page.reload()
		await expect(page.locator('.card-modal__title')).toHaveText(card.title)
		await expect(page.locator('.card-modal__desc-placeholder')).toHaveCount(0)
	} finally {
		await context.close()
		await api.delete(`/boards/${board.id}`).catch(() => {})
	}
})

test('label chips remove by mouse and keyboard while another removal is pending, and show refusal', async ({ page }) => {
	const { board, card, url } = await seedCard('Remove label chips')
	let release
	const held = new Promise((resolve) => { release = resolve })
	try {
		const labels = []
		for (const title of ['Mouse', 'Enter', 'Space']) {
			const label = await api.post('/labels', { boardId: board.id, title, color: '0082c9' })
			labels.push(label)
			await api.put(`/cards/${card.id}/labels/${label.id}`)
		}
		let arrived
		const started = new Promise((resolve) => { arrived = resolve })
		let first = true
		await page.route((u) => u.pathname.includes(`/cards/${card.id}/labels/`), async (route) => {
			if (route.request().method() === 'DELETE' && first) {
				first = false
				arrived()
				await held
			}
			await route.continue()
		})
		await ncLogin(page)
		await page.goto(url)
		const mouse = page.getByRole('button', { name: 'Remove label Mouse', exact: true })
		await expect(mouse).toBeVisible()
		await mouse.click()
		await started
		await page.getByRole('button', { name: 'Remove label Enter', exact: true }).press('Enter')
		await page.getByRole('button', { name: 'Remove label Space', exact: true }).press('Space')
		release()
		await expect.poll(async () => (await api.get(`/cards/${card.id}`)).labelIds).toEqual([])
		await expect(page).toHaveURL(url)
		await page.reload()
		await expect(page.locator('.card-modal__title')).toHaveText(card.title)
		await expect(page.locator('.card-modal__label-chip')).toHaveCount(0)

		await api.put(`/cards/${card.id}/labels/${labels[0].id}`)
		await page.reload()
		await page.route((u) => u.pathname.endsWith(`/cards/${card.id}/labels/${labels[0].id}`), (route) => route.request().method() === 'DELETE'
			? route.fulfill({ status: 403, json: { error: 'Label removal refused' } })
			: route.continue())
		await page.getByRole('button', { name: 'Remove label Mouse', exact: true }).click()
		await expect(page.getByText('Label removal refused', { exact: true })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Remove label Mouse', exact: true })).toBeVisible()
		expect((await api.get(`/cards/${card.id}`)).labelIds).toEqual([labels[0].id])
	} finally {
		release()
		await page.unrouteAll({ behavior: 'wait' })
		await api.delete(`/boards/${board.id}`).catch(() => {})
	}
})

for (const width of [320, 375]) {
	test(`the visibility selector fits ${width}px content for editors and disabled peers`, async ({ page, browser, peer }) => {
		const { board, card, url } = await seedCard('Narrow visibility selector')
		const context = await browser.newContext({ viewport: { width, height: 844 }, storageState: { cookies: [], origins: [] } })
		try {
			await api.post(`/boards/${board.id}/acl`, { participant: peer.user, participantType: 'user', permission: 3 })
			await page.setViewportSize({ width, height: 844 })
			await ncLogin(page)
			await page.goto(url)
			const selector = page.getByLabel('Card visibility', { exact: true })
			const fits = async (control) => {
				await control.scrollIntoViewIfNeeded()
				const bounds = await control.evaluate((element) => {
					const field = element.getBoundingClientRect()
					const content = element.closest('.card-modal__content').getBoundingClientRect()
					return { left: field.left, right: field.right, contentLeft: content.left, contentRight: content.right }
				})
				expect(bounds.left).toBeGreaterThanOrEqual(bounds.contentLeft - 1)
				expect(bounds.right).toBeLessThanOrEqual(bounds.contentRight + 1)
			}
			await expect(selector).toBeEnabled()
			await fits(selector)
			await selector.selectOption('internal')
			await expect.poll(async () => (await api.get(`/cards/${card.id}`)).visibility).toBe('internal')
			await selector.selectOption('public')
			await expect.poll(async () => (await api.get(`/cards/${card.id}`)).visibility).toBe('public')
			const peerPage = await context.newPage()
			await ncLogin(peerPage, { user: peer.user, pass: peer.pass })
			await peerPage.goto(url)
			const disabled = peerPage.getByLabel('Card visibility', { exact: true })
			await expect(disabled).toBeDisabled()
			await fits(disabled)
		} finally {
			await context.close()
			await api.delete(`/boards/${board.id}`).catch(() => {})
		}
	})
}

for (const allDay of [false, true]) {
	test(`a partial empty ${allDay ? 'date' : 'datetime'} survives a remote date change while untouched focus follows it`, async ({ page, peer }) => {
		const { board, card, url } = await seedCard('Collaborative date draft')
		try {
			await api.patch(`/cards/${card.id}`, { allDay })
			await api.post(`/boards/${board.id}/acl`, { participant: peer.user, participantType: 'user', permission: 3 })
			await ncLogin(page)
			await page.goto(url)
			await page.locator('[data-pill=due]').click()
			const input = page.locator('[data-date=end]')
			await input.focus()
			await input.press('Home')
			await input.press('1')
			await expect(input).toHaveValue('')
			expect(await input.evaluate((element) => element.validity.badInput)).toBe(true)
			await peer.api.patch(`/cards/${card.id}`, { duedate: '2031-06-20T11:00:00Z', title: 'Remote date arrived' })
			await expect(page.locator('.card-modal__title')).toHaveText('Remote date arrived')
			await expect(input).toBeFocused()
			await expect(input).toHaveValue('')
			expect(await input.evaluate((element) => element.validity.badInput)).toBe(true)

			await api.patch(`/cards/${card.id}`, { duedate: '' })
			await page.reload()
			await page.locator('[data-pill=due]').click()
			await input.focus()
			const previous = await input.inputValue()
			expect(previous).toBe('')
			await peer.api.patch(`/cards/${card.id}`, { duedate: '2032-07-21T12:00:00Z', title: 'Untouched date updated' })
			await expect(page.locator('.card-modal__title')).toHaveText('Untouched date updated')
			await expect(input).toBeFocused()
			await expect(input).not.toHaveValue(previous)
			await expect(input).toHaveValue(/^2032-07-21/)
		} finally {
			await api.delete(`/boards/${board.id}`).catch(() => {})
		}
	})
}
