// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Seed script: creates a 'Perf Test Board' with N cards spread across S stacks.
//
// PARAMETERIZED (env vars, all optional):
//   CARDS=<total cards>   default 2001  — total cards across all stacks
//   STACKS=<stack count>  default 3     — number of stacks to spread cards over
//   TITLE=<board title>   default 'Perf Test Board'
//
// Cards within a single stack MUST be created sequentially: two concurrent
// appends to the same stack derive the same fractional sort key and the loser
// gets a retryable 409 (rebalance_required). We instead parallelize ACROSS
// stacks (safe — different key spaces) and go sequentially within each, so more
// stacks = faster. For a very large board prefer more stacks (e.g. STACKS=20).
//
// Examples:
//   node scripts/seed-board.mjs                 # 2001 cards / 3 stacks (default)
//   CARDS=5000 STACKS=10 node scripts/seed-board.mjs
//   CARDS=10000 STACKS=20 node scripts/seed-board.mjs   # very-large board
//
// The perf spec (tests/e2e/perf.spec.js) reads the SAME board by title, so seed
// at the size you want to measure, then run:
//   npx playwright test --config playwright.perf.config.js
//
// Requires the dev stack running at http://localhost:8891.

const BASE = process.env.BASE || 'http://localhost:8891'
const USER = process.env.NC_USER || 'admin'
const PASS = process.env.NC_PASS || 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')
const HEADERS = {
	'OCS-APIREQUEST': 'true',
	'Content-Type': 'application/json',
	Authorization: AUTH,
}

const BOARD_TITLE = process.env.TITLE || 'Perf Test Board'
const TOTAL_CARDS = Number(process.env.CARDS || 2001)
const STACK_COUNT = Number(process.env.STACKS || 3)

if (!Number.isFinite(TOTAL_CARDS) || TOTAL_CARDS < 1) {
	throw new Error(`Invalid CARDS=${process.env.CARDS}`)
}
if (!Number.isFinite(STACK_COUNT) || STACK_COUNT < 1) {
	throw new Error(`Invalid STACKS=${process.env.STACKS}`)
}

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

// Distribute TOTAL_CARDS as evenly as possible across STACK_COUNT stacks.
function cardsPerStack() {
	const base = Math.floor(TOTAL_CARDS / STACK_COUNT)
	const remainder = TOTAL_CARDS % STACK_COUNT
	return Array.from({ length: STACK_COUNT }, (_, i) => base + (i < remainder ? 1 : 0))
}

async function main() {
	const t0 = Date.now()
	console.log(
		`Seeding '${BOARD_TITLE}': ${TOTAL_CARDS} cards across ${STACK_COUNT} stack(s).`,
	)

	console.log('Cleaning up existing board(s) with this title…')
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

	const perStack = cardsPerStack()
	const stackIds = []
	for (let s = 0; s < STACK_COUNT; s++) {
		const title = `Stack ${String.fromCharCode(65 + (s % 26))}${s >= 26 ? Math.floor(s / 26) : ''}`
		const stack = await apiPost('/stacks', { boardId: board.id, title })
		stackIds.push(stack.id)
	}
	console.log(`  ${stackIds.length} stack(s) created.`)

	// Cards within a stack must be sequential (see header note), but stacks are
	// independent — fill all stacks concurrently. `created` is a shared counter
	// updated as each POST resolves, so the progress line reflects real totals.
	let created = 0
	const fillStack = async (stackId, count) => {
		for (let i = 0; i < count; i++) {
			await apiPost('/cards', {
				stackId,
				title: `Card ${i + 1} — stack #${stackId}`,
			})
			created++
			if (created % 25 === 0 || created === TOTAL_CARDS) {
				process.stdout.write(`\r    ${created}/${TOTAL_CARDS} cards created…`)
			}
		}
	}
	await Promise.all(stackIds.map((stackId, s) => fillStack(stackId, perStack[s])))
	console.log()

	const secs = ((Date.now() - t0) / 1000).toFixed(1)
	console.log(`\nDone in ${secs}s. ${created} cards seeded.`)
	console.log(`Board URL: ${BASE}/index.php/apps/kanso#/board/${board.id}`)
}

main().catch((err) => {
	console.error(err)
	process.exit(1)
})
