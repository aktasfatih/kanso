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

test('reports each violation in a file separately', () => {
	const src = [
		'await expect(a).toBeVisible({ timeout: 5000 })',
		'await expect(b).toBeVisible({ timeout: 6_000 })',
		'await expect(c).toBeVisible()',
		'',
	].join('\n')
	assert.deepEqual(scan(src).map((v) => v.line), [1, 2])
})
