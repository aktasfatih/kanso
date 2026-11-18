// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { timeStatsRange } from '../../src/utils/timeStats.js'
import { formatDuration } from '../../src/utils/duration.js'

test('time presets use inclusive UTC dates across month and year boundaries', () => {
	const now = new Date('2027-01-02T20:30:00-08:00')
	assert.deepEqual(timeStatsRange('today', now), { from: '2027-01-03', to: '2027-01-03' })
	assert.deepEqual(timeStatsRange('week', now), { from: '2026-12-28', to: '2027-01-03' })
	assert.deepEqual(timeStatsRange('month30', now), { from: '2026-12-05', to: '2027-01-03' })
	assert.deepEqual(timeStatsRange('month', now), { from: '2027-01-01', to: '2027-01-03' })
})

test('last 30 days includes leap day without local daylight-saving arithmetic', () => {
	assert.deepEqual(timeStatsRange('month30', new Date('2028-03-15T12:00:00Z')), { from: '2028-02-15', to: '2028-03-15' })
})

test('time durations preserve seconds for short timers and compact long durations', () => {
	assert.equal(formatDuration(45), '45s')
	assert.equal(formatDuration(5400), '1h 30m')
	assert.equal(formatDuration(3605), '1h')
	assert.equal(formatDuration(0), '0m')
	assert.equal(formatDuration(-10), '0m')
})
