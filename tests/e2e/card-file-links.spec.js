// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, ncLogin, BASE, currentAuth, me, api } from './helpers.js'

// KANSO-307 — live links from a card to Nextcloud Files nodes. Unlike "Choose
// from Files" (a COPY, pinned in card-attachments.spec.js), a link stores only
// the node's id + a filename snapshot and opens NC's own /f/<fileid> permalink,
// so Files' ACL decides on open.

// DAV root of the CURRENT user's own Files, built at call time so it reads the
// live `me` binding (per-worker rebind under E2E_ISOLATE).
const davUrl = () => BASE + '/remote.php/dav/files/' + me

async function putUserFile(name, content) {
	const r = await fetch(`${davUrl()}/${name}`, {
		method: 'PUT',
		headers: { Authorization: currentAuth },
		body: content,
	})
	if (!r.ok && r.status !== 201 && r.status !== 204) {
		throw new Error(`PUT ${name} failed: ${r.status}`)
	}
}

async function deleteUserFile(name) {
	await fetch(`${davUrl()}/${name}`, {
		method: 'DELETE',
		headers: { Authorization: currentAuth },
	}).catch(() => {})
}

// The node's numeric file id, read the way any DAV client does (oc:fileid).
async function fileIdOf(name) {
	const r = await fetch(`${davUrl()}/${name}`, {
		method: 'PROPFIND',
		headers: { Authorization: currentAuth, Depth: '0', 'Content-Type': 'application/xml' },
		body: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
			+ '<d:prop><oc:fileid/></d:prop></d:propfind>',
	})
	const xml = await r.text()
	const m = xml.match(/<oc:fileid>(\d+)<\/oc:fileid>/)
	if (!m) throw new Error(`PROPFIND ${name} → ${r.status}: no oc:fileid`)
	return Number(m[1])
}

test.describe('Card file links', () => {
	let boardId = 0
	let cardId = 0

	test.beforeAll(async () => {
		boardId = (await api.post('/boards', { title: 'File links E2E' })).id
		const stackId = (await api.post('/stacks', { boardId, title: 'Tasks' })).id
		cardId = (await api.post('/cards', { stackId, title: 'Card with file links' })).id
	})

	test.afterAll(async () => {
		if (boardId) await api.raw('DELETE', `/boards/${boardId}`)
	})

	test('link an existing Files item through the picker; the chip opens the /f/ permalink', async ({ page }) => {
		const name = `kanso-linked-${Date.now()}.txt`
		await putUserFile(name, 'linked from Files')

		try {
			const fileId = await fileIdOf(name)
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/board/${boardId}/card/${cardId}`)
			await page.waitForSelector('.card-modal', { timeout: 15_000 })

			await page.locator('.card-modal__file-link-add').click()
			await expect(page.locator('.file-picker')).toBeVisible()

			await page.locator('.file-picker__filter-input input').fill(name)
			const pickerRow = page.locator('[data-testid="file-list-row"]', { hasText: name })
			await expect(pickerRow).toHaveCount(1)
			await pickerRow.click()
			await page.getByRole('button', { name: 'Link file', exact: true }).click()

			// A LINK chip (not an attachment row): the filename, pointing at NC's
			// own permalink for the node rather than any Kanso byte endpoint.
			const chip = page.locator('a.card-modal__file-link', { hasText: name })
			await expect(chip).toHaveCount(1, { timeout: 15_000 })
			const href = await chip.getAttribute('href')
			expect(href).toMatch(new RegExp(`/f/${fileId}$`))
			expect(href).not.toContain('/attachments/')
			await expect(chip).toHaveAttribute('target', '_blank')

			// Server state agrees: one link row naming that node.
			const links = await api.get(`/cards/${cardId}/file-links`)
			const mine = links.filter((l) => l.fileId === fileId)
			expect(mine).toHaveLength(1)
			expect(mine[0].name).toBe(name)

			await api.delete(`/cards/${cardId}/file-links/${mine[0].id}`)
		} finally {
			await deleteUserFile(name)
		}
	})

	test('an API-linked file shows as a chip and can be removed from the card', async ({ page }) => {
		const name = `kanso-api-linked-${Date.now()}.txt`
		await putUserFile(name, 'linked over the API')

		try {
			const fileId = await fileIdOf(name)
			const link = await api.post(`/cards/${cardId}/file-links`, { fileId })
			expect(link.fileId).toBe(fileId)
			expect(link.name).toBe(name)

			// Re-linking the same node is idempotent - the same row comes back.
			const again = await api.post(`/cards/${cardId}/file-links`, { fileId })
			expect(again.id).toBe(link.id)

			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/board/${boardId}/card/${cardId}`)
			await page.waitForSelector('.card-modal', { timeout: 15_000 })

			const row = page.locator('.card-modal__link-row', { has: page.locator('a.card-modal__file-link', { hasText: name }) })
			await expect(row).toHaveCount(1, { timeout: 15_000 })

			await row.getByRole('button', { name: `Remove link to ${name}` }).click()
			await expect(page.locator('a.card-modal__file-link', { hasText: name })).toHaveCount(0, { timeout: 8000 })

			// Unlinking removes the reference only - the file itself stays in Files.
			const links = await api.get(`/cards/${cardId}/file-links`)
			expect(links.filter((l) => l.fileId === fileId)).toHaveLength(0)
			expect(await fileIdOf(name)).toBe(fileId)
		} finally {
			await deleteUserFile(name)
		}
	})

	test('a fileId the caller cannot read is refused and stores nothing', async () => {
		const r = await api.raw('POST', `/cards/${cardId}/file-links`, { fileId: 999999999 })
		expect(r.status).toBe(400)
		const links = await api.get(`/cards/${cardId}/file-links`)
		expect(links.filter((l) => l.fileId === 999999999)).toHaveLength(0)
	})
})
