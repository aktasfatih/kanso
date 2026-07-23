// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Seed script: creates a 'Perf Test Board' with 3 stacks × ~667 cards (2 001 total).
// Usage: node scripts/seed-board.mjs
// Requires the dev stack running at http://localhost:8891.

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')
const HEADERS = {
	'OCS-APIREQUEST': 'true',
	'Content-Type': 'application/json',
	Authorization: AUTH,
}

const BOARD_TITLE = 'Perf Test Board'
const STACKS = ['Stack A', 'Stack B', 'Stack C']
const CARDS_PER_STACK = 667 // 3 × 667 = 2 001

async function apiGet(path) {
	const r = await fetch(API + path, { headers: HEADERS })
	if (!r.ok) throw new Error(`GET ${path} → ${r.status}: ${await r.text()}`)
	return r.json()
}

async function apiPost(path, body) {
	const r = await fetch(API + path, {
		method: 'POST',
		headers: HEADERS,
		body: JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`POST ${path} → ${r.status}: ${await r.text()}`)
	return r.json()
}

async function apiDelete(path) {
	const r = await fetch(API + path, { method: 'DELETE', headers: HEADERS })
	if (!r.ok) throw new Error(`DELETE ${path} → ${r.status}`)
}

async function main() {
	console.log('Cleaning up existing Perf Test Board(s)…')
	const boards = await apiGet('/boards')
	for (const b of boards) {
		if (b.title === BOARD_TITLE) {
			await apiDelete(`/boards/${b.id}`)
			console.log(`  Deleted board #${b.id}`)
		}
	}

	console.log(`Creating '${BOARD_TITLE}'…`)
	const board = await apiPost('/boards', { title: BOARD_TITLE, color: '0082c9' })
	console.log(`  Board #${board.id} created.`)

	const stackIds = []
	for (const title of STACKS) {
		const s = await apiPost('/stacks', { boardId: board.id, title })
		stackIds.push(s.id)
		console.log(`  Stack '${title}' #${s.id} created.`)
	}

	const total = stackIds.length * CARDS_PER_STACK
	let created = 0
	const BATCH = 20 // Create cards in parallel batches to stay under connection limits

	for (const stackId of stackIds) {
		console.log(`  Seeding ${CARDS_PER_STACK} cards into stack #${stackId}…`)
		for (let batch = 0; batch < CARDS_PER_STACK; batch += BATCH) {
			const end = Math.min(batch + BATCH, CARDS_PER_STACK)
			const promises = []
			for (let i = batch; i < end; i++) {
				promises.push(
					apiPost('/cards', {
						stackId,
						title: `Card ${i + 1} — stack #${stackId}`,
					}),
				)
			}
			await Promise.all(promises)
			created += end - batch
			process.stdout.write(`\r    ${created}/${total} cards created…`)
		}
		console.log()
	}

	console.log(`\nDone! Board URL: ${BASE}/index.php/apps/kanso#/board/${board.id}`)
}

main().catch((err) => {
	console.error(err)
	process.exit(1)
})
