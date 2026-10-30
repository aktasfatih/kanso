// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { readFileSync } from 'node:fs'

function summarize(report) {
	if (!report || !Array.isArray(report.suites) || !Array.isArray(report.errors) || !report.stats) throw new Error('invalid report')
	const counts = { expected: 0, expectedFailures: 0, unexpected: 0, flaky: 0, skipped: 0, interrupted: 0, didNotRun: 0 }
	function visit(suite) {
		if (!suite || !Array.isArray(suite.specs) || (suite.suites !== undefined && !Array.isArray(suite.suites))) throw new Error('invalid suite')
		for (const spec of suite.specs) {
			if (!spec || !Array.isArray(spec.tests) || spec.tests.length === 0) throw new Error('invalid spec')
			for (const test of spec.tests) {
				if (!test || !['expected', 'unexpected', 'flaky', 'skipped'].includes(test.status)
					|| typeof test.expectedStatus !== 'string' || !Array.isArray(test.results)
					|| test.results.some((result) => !result || !['passed', 'failed', 'timedOut', 'skipped', 'interrupted'].includes(result.status))) throw new Error('invalid test')
				if (test.status !== 'skipped') {
					counts[test.status]++
					if (test.status === 'expected' && test.expectedStatus === 'failed') counts.expectedFailures++
				} else if (test.results.some((result) => result.status === 'interrupted')) {
					counts.interrupted++
				} else if (test.results.length === 0 || test.expectedStatus !== 'skipped') {
					counts.didNotRun++
				} else {
					counts.skipped++
				}
			}
		}
		for (const child of suite.suites ?? []) visit(child)
	}
	for (const suite of report.suites) visit(suite)
	const skipped = counts.skipped + counts.interrupted + counts.didNotRun
	if (counts.expected + counts.unexpected + counts.flaky + skipped === 0
		|| report.stats.expected !== counts.expected || report.stats.unexpected !== counts.unexpected
		|| report.stats.flaky !== counts.flaky || report.stats.skipped !== skipped) throw new Error('incomplete report')
	return { ...counts, errors: report.errors.length }
}

try {
	const counts = summarize(JSON.parse(readFileSync(process.argv[2], 'utf8')))
	const details = `${counts.expected - counts.expectedFailures} passed, ${counts.expectedFailures} expected failures, ${counts.unexpected} failed, ${counts.flaky} flaky, ${counts.skipped} skipped, ${counts.errors} global errors`
	if (counts.interrupted || counts.didNotRun) {
		console.log(`::warning::Playwright run incomplete: ${counts.interrupted} interrupted, ${counts.didNotRun} did not run; ${details}. The suite did not finish. Inspect any reported failures separately.`)
	} else {
		console.log(`Playwright execution summary: ${details}.`)
	}
} catch {
	console.log('::warning::Playwright execution status unknown: report missing, malformed or incomplete. The suite cannot be assessed from this report.')
}
