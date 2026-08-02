// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const KAN = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from('admin:admin').toString('base64')

async function call(method, path, body) {
	const r = await fetch(KAN + path, {
		method,
		headers: { ...HEADERS, Authorization: AUTH },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`${method} ${path} → ${r.status}: ${await r.text()}`)
	const text = await r.text()
	return text ? JSON.parse(text) : null
}
const kanso = (m, p, b) => call(m, p, b)

// Full-board portability (#3437): seed a populated board through the Kanso
// API, export it to Kanso's own JSON envelope, import that envelope into a
// fresh board, and assert the re-export deep-equals the original modulo the
// volatile bits (ids, owner, timestamps).
test.describe('Board export / import', () => {
	let srcBoardId = 0
	let importedBoardId = 0
	const title = 'E2E Portability ' + Math.floor(Date.now() / 1000)

	// Strip the volatile fields so two exports of the "same" board compare equal.
	function normalize(doc) {
		const b = doc.board
		const stripCard = (c) => ({
			title: c.title,
			description: c.description,
			sortKey: c.sortKey,
			archived: c.archived,
			priority: c.priority,
			stackTitleRef: c.stackTitleRef, // filled in below
			labelTitles: c.labelTitles,
			checklist: (c.checklist || []).map((i) => ({ title: i.title, done: i.done })),
			comments: (c.comments || []).map((cm) => ({ body: cm.body })),
			reviews: (c.reviews || []).map((rv) => ({ reviewer: rv.reviewer, state: rv.state })),
		})

		// Resolve id-based references to STABLE names so remapped ids compare equal.
		const stackTitleById = Object.fromEntries(b.stacks.map((s) => [s.id, s.title]))
		const labelTitleById = Object.fromEntries(b.labels.map((l) => [l.id, l.title]))
		const cards = b.cards.map((c) => stripCard({
			...c,
			stackTitleRef: stackTitleById[c.stackId],
			labelTitles: (c.labelIds || []).map((id) => labelTitleById[id]).sort(),
		}))
		// Order-independent comparison of the collections.
		const bySortThenTitle = (a, z) => (a.sortKey || '').localeCompare(z.sortKey || '') || a.title.localeCompare(z.title)
		return {
			title: b.title,
			color: b.color,
			estimateScale: b.estimateScale,
			stacks: b.stacks.map((s) => ({ title: s.title, role: s.role, wipLimit: s.wipLimit })).sort((a, z) => a.title.localeCompare(z.title)),
			labels: b.labels.map((l) => ({ title: l.title, color: l.color })).sort((a, z) => a.title.localeCompare(z.title)),
			cards: cards.sort(bySortThenTitle),
		}
	}

	test.beforeAll(async () => {
		const created = await kanso('POST', '/boards', { title, color: '0082c9' })
		srcBoardId = created.id

		const todo = await kanso('POST', '/stacks', { boardId: srcBoardId, title: 'To do' })
		const done = await kanso('POST', '/stacks', { boardId: srcBoardId, title: 'Done' })

		const label = await kanso('POST', '/labels', { boardId: srcBoardId, title: 'Priority', color: 'e11d48' })

		const alpha = await kanso('POST', '/cards', { stackId: todo.id, title: 'Alpha' })
		await kanso('PATCH', `/cards/${alpha.id}`, { description: 'the alpha description', priority: 4 })
		await kanso('PUT', `/cards/${alpha.id}/labels/${label.id}`)
		await kanso('POST', `/cards/${alpha.id}/checklist`, { title: 'first step' })
		await kanso('POST', `/cards/${alpha.id}/checklist`, { title: 'second step' })
		await kanso('POST', `/cards/${alpha.id}/comments`, { body: 'a top-level comment' })

		await kanso('POST', '/cards', { stackId: done.id, title: 'Beta' })
	})

	test.afterAll(async () => {
		if (importedBoardId) await kanso('DELETE', `/boards/${importedBoardId}`).catch(() => {})
		if (srcBoardId) await kanso('DELETE', `/boards/${srcBoardId}`).catch(() => {})
	})

	test('exports a populated board, imports it, and the re-export matches', async () => {
		const original = await kanso('GET', `/boards/${srcBoardId}/export`)
		// Envelope format version (bumped to 2 when automation rules joined it).
		expect(original.kanso).toBeGreaterThanOrEqual(1)
		expect(original.board.title).toBe(title)
		expect(original.board.cards.length).toBe(2)
		// The description + checklist + comment made it into the export.
		const alpha = original.board.cards.find((c) => c.title === 'Alpha')
		expect(alpha.description).toBe('the alpha description')
		expect(alpha.checklist.length).toBe(2)
		expect(alpha.comments.length).toBe(1)
		expect(alpha.labelIds.length).toBe(1)

		// Import the exact document back into a fresh board.
		const res = await kanso('POST', '/boards/import', { document: JSON.stringify(original) })
		importedBoardId = res.boardId
		expect(res.boardId).not.toBe(srcBoardId)
		expect(res.stacks).toBe(2)
		expect(res.cards).toBe(2)
		expect(res.labels).toBe(1)

		// Re-export the imported board and compare the normalized shapes.
		const reexport = await kanso('GET', `/boards/${importedBoardId}/export`)
		expect(normalize(reexport)).toEqual(normalize(original))

		// Fresh ids everywhere (the imported board owns brand-new stacks/cards).
		const srcCardIds = new Set(original.board.cards.map((c) => c.id))
		for (const c of reexport.board.cards) {
			expect(srcCardIds.has(c.id)).toBe(false)
		}

		// Rejects a bogus / future-version document.
		const bad = await fetch(KAN + '/boards/import', {
			method: 'POST',
			headers: { ...HEADERS, Authorization: AUTH },
			body: JSON.stringify({ document: JSON.stringify({ kanso: 9999, board: { title: 'x' } }) }),
		})
		expect(bad.status).toBe(400)
	})
})
