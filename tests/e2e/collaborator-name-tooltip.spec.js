// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #126 — "the name of the selected person is under the tooltip".
//
// Wherever a collaborator avatar sits next to that same collaborator's name,
// NcAvatar's tooltip was left enabled. In @nextcloud/vue 9 that tooltip is a
// plain native `title=` attribute on the avatar's root `span.avatardiv`, so the
// browser paints it AT THE CURSOR — i.e. directly on top of the name it is
// duplicating 8px away. It is browser chrome: no z-index, stacking context or
// popper placement can move it, so the only fix is not to ask for it.
//
// The mirror-image defect was the same rows' name spans: ellipsized with no
// `title`, so a truncated name could not be read at all. So the tooltip does
// not disappear, it MOVES to where it is useful — off the avatar, onto the
// (possibly truncated) name span.
//
// IMPORTANT FOR FUTURE READERS: a Playwright test cannot assert that a native
// tooltip is or is not PAINTED — native tooltips are rendered by the browser
// outside the page, invisible to the DOM, to screenshots and to CDP. The
// `title` attribute IS the assertion here, because the attribute is the entire
// mechanism. Do not "improve" this into a hover + screenshot check; it would
// assert nothing and flake on the OS tooltip delay.
//
// Bare avatars with no adjacent name (card tiles, card previews) deliberately
// KEEP their tooltip — there it is the only way to identify the user — and are
// out of scope here.

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Collaborator name is not covered by the avatar tooltip (#126)', () => {
	const ts = Date.now()
	const state = { boardId: 0, stackId: 0, cardId: 0 }

	test.beforeAll(async ({ peer }) => {
		const board = await api.post('/boards', { title: `Tooltip E2E ${ts}` })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'To do' })).id
		// Share with a second, real user so the sharing panel has a user entry
		// (NcAvatar) rather than only the group-icon branch.
		await api.post(`/boards/${board.id}/acl`, {
			participant: peer.user,
			participantType: 'user',
			permission: 3, // READ | EDIT
		})
		state.cardId = (await api.post('/cards', { stackId: state.stackId, title: `Tooltip card ${ts}` })).id
		// Assigning the peer is what renders the card modal's assignee pill.
		await api.put(`/cards/${state.cardId}/assignees/${peer.user}`)
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('share picker: the avatar carries no title, the name span carries its own', async ({ page, peer }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Board settings → Sharing.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.locator('#bs-rail-tab-sharing').click()

		const entry = page.locator('.sharing__entry', { hasText: peer.user })
		await expect(entry).toBeVisible({ timeout: 8_000 })

		// The avatar must not repeat the name over the cursor.
		const avatar = entry.locator('.avatardiv')
		await expect(avatar).toBeVisible()
		expect(await avatar.getAttribute('title')).toBeNull()

		// …and the (ellipsizable) name span must be readable on hover instead.
		const name = entry.locator('.sharing__entry-name')
		await expect(name).toBeVisible()
		const text = (await name.innerText()).trim()
		expect(text.length).toBeGreaterThan(0)
		expect(await name.getAttribute('title')).toBe(text)
	})

	test('card modal assignee pill: the avatar carries no title, the name span carries its own', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.cardId}`)
		await page.waitForSelector('.card-modal__content', { timeout: 15_000 })

		const pill = page.locator('.card-modal__assignee-pill').first()
		await expect(pill).toBeVisible({ timeout: 8_000 })

		const avatar = pill.locator('.avatardiv')
		await expect(avatar).toBeVisible()
		expect(await avatar.getAttribute('title')).toBeNull()

		const name = pill.locator('.card-modal__assignee-name')
		await expect(name).toBeVisible()
		const text = (await name.innerText()).trim()
		expect(text.length).toBeGreaterThan(0)
		expect(await name.getAttribute('title')).toBe(text)
	})
})
