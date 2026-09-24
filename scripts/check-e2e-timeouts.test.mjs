// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Fixture tests for the e2e wait-budget guard (scripts/check-e2e-timeouts.mjs).
 * Zero-dependency, using Node's built-in test runner:
 *
 *   node --test scripts/check-e2e-timeouts.test.mjs
 *
 * These exist so the guard's non-vacuity is a standing fact rather than a
 * one-off manual check: a guard that silently stops catching a reintroduced
 * short budget is worse than no guard, because the green CI run then reads as
 * proof. Every fixture is a real snippet shape taken from tests/e2e.
 */

import test from 'node:test'
import assert from 'node:assert/strict'
import { scanSource } from './check-e2e-timeouts.mjs'

/** @param {string} src @return {object[]} violations found in src */
const scan = (src) => [...scanSource('fixture.spec.js', src)]

test('flags a positive expect matcher that budgets under 15s', () => {
	const found = scan('await expect(tile).toBeVisible({ timeout: 5000 })\n')
	assert.equal(found.length, 1)
	assert.equal(found[0].matcher, 'toBeVisible')
	assert.equal(found[0].value, 5000)
	assert.equal(found[0].line, 1)
})

test('flags the underscore form too (10_000 is still under the budget)', () => {
	assert.equal(scan('await page.waitForSelector(\'.card-modal\', { timeout: 10_000 })\n').length, 1)
})

test('flags a wait whose option sits on a later line', () => {
	const src = [
		'await expect(counter).toHaveText(\'200+\', {',
		'\ttimeout: 10_000,',
		'})',
		'',
	].join('\n')
	const found = scan(src)
	assert.equal(found.length, 1)
	assert.equal(found[0].line, 2)
})

test('flags every positive wait shape the suite uses', () => {
	for (const src of [
		'await expect(row).toHaveText(\'x\', { timeout: 8000 })\n',
		'await expect(input).toHaveValue(\'x\', { timeout: 8000 })\n',
		'await expect(rows).toHaveCount(2, { timeout: 8000 })\n',
		'await page.waitForSelector(\'.x\', { timeout: 8000 })\n',
		'await page.waitForResponse((r) => r.ok(), { timeout: 8000 })\n',
		'await page.waitForFunction(() => window.ready, null, { timeout: 8000 })\n',
	]) {
		assert.equal(scan(src).length, 1, src)
	}
})

test('accepts a budget at or above the 15s global', () => {
	assert.deepEqual(scan('await expect(tile).toBeVisible({ timeout: 15_000 })\n'), [])
	assert.deepEqual(scan('await expect(tile).toBeVisible({ timeout: 30_000 })\n'), [])
})

test('leaves negative waits alone — their short budget is load-bearing', () => {
	assert.deepEqual(scan('await expect(tile).not.toBeVisible({ timeout: 5000 })\n'), [])
	assert.deepEqual(scan('await expect(tiles).toHaveCount(0, { timeout: 5000 })\n'), [])
	assert.deepEqual(
		scan('await page.waitForSelector(\'.card-modal\', { state: \'hidden\', timeout: 5000 })\n'),
		[],
	)
})

test('ignores waitForTimeout, which is a sleep rather than a wait on a condition', () => {
	assert.deepEqual(scan('await page.waitForTimeout(500)\n'), [])
})

test('ignores a timeout inside a string or a comment', () => {
	assert.deepEqual(scan('const note = \'toBeVisible({ timeout: 5000 })\'\n'), [])
	assert.deepEqual(scan('// await expect(x).toBeVisible({ timeout: 5000 })\n'), [])
})

test('honours the short-budget-ok escape on the wait\'s own line', () => {
	assert.deepEqual(
		scan('await expect(tile).toBeVisible({ timeout: 5000 }) // short-budget-ok: fixture\n'),
		[],
	)
})

test('honours the short-budget-ok escape on the line above', () => {
	const src = [
		'// short-budget-ok: fixture reason',
		'await expect(tile).toBeVisible({ timeout: 5000 })',
		'',
	].join('\n')
	assert.deepEqual(scan(src), [])
})

test('an unrelated comment above does NOT suppress the finding', () => {
	const src = [
		'// Open the card modal.',
		'await expect(tile).toBeVisible({ timeout: 5000 })',
		'',
	].join('\n')
	assert.equal(scan(src).length, 1)
})

// ── expect.poll / toPass ──────────────────────────────────────────────────────
// Both retry until the assertion passes, so neither has the negative form whose
// short budget is load-bearing — every budget on them is a positive one.

test('flags an expect.poll that budgets under 15s', () => {
	const found = scan('await expect.poll(() => count(), { timeout: 8_000 }).toBe(3)\n')
	assert.equal(found.length, 1)
	assert.equal(found[0].matcher, 'expect.poll')
	assert.equal(found[0].value, 8000)
	assert.equal(found[0].kind, 'poll')
})

test('flags a toPass that budgets under 15s', () => {
	const found = scan('await expect(async () => { await check() }).toPass({ timeout: 10_000 })\n')
	assert.equal(found.length, 1)
	assert.equal(found[0].matcher, 'toPass')
	assert.equal(found[0].value, 10000)
})

test('flags an expect.poll whose option sits on its own line', () => {
	const src = [
		'await expect.poll(',
		'\tasync () => (await api.get(`/cards/${id}`)).stackId,',
		'\t{ timeout: 8_000 },',
		').toBe(target)',
		'',
	].join('\n')
	const found = scan(src)
	assert.equal(found.length, 1)
	assert.equal(found[0].line, 3)
})

test('a NEGATED poll is still flagged — poll has no budget-spending negative', () => {
	assert.equal(scan('await expect.poll(() => url(), { timeout: 8_000 }).not.toContain(\'x\')\n').length, 1)
})

test('accepts a poll at or above the 15s global, and one with no budget at all', () => {
	assert.deepEqual(scan('await expect.poll(() => n(), { timeout: 15_000 }).toBe(1)\n'), [])
	assert.deepEqual(scan('await expect.poll(() => n(), { timeout: 30_000 }).toBe(1)\n'), [])
	assert.deepEqual(scan('await expect.poll(() => n()).toBe(1)\n'), [])
})

test('leaves a poll option that is not a numeric literal alone', () => {
	assert.deepEqual(scan('await expect.poll(() => n(), { timeout: REFETCH + SLACK }).toBe(1)\n'), [])
	assert.deepEqual(scan('await expect.poll(() => n(), { message: \'timeout: 500\' }).toBe(1)\n'), [])
})

test('honours short-budget-ok on a poll', () => {
	assert.deepEqual(
		scan('await expect.poll(() => n(), { timeout: 2_000 }).toBe(1) // short-budget-ok: fixture\n'),
		[],
	)
})

// ── Finite lifetime: a wait must not outlive what it waits on ─────────────────

test('flags a toast wait with no budget — the 15s global outlives the toast', () => {
	const found = scan('await expect(toast(page, \'Card deleted\')).toBeVisible()\n')
	assert.equal(found.length, 1)
	assert.equal(found[0].kind, 'toast')
	assert.equal(found[0].value, null)
})

test('flags a toast wait budgeted at or above the 10s toast life', () => {
	assert.deepEqual(
		scan('await expect(toast(page, \'x\')).toBeVisible({ timeout: 30_000 })\n').map((v) => v.kind),
		['toast'],
	)
	// 10s is both over the toast's life and under the 15s global, so it breaks
	// both rules at once and is reported by each.
	assert.deepEqual(
		scan('await expect(toast(page, \'x\')).toBeVisible({ timeout: 10_000 })\n').map((v) => v.kind).sort(),
		['short', 'toast'],
	)
})

test('follows a toast through a variable, and through one derived from it', () => {
	const src = [
		'const undoToast = toast(page, \'Card deleted\')',
		'await expect(undoToast).toBeVisible()',
		'const undoBtn = undoToast.getByRole(\'button\', { name: \'Undo\' })',
		'await expect(undoBtn).toBeVisible()',
		'',
	].join('\n')
	assert.deepEqual(scan(src).map((v) => v.line), [2, 4])
})

test('accepts a toast wait budgeted under the toast life, once annotated', () => {
	const src = [
		'const undoToast = toast(page, \'Card deleted\')',
		'// short-budget-ok: the undo toast is gone at 10s',
		'await expect(undoToast).toBeVisible({ timeout: 8_000 })',
		'',
	].join('\n')
	assert.deepEqual(scan(src), [])
})

test('honours long-budget-ok when the slow part precedes the toast', () => {
	assert.deepEqual(
		scan('await expect(toast(page, \'x\')).toBeVisible({ timeout: 60_000 }) // long-budget-ok: fixture\n'),
		[],
	)
})

test('short-budget-ok does NOT excuse a toast wait that outlives the toast', () => {
	const found = scan('await expect(toast(page, \'x\')).toBeVisible({ timeout: 30_000 }) // short-budget-ok: fixture\n')
	assert.equal(found.length, 1)
	assert.equal(found[0].kind, 'toast')
})

test('a toast asserted ABSENT needs no lifetime budget', () => {
	assert.deepEqual(scan('await expect(toast(page, \'x\')).toHaveCount(0)\n'), [])
	assert.deepEqual(scan('await expect(toast(page, \'x\')).not.toBeVisible()\n'), [])
})

test('a non-toast wait on the line after a toast one is not dragged in', () => {
	const src = [
		'const undoToast = toast(page, \'x\')',
		'// short-budget-ok: fixture',
		'await expect(undoToast).toBeVisible({ timeout: 8_000 })',
		'await expect(page.locator(\'.card-tile\')).toHaveCount(3)',
		'',
	].join('\n')
	assert.deepEqual(scan(src), [])
})

// A wait with no `expect()` of its own — `page.waitForSelector`,
// `waitForResponse`, `waitForFunction` — must not inherit the subject of an
// earlier assertion. These two tests pin BOTH directions of that: the innocent
// line stays quiet, and the violation the lifetime rule exists for still fires.

test('a bare page.wait* after a toast assertion is not given the toast as its subject', () => {
	for (const tail of [
		'await page.waitForSelector(\'.card-modal\', { timeout: 15_000 })',
		'await page.waitForResponse((r) => r.ok(), { timeout: 15_000 })',
		'await page.waitForFunction(() => window.ready, null, { timeout: 30_000 })',
	]) {
		const src = [
			'const undoToast = toast(page, \'Card deleted\')',
			'// short-budget-ok: fixture',
			'await expect(undoToast).toBeVisible({ timeout: 8_000 })',
			tail,
			'',
		].join('\n')
		assert.deepEqual(scan(src), [], tail)
	}
})

test('...and the same holds for an inline toast() assertion right above', () => {
	const src = [
		'await expect(toast(page, \'Backup deleted\')).toBeVisible({ timeout: 6_000 }) // short-budget-ok: f',
		'await page.waitForSelector(\'#kanso-backup-file-rows\', { timeout: 15_000 })',
		'',
	].join('\n')
	assert.deepEqual(scan(src), [])
})

test('the genuine toast-outlives-its-life case is STILL reported', () => {
	// Same two lines as above, but the toast wait itself now outlives the toast.
	const src = [
		'const undoToast = toast(page, \'Card deleted\')',
		'await expect(undoToast).toBeVisible({ timeout: 30_000 })',
		'await page.waitForSelector(\'.card-modal\', { timeout: 15_000 })',
		'',
	].join('\n')
	const found = scan(src)
	assert.deepEqual(found.map((v) => [v.line, v.kind, v.matcher]), [[2, 'toast', 'toBeVisible']])
})

test('a multi-line expect still reaches its subject, and a negated one still does not', () => {
	assert.deepEqual(
		scan('await expect(toast(page, \'x\'))\n\t.toBeVisible({ timeout: 30_000 })\n').map((v) => v.kind),
		['toast'],
	)
	assert.deepEqual(scan('await expect(toast(page, \'x\'))\n\t.not.toBeVisible()\n'), [])
})

test('reports each violation in a file separately', () => {
	const src = [
		'await expect(a).toBeVisible({ timeout: 5000 })',
		'await expect(b).toBeVisible({ timeout: 6_000 })',
		'await expect(c).toBeVisible()',
		'',
	].join('\n')
	assert.deepEqual(scan(src).map((v) => v.line), [1, 2])
})
