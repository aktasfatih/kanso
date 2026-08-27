// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Fixture tests for `node scripts/l10n.mjs lint` (scripts/l10n.mjs — the
 * parsePoStrict / extractPlaceholders / diffMultisets / lintCatalogText
 * exports). Zero-dependency, using Node's built-in test runner:
 *
 *   node --test scripts/l10n.lint.test.mjs
 *
 * Each fixture is a minimal, syntactically real PO file (same header +
 * msgid/msgstr shape lint actually reads) so a false pass/fail here reflects
 * what would really happen against translationfiles/<lang>/kanso.po.
 */

import test from 'node:test'
import assert from 'node:assert/strict'
import { lintCatalogText } from './l10n.mjs'

const HEADER = [
	'msgid ""',
	'msgstr ""',
	'"Language: xx\\n"',
	'"Plural-Forms: nplurals=2; plural=(n != 1);\\n"',
	'',
].join('\n')

/** Build a minimal PO source: header + one entry block. */
function po(entryLines) {
	return `${HEADER}\n${entryLines.join('\n')}\n`
}

test('renamed placeholder ({count} -> {anzahl}) fails, naming language + msgid', () => {
	const text = po([
		'msgid "{count} selected"',
		'msgstr "{anzahl} ausgewählt"',
	])
	const { ok, problems } = lintCatalogText('xx', text)
	assert.equal(ok, false)
	assert.equal(problems.length, 1)
	assert.match(problems[0].message, /\[xx\]/)
	assert.match(problems[0].message, /\{count\} selected/)
	assert.match(problems[0].message, /missing \{count\}/)
	assert.match(problems[0].message, /unexpected \{anzahl\}/)
})

test('dropped %1$s fails', () => {
	const text = po([
		'msgid "%1$s created %2$s"',
		'msgstr "%2$s erstellt"',
	])
	const { ok, problems } = lintCatalogText('xx', text)
	assert.equal(ok, false)
	assert.equal(problems.length, 1)
	assert.match(problems[0].message, /missing %1\$s/)
})

test('wrong plural-form count (msgstr[2] with nplurals=2) fails', () => {
	const text = po([
		'msgid "%n card"',
		'msgid_plural "%n cards"',
		'msgstr[0] "%n Karte"',
		'msgstr[1] "%n Karten"',
		'msgstr[2] "%n Karten (extra)"',
	])
	const { ok, problems } = lintCatalogText('xx', text)
	assert.equal(ok, false)
	assert.ok(problems.some((p) => /msgstr\[2\] present but Plural-Forms declares nplurals=2/.test(p.message)))
})

test('a legitimately partial catalogue (empty msgstr) passes', () => {
	const text = po([
		'msgid "{count} selected"',
		'msgstr ""',
		'',
		'msgid "%n card"',
		'msgid_plural "%n cards"',
		'msgstr[0] ""',
		'msgstr[1] ""',
		'',
		'msgid "Board settings"',
		'msgstr "Board-Einstellungen"',
	])
	const { ok, problems } = lintCatalogText('xx', text)
	assert.equal(ok, true, JSON.stringify(problems))
})

test('malformed PO syntax (msgstr with no preceding msgid) is rejected', () => {
	const text = `${HEADER}\nmsgstr "orphan translation"\n`
	const { ok, problems } = lintCatalogText('xx', text)
	assert.equal(ok, false)
	assert.ok(problems.some((p) => /msgstr with no preceding msgid/.test(p.message)))
})

test('missing/malformed Plural-Forms header is rejected', () => {
	const text = [
		'msgid ""',
		'msgstr ""',
		'"Language: xx\\n"',
		'',
		'msgid "hello"',
		'msgstr "hallo"',
		'',
	].join('\n')
	const { ok, problems } = lintCatalogText('xx', text)
	assert.equal(ok, false)
	assert.ok(problems.some((p) => /Plural-Forms/.test(p.message)))
})

test('literal % and { in prose do not false-positive', () => {
	const text = po([
		'msgid "{p}% done"',
		'msgstr "{p}% fertig"',
	])
	const { ok, problems } = lintCatalogText('xx', text)
	assert.equal(ok, true, JSON.stringify(problems))
})

test('reordered placeholders are fine — identity and count matter, not order', () => {
	const text = po([
		'msgid "{actor} moved {object}"',
		'msgstr "{object} wurde von {actor} verschoben"',
	])
	const { ok, problems } = lintCatalogText('xx', text)
	assert.equal(ok, true, JSON.stringify(problems))
})

test('a plural entry whose singular and plural English forms share one %n only requires one %n per msgstr[n]', () => {
	// Regression: msgid and msgid_plural both contain "%n" once (as almost every
	// plural entry does). The expected set must not double-count it to 2 just
	// because it showed up in both source forms.
	const text = po([
		'msgid "%n card"',
		'msgid_plural "%n cards"',
		'msgstr[0] "%n Karte"',
		'msgstr[1] "%n Karten"',
	])
	const { ok, problems } = lintCatalogText('xx', text)
	assert.equal(ok, true, JSON.stringify(problems))
})

test('a repeated placeholder must appear the same number of times', () => {
	const text = po([
		'msgid "{name} {name}"',
		'msgstr "{name}"',
	])
	const { ok, problems } = lintCatalogText('xx', text)
	assert.equal(ok, false)
	assert.ok(problems.some((p) => /missing \{name\}/.test(p.message)))
})
