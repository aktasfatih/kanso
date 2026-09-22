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
 * What this does NOT flag, on purpose:
 *   - negative waits (`not.toBeVisible`, `toHaveCount(0)`, `state: 'hidden'`):
 *     there a short budget is load-bearing, because the assertion only passes
 *     by SPENDING it. Lengthening those just makes the suite slower.
 *   - `waitForTimeout` (an unconditional sleep, not a wait on a condition).
 *   - budgets of 15s or more — those are at or above the global.
 *
 * Escape hatch for a deliberately short positive budget: put
 *   // short-budget-ok: <reason>
 * on the wait's own line, on any line it spans, or on the line above it.
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

const CALL_RE = new RegExp(`\\.(${POSITIVE_WAITS.join('|')})\\s*\\(`, 'g')
const TIMEOUT_RE = /\btimeout\s*:\s*([0-9_]+)/g
const HIDDEN_RE = /\bstate\s*:\s*['"`](hidden|detached)['"`]/

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
 * Every un-annotated sub-budget positive wait in one file's source.
 *
 * @param {string} file path used in the report
 * @param {string} src file contents
 * @return {{file: string, line: number, matcher: string, value: number}[]} violations
 */
export function scanSource(file, src) {
	const found = []
	const masked = mask(src)
	const lineOf = (offset) => src.slice(0, offset).split('\n').length
	const lines = src.split('\n')

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

		TIMEOUT_RE.lastIndex = 0
		let t
		while ((t = TIMEOUT_RE.exec(argsMasked)) !== null) {
			const value = Number(t[1].replaceAll('_', ''))
			if (value >= GLOBAL_BUDGET) { continue }

			// Negative waits keep their short budget: they pass by spending it.
			if (isNegated(masked, dotAt)) { continue }
			if (HIDDEN_RE.test(argsRaw)) { continue }
			if (matcher === 'toHaveCount') {
				const first = argsMasked.split(',')[0].trim()
				if (first === '0') { continue }
			}

			const startLine = lineOf(dotAt)
			const endLine = lineOf(closeAt)
			const context = lines.slice(Math.max(0, startLine - 2), endLine).join('\n')
			if (context.includes(ESCAPE)) { continue }

			found.push({ file, line: lineOf(openAt + 1 + t.index), matcher, value })
		}
	}
	return found
}

/** Scan tests/e2e and exit non-zero on any violation. @return {void} */
function main() {
	const violations = []
	for (const file of jsFiles(E2E_DIR)) {
		violations.push(...scanSource(file, readFileSync(file, 'utf8')))
	}

	if (violations.length === 0) {
		process.stdout.write(`e2e timeout guard: OK — no positive wait budgets itself under ${GLOBAL_BUDGET}ms.\n`)
		return
	}

	const byFile = new Map()
	for (const v of violations) {
		if (!byFile.has(v.file)) { byFile.set(v.file, []) }
		byFile.get(v.file).push(v)
	}

	process.stderr.write(
		`e2e timeout guard: ${violations.length} positive wait(s) in ${byFile.size} file(s) budget themselves under ${GLOBAL_BUDGET}ms.\n\n`
		+ 'playwright.config.js already gives every assertion 15s so the suite survives a\n'
		+ 'saturated runner pool. An explicit shorter budget opts back out of that and is\n'
		+ 'how the same commit trips a different spec on every run.\n\n'
		+ 'Fix: delete the `{ timeout: N }` option — the 15s global then applies.\n'
		+ `If the short budget is deliberate, annotate the wait with \`// ${ESCAPE} <reason>\`.\n\n`,
	)
	for (const [file, list] of byFile) {
		for (const v of list) {
			process.stderr.write(`  ${file}:${v.line}  ${v.matcher}({ timeout: ${v.value} })\n`)
		}
	}
	process.exitCode = 1
}

// Only the direct invocation scans and exits; the self-tests import scanSource.
if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) { main() }
