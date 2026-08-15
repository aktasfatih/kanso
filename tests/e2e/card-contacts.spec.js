// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const DAV = BASE + '/remote.php/dav/addressbooks/users/admin/contacts'
const HEADERS = {
	'OCS-APIREQUEST': 'true',
	'Content-Type': 'application/json',
}
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')

// A stable UID we control, so the picker + link resolve deterministically.
const CONTACT_UID = 'kanso-e2e-contact-1'
const CONTACT_FN = 'Casey Contact E2E'

async function apiGet(path) {
	const r = await fetch(API + path, { headers: { ...HEADERS, Authorization: AUTH } })
	if (!r.ok) throw new Error(`GET ${path} → ${r.status}`)
	return r.json()
}

async function apiPost(path, body) {
	const r = await fetch(API + path, {
		method: 'POST',
		headers: { ...HEADERS, Authorization: AUTH },
		body: JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`POST ${path} → ${r.status}: ${await r.text()}`)
	return r.json()
}

async function apiDelete(path) {
	const r = await fetch(API + path, {
		method: 'DELETE',
		headers: { ...HEADERS, Authorization: AUTH },
	})
	if (!r.ok && r.status !== 404) throw new Error(`DELETE ${path} → ${r.status}`)
}

async function putVCard() {
	const vcard = [
		'BEGIN:VCARD',
		'VERSION:3.0',
		`UID:${CONTACT_UID}`,
		`FN:${CONTACT_FN}`,
		'EMAIL:casey.e2e@example.com',
		'END:VCARD',
	].join('\r\n')
	const r = await fetch(`${DAV}/${CONTACT_UID}.vcf`, {
		method: 'PUT',
		headers: { Authorization: AUTH, 'Content-Type': 'text/vcard' },
		body: vcard,
	})
	if (!r.ok) throw new Error(`PUT vcard → ${r.status}`)
}

async function deleteVCard() {
	await fetch(`${DAV}/${CONTACT_UID}.vcf`, {
		method: 'DELETE',
		headers: { Authorization: AUTH },
	}).catch(() => {})
}

async function ncLogin(page) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})

	const userInput = page.locator('#user')
	const isLoginPage = await userInput.isVisible({ timeout: 3000 }).catch(() => false)
	if (!isLoginPage) return

	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

test.describe('Card contacts (#3530)', () => {
	const state = { boardId: 0, cardId: 0 }

	test.beforeAll(async () => {
		await putVCard()

		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'Contacts Test Board') {
				await apiDelete(`/boards/${b.id}`)
			}
		}
		const board = await apiPost('/boards', { title: 'Contacts Test Board' })
		state.boardId = board.id
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'S1' })
		const card = await apiPost('/cards', { stackId: stack.id, title: 'Contact Card' })
		state.cardId = card.id
	})

	test.afterAll(async () => {
		if (state.boardId) await apiDelete(`/boards/${state.boardId}`)
		await deleteVCard()
	})

	test('link a contact from the card modal: chip renders and persists', async ({ page }) => {
		const cardUrl = `${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.cardId}`

		await ncLogin(page)
		await page.goto(cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// The picker only appears when the Contacts app is available (feature-detected).
		const linkBtn = page.locator('.card-modal__attr button', { hasText: /link contact/i })
		await expect(linkBtn).toBeVisible({ timeout: 8000 })
		await linkBtn.click()

		// Search the address book for our seeded contact.
		await page.locator('.card-modal__contact-search').fill('Casey')
		const option = page.locator('.card-modal__assign-option', { hasText: CONTACT_FN })
		await expect(option).toBeVisible({ timeout: 8000 })
		await option.click()

		// The chip appears in the attribute bar, no error.
		await expect(
			page.locator('.card-modal__assignee-pill', { hasText: CONTACT_FN }),
		).toBeVisible({ timeout: 8000 })
		await expect(page.locator('.card-modal__save-error')).toHaveCount(0)

		// The server persisted the link (denormalized display-name snapshot).
		const cardPayload = await apiGet(`/cards/${state.cardId}`)
		const linked = (cardPayload.contacts || []).find((c) => c.contactUri === CONTACT_UID)
		expect(linked).toBeTruthy()
		expect(linked.displayName).toBe(CONTACT_FN)

		// And it survives a reload (persisted, not just optimistic UI).
		await page.reload()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })
		await expect(
			page.locator('.card-modal__assignee-pill', { hasText: CONTACT_FN }),
		).toBeVisible({ timeout: 8000 })
	})
})
