#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Guard: no e2e wait may give itself LESS time than the suite-wide budget.
 *
 * playwright.config.js sets `expect: { timeout: 15_000 }` deliberately — the
 * self-hosted runner pool is shared, and a job that takes ~0.3s per round-trip
 * on an idle box measurably degrades 1.8-3x when the pool is saturated. An
 * explicit `{ timeout: 5000 }` on a POSITIVE wait silently opts back out of
 * that headroom, and that is what makes the suite load-sensitive: three runs of
 * the same commit tripped seven different specs, no spec twice.
 *
 * So: a positive wait (one that waits for something to APPEAR or to become
 * true) must not carry an explicit sub-15s budget. Deleting the option is the
 * fix — the global then applies.
 *
 * `expect.poll(fn, { timeout })` and `toPass({ timeout })` are held to the same
 * rule. Unlike a matcher, neither has a "negative" mode that spends its budget:
 * both re-run until the assertion passes, so `expect.poll(...).not.toBe(x)`
 * returns the instant the value stops being x. Every poll budget is therefore a
 * positive one, `.not` or no `.not`.
 *
 * And the mirror-image mistake, which the first sweep of this rule made: a wait
 * on something with a FINITE LIFETIME must not outlive it. An @nextcloud/dialogs
 * toast dismisses itself (TOAST_UNDO_TIMEOUT = 10s, TOAST_DEFAULT_TIMEOUT = 7s),
 * so a 15s wait on one can be a wait nothing could ever satisfy: once the toast
 * has expired, "not visible" is reported for something that really did appear.
 * A positive wait whose subject comes from the suite's own `toast()` helper must
 * therefore state a budget, and one below the 10s longest toast life.
 *
 * What this does NOT flag, on purpose:
 *   - negative waits (`not.toBeVisible`, `toHaveCount(0)`, `state: 'hidden'`):
 *     there a short budget is load-bearing, because the assertion only passes
 *     by SPENDING it. Lengthening those just makes the suite slower.
 *   - `waitForTimeout` (an unconditional sleep, not a wait on a condition).
 *   - budgets of 15s or more — those are at or above the global.
 *   - matchers outside POSITIVE_WAITS (`toHaveClass`, `toContainText`,
 *     `toHaveURL`, `toBeInViewport`, …). ~130 of those still carry hand-rolled
 *     sub-15s budgets; sweeping them is its own change, not this guard's.
 *
 * Escape hatches, each on the wait's own line, on any line it spans, or on the
 * line above it:
 *   // short-budget-ok: <reason>   a deliberately short positive budget
 *   // long-budget-ok: <reason>    a toast wait that must outlive the toast,
 *                                  because the action it waits through (a
 *                                  100-card bulk write) is the slow part
 *
 * Usage: node scripts/check-e2e-timeouts.mjs
 * Exits 1 and prints file:line for every un-annotated violation.
 *
 * `scanSource()` is exported so scripts/check-e2e-timeouts.test.mjs can prove
 * this guard is not vacuous — that it really fails on a reintroduced short
 * budget, and really stays quiet on the exempt shapes.
 */

import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { pathToFileURL } from 'node:url'

const E2E_DIR = 'tests/e2e'
const GLOBAL_BUDGET = 15_000
const ESCAPE = 'short-budget-ok:'
const LONG_ESCAPE = 'long-budget-ok:'

// The longest life @nextcloud/dialogs gives a toast: TOAST_UNDO_TIMEOUT (a plain
// toast gets TOAST_DEFAULT_TIMEOUT = 7s). Both are in
// node_modules/@nextcloud/dialogs/dist/toast.d.ts. The guard uses the LONGER of
// the two, because it cannot tell an undo toast from a plain one — under 10s is
// the part that is true of every toast, and the 7s nuance is the author's job.
const TOAST_LIFETIME = 10_000

// Waits that succeed when something APPEARS / becomes true. A short budget here
// only ever costs the test its headroom on a slow runner.
const POSITIVE_WAITS = [
	'toBeVisible',
	'toHaveText',
	'toHaveValue',
	'toHaveCount',
	'waitForSelector',
	'waitForResponse',
	'waitForFunction',
]

// Retry-until-it-passes helpers. Neither has a budget-spending negative form.
const POLL_WAITS = ['poll', 'toPass']

const CALL_RE = new RegExp(`\\.(${POSITIVE_WAITS.join('|')})\\s*\\(`, 'g')
const POLL_RE = new RegExp(`\\.(${POLL_WAITS.join('|')})\\s*\\(`, 'g')
const TIMEOUT_RE = /\btimeout\s*:\s*([0-9_]+)/g
const HIDDEN_RE = /\bstate\s*:\s*['"`](hidden|detached)['"`]/
// The suite's own toast locator helper (tests/e2e/helpers.js). It returns
// exactly one thing — an @nextcloud/dialogs toast — which is what makes keying
// the lifetime rule on it precise rather than a guess about locator shapes.
const TOAST_CALL_RE = /\btoast\s*\(/
const DECL_RE = /\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=([^\n]*)/g

/**
 * Blank out string literals and comments so paren-matching and the
 * `timeout:` scan can't be fooled by text inside them. Offsets are preserved
 * one-for-one, so any index into the mask is also an index into the source.
 *
 * @param {string} src file contents
 * @return {string} same-length copy with literals/comments replaced by spaces
 */
function mask(src) {
	const out = src.split('')
	let i = 0
	const blank = (from, to) => {
		for (let k = from; k < to && k < out.length; k++) {
			if (out[k] !== '\n') { out[k] = ' ' }
		}
	}
	while (i < src.length) {
		const c = src[i]
		if (c === '/' && src[i + 1] === '/') {
			let j = i
			while (j < src.length && src[j] !== '\n') { j++ }
			blank(i, j)
			i = j
			continue
		}
		if (c === '/' && src[i + 1] === '*') {
			const end = src.indexOf('*/', i + 2)
			const j = end === -1 ? src.length : end + 2
			blank(i, j)
			i = j
			continue
		}
		if (c === '\'' || c === '"' || c === '`') {
			let j = i + 1
			while (j < src.length) {
				if (src[j] === '\\') { j += 2; continue }
				if (src[j] === c) { j++; break }
				// An unterminated single/double quote would swallow the rest of
				// the file; a newline ends it (template literals may span lines).
				if (c !== '`' && src[j] === '\n') { break }
				j++
			}
			blank(i, j)
			i = j
			continue
		}
		i++
	}
	return out.join('')
}

/**
 * Index of the closing paren matching the open paren at `open`, or -1.
 *
 * @param {string} masked masked source
 * @param {number} open index of the '('
 * @return {number} index of the matching ')'
 */
function matchParen(masked, open) {
	let depth = 0
	for (let i = open; i < masked.length; i++) {
		if (masked[i] === '(') { depth++ } else if (masked[i] === ')') {
			depth--
			if (depth === 0) { return i }
		}
	}
	return -1
}

/**
 * True when the chain immediately before `at` is a `.not.` negation.
 *
 * @param {string} masked masked source
 * @param {number} at index of the '.' introducing the matcher
 * @return {boolean} whether the matcher is negated
 */
function isNegated(masked, at) {
	let i = at - 1
	while (i >= 0 && /\s/.test(masked[i])) { i-- }
	return masked.slice(Math.max(0, i - 3), i + 1) === '.not'
}

/**
 * Every identifier in a file that is bound to a toast locator — directly
 * (`const t = toast(page, 'x')`) or derived from one
 * (`const btn = t.getByRole(...)`). Iterated to a fixed point so a chain of
 * derivations is covered too.
 *
 * @param {string} masked masked source
 * @return {Set<string>} identifier names that denote a toast (or part of one)
 */
function toastIdentifiers(masked) {
	const idents = new Set()
	for (;;) {
		const before = idents.size
		DECL_RE.lastIndex = 0
		let decl
		while ((decl = DECL_RE.exec(masked)) !== null) {
			const [, name, rhs] = decl
			const derived = [...idents].some((id) => new RegExp(`\\b${id}\\b`).test(rhs))
			if (TOAST_CALL_RE.test(rhs) || derived) { idents.add(name) }
		}
		if (idents.size === before) { return idents }
	}
}

/**
 * The expression a matcher is asserted against, when it is spelled
 * `expect(<subject>)` — the shape the lifetime rule keys on.
 *
 * The matcher must actually hang off that `expect()`: its paren has to close
 * BEFORE the matcher's dot, with nothing but an optional `.not` chain in
 * between. Merely being the nearest `expect(` behind the cursor is not enough —
 * `page.waitForSelector` / `waitForResponse` / `waitForFunction` carry no
 * `expect()` of their own, so any one of them written after a toast assertion
 * would otherwise inherit that assertion's subject and be failed by the
 * toast-lifetime rule. A guard that reddens CI on an innocent line is worse
 * than the documented rule it enforces, so an unanchored wait gets no subject.
 *
 * @param {string} masked masked source
 * @param {number} dotAt index of the '.' introducing the matcher
 * @return {string|null} the subject text, or null when there is no expect() anchor
 */
function expectSubject(masked, dotAt) {
	const from = Math.max(0, dotAt - 400)
	const at = masked.slice(from, dotAt).lastIndexOf('expect(')
	if (at === -1) { return null }
	const openAt = from + at + 'expect('.length - 1
	const closeAt = matchParen(masked, openAt)
	if (closeAt === -1 || closeAt >= dotAt) { return null }
	if (!/^\s*(?:\.not\s*)?$/.test(masked.slice(closeAt + 1, dotAt))) { return null }
	return masked.slice(openAt + 1, closeAt)
}

/** @param {string} dir @return {string[]} every .js file under dir, recursively */
function jsFiles(dir) {
	const found = []
	for (const entry of readdirSync(dir, { withFileTypes: true })) {
		const path = join(dir, entry.name)
		if (entry.isDirectory()) { found.push(...jsFiles(path)) } else if (entry.name.endsWith('.js')) { found.push(path) }
	}
	return found.sort()
}

/**
 * Every un-annotated wait in one file's source whose budget is wrong: too short
 * for the suite's global (`kind: 'short'` / `'poll'`), or longer than the life
 * of the toast it waits on (`kind: 'toast'`).
 *
 * @param {string} file path used in the report
 * @param {string} src file contents
 * @return {{file: string, line: number, matcher: string, value: number|null, kind: string}[]} violations
 */
export function scanSource(file, src) {
	const found = []
	const masked = mask(src)
	const lineOf = (offset) => src.slice(0, offset).split('\n').length
	const lines = src.split('\n')
	const toastIdents = toastIdentifiers(masked)

	/**
	 * The comment context a wait's annotations may live in: the line above it
	 * through its last line.
	 *
	 * @param {number} from offset where the wait starts
	 * @param {number} to offset where the wait ends
	 * @return {string} the source of those lines
	 */
	const contextOf = (from, to) => lines.slice(Math.max(0, lineOf(from) - 2), lineOf(to)).join('\n')

	/**
	 * Whether `subject` denotes an @nextcloud/dialogs toast.
	 *
	 * @param {string|null} subject the expect() subject text, if any
	 * @return {boolean} true when it is a toast locator
	 */
	const isToast = (subject) => subject !== null
		&& (TOAST_CALL_RE.test(subject) || [...toastIdents].some((id) => new RegExp(`\\b${id}\\b`).test(subject)))

	CALL_RE.lastIndex = 0
	let call
	while ((call = CALL_RE.exec(masked)) !== null) {
		const matcher = call[1]
		const dotAt = call.index
		const openAt = call.index + call[0].length - 1
		const closeAt = matchParen(masked, openAt)
		if (closeAt === -1) { continue }

		const argsMasked = masked.slice(openAt + 1, closeAt)
		const argsRaw = src.slice(openAt + 1, closeAt)

		// Negative waits keep their short budget: they pass by spending it. They
		// also have no lifetime problem — asserting a toast is ABSENT is fine at
		// any budget.
		let negative = isNegated(masked, dotAt) || HIDDEN_RE.test(argsRaw)
		if (matcher === 'toHaveCount' && argsMasked.split(',')[0].trim() === '0') { negative = true }

		const budgets = []
		TIMEOUT_RE.lastIndex = 0
		let t
		while ((t = TIMEOUT_RE.exec(argsMasked)) !== null) {
			budgets.push({ value: Number(t[1].replaceAll('_', '')), at: openAt + 1 + t.index })
		}

		const context = contextOf(dotAt, closeAt)

		// Rule 1 — no positive wait may budget itself under the 15s global.
		if (!negative && !context.includes(ESCAPE)) {
			for (const b of budgets) {
				if (b.value >= GLOBAL_BUDGET) { continue }
				found.push({ file, line: lineOf(b.at), matcher, value: b.value, kind: 'short' })
			}
		}

		// Rule 2 — a wait on something that dismisses ITSELF must not outlive it.
		if (!negative && !context.includes(LONG_ESCAPE) && isToast(expectSubject(masked, dotAt))) {
			const longest = budgets.length === 0 ? null : Math.max(...budgets.map((b) => b.value))
			if (longest === null || longest >= TOAST_LIFETIME) {
				found.push({ file, line: lineOf(dotAt), matcher, value: longest, kind: 'toast' })
			}
		}
	}

	// Rule 3 — expect.poll / toPass, which retry until they pass and so have no
	// budget-spending negative form at all.
	POLL_RE.lastIndex = 0
	let poll
	while ((poll = POLL_RE.exec(masked)) !== null) {
		const matcher = poll[1] === 'poll' ? 'expect.poll' : 'toPass'
		const openAt = poll.index + poll[0].length - 1
		const closeAt = matchParen(masked, openAt)
		if (closeAt === -1) { continue }

		const context = contextOf(poll.index, closeAt)
		if (context.includes(ESCAPE)) { continue }

		TIMEOUT_RE.lastIndex = 0
		let t
		const argsMasked = masked.slice(openAt + 1, closeAt)
		while ((t = TIMEOUT_RE.exec(argsMasked)) !== null) {
			const value = Number(t[1].replaceAll('_', ''))
			if (value >= GLOBAL_BUDGET) { continue }
			found.push({ file, line: lineOf(openAt + 1 + t.index), matcher, value, kind: 'poll' })
		}
	}

	return found.sort((a, b) => a.line - b.line)
}

/** Scan tests/e2e and exit non-zero on any violation. @return {void} */
function main() {
	const violations = []
	for (const file of jsFiles(E2E_DIR)) {
		violations.push(...scanSource(file, readFileSync(file, 'utf8')))
	}

	if (violations.length === 0) {
		process.stdout.write(
			`e2e timeout guard: OK — no wait budgets itself under ${GLOBAL_BUDGET}ms, `
			+ 'and no toast wait outlives the toast.\n',
		)
		return
	}

	const tooShort = violations.filter((v) => v.kind !== 'toast')
	const tooLong = violations.filter((v) => v.kind === 'toast')

	/**
	 * Print one group of violations, file by file.
	 *
	 * @param {object[]} list the violations to print
	 * @return {void}
	 */
	const list = (group) => {
		for (const v of group) {
			const budget = v.value === null ? 'no explicit budget' : `{ timeout: ${v.value} }`
			process.stderr.write(`  ${v.file}:${v.line}  ${v.matcher}(${budget})\n`)
		}
	}

	if (tooShort.length > 0) {
		const files = new Set(tooShort.map((v) => v.file)).size
		process.stderr.write(
			`e2e timeout guard: ${tooShort.length} wait(s) in ${files} file(s) budget themselves under ${GLOBAL_BUDGET}ms.\n\n`
			+ 'playwright.config.js already gives every assertion 15s so the suite survives a\n'
			+ 'saturated runner pool. An explicit shorter budget opts back out of that and is\n'
			+ 'how the same commit trips a different spec on every run. `expect.poll` and\n'
			+ '`toPass` are covered too: both retry until they pass, so neither has a\n'
			+ 'negative form whose short budget is load-bearing.\n\n'
			+ 'Fix: delete the `{ timeout: N }` option — the 15s global then applies.\n'
			+ `If the short budget is deliberate, annotate the wait with \`// ${ESCAPE} <reason>\`.\n\n`,
		)
		list(tooShort)
		process.stderr.write('\n')
	}

	if (tooLong.length > 0) {
		const files = new Set(tooLong.map((v) => v.file)).size
		process.stderr.write(
			`e2e timeout guard: ${tooLong.length} wait(s) on a toast in ${files} file(s) can outlive it.\n\n`
			+ `An @nextcloud/dialogs toast dismisses itself — ${TOAST_LIFETIME}ms for an undo toast, 7000ms\n`
			+ 'for a plain one. A wait that budgets itself at or above that life can end up\n'
			+ 'reporting "not visible" for a toast that appeared and simply expired, which is\n'
			+ 'the wrong failure and one no budget could have satisfied.\n\n'
			+ `Fix: state a budget UNDER the toast's life (and annotate it \`// ${ESCAPE} <reason>\`,\n`
			+ 'since it is also under the 15s global).\n'
			+ 'If the wait has to cover a slow action that happens BEFORE the toast is raised\n'
			+ `(a 100-card bulk write, say), annotate it \`// ${LONG_ESCAPE} <reason>\` instead.\n\n`,
		)
		list(tooLong)
	}
	process.exitCode = 1
}

// Only the direct invocation scans and exits; the self-tests import scanSource.
if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) { main() }
