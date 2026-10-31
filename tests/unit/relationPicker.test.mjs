// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test'
import assert from 'node:assert/strict'
import { beginRelationPick, clearRelationPick, navigateRelationPick, relationPicker } from '../../src/services/relationPicker.js'

test('a rejected or failed navigation clears the original relation session', async () => {
	beginRelationPick({ sourceId: 1 })
	await assert.rejects(navigateRelationPick({ push: async () => { throw new Error('load failed') } }, {}), /load failed/)
	assert.equal(relationPicker.value, null)
	beginRelationPick({ sourceId: 1 })
	await navigateRelationPick({ push: async () => ({ type: 4 }) }, {})
	assert.equal(relationPicker.value, null)
})

test('a late navigation failure does not clear a newer relation session', async () => {
	beginRelationPick({ sourceId: 1 })
	let reject
	const pending = navigateRelationPick({ push: () => new Promise((resolve, fail) => { reject = fail }) }, {})
	beginRelationPick({ sourceId: 2 })
	reject(new Error('load failed'))
	await assert.rejects(pending, /load failed/)
	assert.equal(relationPicker.value.sourceId, 2)
	clearRelationPick()
})
