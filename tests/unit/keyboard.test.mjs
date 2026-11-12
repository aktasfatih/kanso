// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import test from 'node:test'
import assert from 'node:assert/strict'
import { submitOnEnter } from '../../src/utils/keyboard.js'

test('IME candidate confirmation neither submits nor prevents composition', () => {
	const event = { isComposing: true, preventDefault() { assert.fail('composition must keep its default action') } }
	submitOnEnter(event, () => assert.fail('candidate confirmation must not submit'))
})

test('ordinary Enter prevents the default action and submits once with the event', () => {
	let prevented = 0
	let submitted = 0
	const event = { isComposing: false, preventDefault() { prevented++ } }
	const result = submitOnEnter(event, (received) => {
		assert.equal(prevented, 1)
		assert.equal(received, event)
		submitted++
		return 'saved'
	})
	assert.equal(submitted, 1)
	assert.equal(result, 'saved')
})

test('Android Enter with keyCode 229 submits when composition is inactive', () => {
	let submitted = false
	submitOnEnter({ isComposing: false, keyCode: 229, preventDefault() {} }, () => { submitted = true })
	assert.equal(submitted, true)
})
