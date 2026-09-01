// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10056 — a counted noun must live in a PLURAL msgid, not a `t()` with the
// number substituted in.
//
// A msgid with no msgid_plural has exactly one form, so no translation of it
// can vary by count — and English itself can't either: `t('kanso', '{n}
// project', { n: 5 })` rendered "5 project" on every card that belonged to more
// than one project. Polish and Russian, which need three forms, had no way to
// express the difference at all.
//
// The check runs over the msgid map the extractor builds from the REAL source
// (the same scan `npm run l10n:extract` writes the POT from), not over the
// committed POT — so breaking the call site in CardDetail.vue turns this red
// immediately, without a re-extract in between. Rendering goes through the real
// @nextcloud/l10n plural function with no catalogue loaded, i.e. the English
// fallback every untranslated locale gets.

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { translatePlural } from '@nextcloud/l10n'
import { extractFrontend } from '../../scripts/l10n.mjs'

/** msgid map as the extractor sees `src/` right now. */
function frontendStrings() {
	const map = new Map()
	extractFrontend(map)
	return map
}

const strings = frontendStrings()

/** The extracted entry for a plural pair, or undefined. */
function pluralEntry(msgid, plural) {
	return strings.get(`${msgid} ${plural}`)
}

test("the card's project pill is a plural msgid", () => {
	const entry = pluralEntry('%n project', '%n projects')
	assert.ok(entry, 'src/ should call n(\'kanso\', \'%n project\', \'%n projects\', …)')
	assert.equal(entry.plural, '%n projects')
	assert.ok(
		[...entry.refs].some((ref) => ref.startsWith('src/components/CardDetail.vue')),
		`the project pill should be the caller, got refs: ${[...entry.refs].join(', ')}`,
	)
})

test('the project pill reads correctly at n=1 and n=5', () => {
	const entry = pluralEntry('%n project', '%n projects')
	assert.ok(entry, 'no plural entry to render')
	assert.equal(translatePlural('kanso', entry.msgid, entry.plural, 1), '1 project')
	assert.equal(translatePlural('kanso', entry.msgid, entry.plural, 5), '5 projects')
})

// Each of these used to interpolate a count into a single-form msgid (or, for
// the recurrence unit `<select>`, to name the unit in a form that could never
// agree with the number typed beside it). Re-introducing any of them puts the
// defect back, so the absence is asserted rather than left to review.
const SINGLE_FORM_COUNTED = [
	'{n} project',
	'Every {n} days',
	'Every {n} weeks',
	'Every {n} months',
	'Every {n} years',
	'· {n} times',
	'day(s)',
	'week(s)',
	'month(s)',
	'year(s)',
	'cards',
	'points',
]

for (const msgid of SINGLE_FORM_COUNTED) {
	test(`"${msgid}" is not a single-form msgid any more`, () => {
		assert.equal(
			strings.get(msgid),
			undefined,
			`"${msgid}" is back as a t() string — a counted noun needs n() with a msgid_plural`,
		)
	})
}

// The list view's caret expands sub-cards, not checklist items; it used to say
// "subtasks", which names a different feature.
test('the list view caret talks about sub-cards', () => {
	assert.equal(strings.get('Collapse subtasks'), undefined)
	assert.equal(strings.get('Expand subtasks'), undefined)
	assert.ok(strings.get('Collapse sub-cards'), 'expected a "Collapse sub-cards" label')
	assert.ok(strings.get('Expand sub-cards'), 'expected an "Expand sub-cards" label')
})
