// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { spawnSync } from 'node:child_process'

const script = new URL('./summarize-e2e-report.mjs', import.meta.url).pathname
const entry = (status, expectedStatus, ...results) => ({ status, expectedStatus, results: results.map((result) => ({ status: result })) })

function run(input) {
	const dir = mkdtempSync(join(tmpdir(), 'kanso-summary-'))
	try {
		const file = join(dir, 'report.json')
		if (input !== undefined) writeFileSync(file, typeof input === 'string' ? input : JSON.stringify(input))
		return spawnSync(process.execPath, [script, file], { encoding: 'utf8' })
	} finally {
		rmSync(dir, { recursive: true, force: true })
	}
}

function report(tests, stats, errors = []) {
	return { suites: [{ specs: [], suites: [{ specs: [{ tests }] }] }], stats, errors }
}

test('distinguishes interrupted and never-run tests from deliberate skips while retaining failures', () => {
	const fixture = report([
		entry('expected', 'passed', 'passed'),
		entry('unexpected', 'passed', 'failed'),
		entry('skipped', 'skipped', 'skipped'),
		entry('skipped', 'passed', 'interrupted'),
		entry('skipped', 'passed'),
	], { expected: 1, unexpected: 1, flaky: 0, skipped: 3 })
	const result = run(fixture)
	assert.equal(result.status, 0)
	assert.match(result.stdout, /run incomplete: 1 interrupted, 1 did not run/)
	assert.match(result.stdout, /1 failed/)
	assert.match(result.stdout, /1 skipped/)
	assert.match(result.stdout, /Inspect any reported failures separately/)
})

test('completed, flaky and intentional skips are not misclassified as interruptions', () => {
	const fixture = report([
		entry('flaky', 'passed', 'interrupted', 'passed'),
		entry('skipped', 'skipped', 'skipped'),
	], { expected: 0, unexpected: 0, flaky: 1, skipped: 1 }, [{ message: 'setup error' }])
	const result = run(fixture)
	assert.equal(result.status, 0)
	assert.doesNotMatch(result.stdout, /run incomplete/)
	assert.match(result.stdout, /1 flaky, 1 skipped, 1 global errors/)
})

test('expected failures are not counted as passed tests', () => {
	const fixture = report([
		entry('expected', 'passed', 'passed'),
		entry('expected', 'failed', 'failed'),
	], { expected: 2, unexpected: 0, flaky: 0, skipped: 0 })
	assert.match(run(fixture).stdout, /1 passed, 1 expected failures, 0 failed/)
})

test('missing, invalid, empty or inconsistent reports are unknown', () => {
	for (const fixture of [undefined, '{"suites":', {}, report([], { expected: 0, unexpected: 0, flaky: 0, skipped: 0 }), report([entry('expected', 'passed', 'passed')], { expected: 2, unexpected: 0, flaky: 0, skipped: 0 })]) {
		const result = run(fixture)
		assert.equal(result.status, 0)
		assert.match(result.stdout, /execution status unknown/)
	}
})
