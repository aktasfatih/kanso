// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// Email intake (#117). There is no IMAP server in CI, so this deliberately does
// NOT try to poll a mailbox — the polling logic is covered by unit tests against
// a scripted server. What only a browser can prove is that the config panel is
// actually wired: that it renders, that it saves through the API, and above all
// that the stored password never comes back to the client.
//
// That last one is the reason this spec exists rather than an API-level test:
// the password round-trip is a property of what the *browser* receives.
test.describe('Email intake configuration', () => {
	const state = { boardId: 0, stackId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Mail intake E2E ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Incoming' })
		state.stackId = stack.id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	async function openIntakePanel(page) {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /automation/i }).click()
		await expect(page.locator('#bs-pane-automation')).toBeVisible({ timeout: 8_000 })

		await page.getByRole('button', { name: /Email intake/i }).click()
		const body = page.locator('#bs-automation-mail-intake')
		await expect(body).toBeVisible()
		return body
	}

	test('the panel saves a mailbox and never returns the password', async ({ page }) => {
		const body = await openIntakePanel(page)

		// The caveat has to be on screen: with an empty allowlist this address is
		// open to anyone who learns it.
		await expect(body.getByText(/Anyone who knows the address can create cards/i)).toBeVisible()

		await body.locator('#bs-mail-host').fill('imap.example.com')
		await body.locator('#bs-mail-username').fill('cards@example.com')
		await body.locator('input[type="password"]').fill('super-secret-value')
		await body.locator('#bs-mail-folder').fill('INBOX')
		await body.locator('select').last().selectOption(String(state.stackId))

		// Capture what the server sends back for the save AND for the reload.
		const responses = []
		page.on('response', async (res) => {
			if (res.url().includes('/mail-intake')) {
				responses.push(await res.text().catch(() => ''))
			}
		})

		await body.getByRole('button', { name: /^Save$/ }).click()
		await expect(body.getByText(/Saved\./)).toBeVisible({ timeout: 8_000 })

		// The credential must not travel back to the browser in any form.
		for (const payload of responses) {
			expect(payload).not.toContain('super-secret-value')
		}
		expect(responses.join('')).toContain('hasPassword')
	})

	test('a saved mailbox reloads without the password and keeps it on re-save', async ({ page }) => {
		const body = await openIntakePanel(page)

		// Settings come back...
		await expect(body.locator('#bs-mail-host')).toHaveValue('imap.example.com')
		await expect(body.locator('#bs-mail-username')).toHaveValue('cards@example.com')
		// ...but the password field is empty, with the placeholder explaining that
		// leaving it so keeps the stored one.
		const password = body.locator('input[type="password"]')
		await expect(password).toHaveValue('')
		await expect(password).toHaveAttribute('placeholder', /leave blank to keep it/i)

		// Re-saving without retyping the password must not wipe it - that is the
		// whole reason an empty field means "unchanged" rather than "clear".
		await body.locator('#bs-mail-folder').fill('Archive')
		await body.getByRole('button', { name: /^Save$/ }).click()
		await expect(body.getByText(/Saved\./)).toBeVisible({ timeout: 8_000 })

		const config = await api.get(`/boards/${state.boardId}/mail-intake`)
		expect(config.hasPassword).toBe(true)
		expect(config.mailbox).toBe('Archive')
		expect(config).not.toHaveProperty('password')
	})

	test('the server refuses a mailbox pointed at a private address', async () => {
		// The SSRF guard runs at connect time, so a save is allowed but the test
		// connection must refuse rather than dial the internal network.
		await api.put(`/boards/${state.boardId}/mail-intake`, {
			stackId: state.stackId,
			host: '127.0.0.1',
			port: 993,
			encryption: 'ssl',
			username: 'cards@example.com',
			password: 'irrelevant',
			mailbox: 'INBOX',
			senderAllowlist: '',
			enabled: false,
		})

		const result = await api.post(`/boards/${state.boardId}/mail-intake/test`)

		expect(result.ok).toBe(false)
		expect(result.error).toMatch(/loopback|private|reserved|restricted/i)
	})

	test('removing the mailbox clears it', async ({ page }) => {
		const body = await openIntakePanel(page)

		await body.getByRole('button', { name: /Remove mailbox/i }).click()
		await expect(body.getByText(/Mailbox removed\./)).toBeVisible({ timeout: 8_000 })

		const config = await api.get(`/boards/${state.boardId}/mail-intake`)
		// A board with no mailbox reports null rather than 404 - the form renders
		// empty from it.
		expect(config === null || config === '').toBeTruthy()
	})
})
