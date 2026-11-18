// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import test from 'node:test'
import assert from 'node:assert/strict'
import { effectScope, ref } from 'vue'
import { useTimeStatsRange } from '../../src/composables/useTimeStatsRange.js'

function harness(t, instant, presetValue = 'today') {
	let now = Date.parse(instant)
	let nextId = 0
	const timers = new Map()
	const previousWindow = globalThis.window
	const previousDocument = globalThis.document
	const view = new EventTarget()
	const doc = new EventTarget()
	doc.visibilityState = 'visible'
	globalThis.window = view
	globalThis.document = doc
	t.mock.method(Date, 'now', () => now)
	t.mock.method(globalThis, 'setTimeout', (callback, delay) => {
		const id = ++nextId
		timers.set(id, { callback, at: now + delay })
		return id
	})
	t.mock.method(globalThis, 'clearTimeout', (id) => { timers.delete(id) })
	const preset = ref(presetValue)
	const scope = effectScope()
	const range = scope.run(() => useTimeStatsRange(preset))
	t.after(() => {
		scope.stop()
		globalThis.window = previousWindow
		globalThis.document = previousDocument
	})

	return {
		range,
		preset,
		scope,
		timers,
		view,
		doc,
		setClock: (instant) => { now = Date.parse(instant) },
		tickTo(instant) {
			const target = Date.parse(instant)
			for (;;) {
				const next = [...timers].sort((a, b) => a[1].at - b[1].at)[0]
				if (!next || next[1].at > target) break
				now = next[1].at
				timers.delete(next[0])
				next[1].callback()
			}
			now = target
		},
	}
}

test('the selected dates advance at UTC midnight and rearm only for the next midnight', (t) => {
	const h = harness(t, '2026-12-31T23:59:59.500Z')
	assert.deepEqual(h.range.value, { from: '2026-12-31', to: '2026-12-31' })
	assert.equal(h.timers.size, 1)
	assert.equal([...h.timers.values()][0].at, Date.parse('2027-01-01T00:00:00Z'))
	h.tickTo('2027-01-01T00:00:00Z')
	assert.deepEqual(h.range.value, { from: '2027-01-01', to: '2027-01-01' })
	assert.equal(h.timers.size, 1)
	assert.equal([...h.timers.values()][0].at, Date.parse('2027-01-02T00:00:00Z'))
	for (const [preset, from] of [['week', '2026-12-26'], ['month30', '2026-12-03'], ['month', '2027-01-01']]) {
		h.preset.value = preset
		assert.deepEqual(h.range.value, { from, to: '2027-01-01' })
	}
})

test('a calendar month range rolls over on the first day of the next month', (t) => {
	const h = harness(t, '2028-02-29T23:59:59Z', 'month')
	assert.deepEqual(h.range.value, { from: '2028-02-01', to: '2028-02-29' })
	h.tickTo('2028-03-01T00:00:00Z')
	assert.deepEqual(h.range.value, { from: '2028-03-01', to: '2028-03-01' })
})

test('restoring visibility and refocusing catch up after a suspended midnight timer', (t) => {
	const h = harness(t, '2026-11-18T23:00:00Z')
	h.doc.visibilityState = 'hidden'
	h.setClock('2026-11-20T09:00:00Z')
	h.doc.dispatchEvent(new Event('visibilitychange'))
	assert.equal(h.range.value.to, '2026-11-18')
	h.doc.visibilityState = 'visible'
	h.doc.dispatchEvent(new Event('visibilitychange'))
	assert.deepEqual(h.range.value, { from: '2026-11-20', to: '2026-11-20' })
	assert.equal(h.timers.size, 1)
	h.setClock('2026-11-21T09:00:00Z')
	h.view.dispatchEvent(new Event('focus'))
	assert.deepEqual(h.range.value, { from: '2026-11-21', to: '2026-11-21' })
	assert.equal(h.timers.size, 1)
})

test('changing the preset also reads the current UTC day after a clock jump', (t) => {
	const h = harness(t, '2026-11-18T22:00:00Z')
	h.setClock('2026-11-19T01:00:00Z')
	h.preset.value = 'week'
	assert.deepEqual(h.range.value, { from: '2026-11-13', to: '2026-11-19' })
})

test('scope disposal removes the timer and both event subscriptions', (t) => {
	const h = harness(t, '2026-11-18T23:00:00Z')
	const oldCallback = [...h.timers.values()][0].callback
	h.scope.stop()
	assert.equal(h.timers.size, 0)
	h.setClock('2026-11-20T09:00:00Z')
	h.view.dispatchEvent(new Event('focus'))
	h.doc.dispatchEvent(new Event('visibilitychange'))
	oldCallback()
	assert.equal(h.timers.size, 0)
	assert.equal(h.range.value.to, '2026-11-18')
})
