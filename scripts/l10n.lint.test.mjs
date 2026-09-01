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
import { lintCatalogText, pluralFormFor, npluralsOf, scaffoldFromPot, mergeCatalog } from './l10n.mjs'

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

// ── init: per-language plural forms and slot counts ─────────────────────────
//
// The bug these cover: `init` used to stamp German's `nplurals=2` into every
// catalogue and always write exactly msgstr[0] + msgstr[1]. A Chinese
// catalogue then had one slot too many and a Russian one two too few, and
// correcting the header by hand made `l10n:lint` — a required CI step — fail
// on every plural entry in the file.

/** A miniature but structurally real POT: header, singular, plural. */
const POT = [
	'# Kanso translation template.',
	'msgid ""',
	'msgstr ""',
	'"Project-Id-Version: Kanso\\n"',
	'"Content-Type: text/plain; charset=UTF-8\\n"',
	'"Plural-Forms: nplurals=2; plural=(n != 1);\\n"',
	'',
	'#: src/a.vue:1',
	'msgid "Board settings"',
	'msgstr ""',
	'',
	'#: src/a.vue:5',
	'msgid "New string"',
	'msgstr ""',
	'',
	'#: src/b.vue:2',
	'msgid "%n card"',
	'msgid_plural "%n cards"',
	'msgstr[0] ""',
	'msgstr[1] ""',
	'',
].join('\n')

/** The ten languages the i18n sprint ships, and the rule each one needs. */
const EXPECTED_PLURAL_FORMS = {
	de: 'nplurals=2; plural=(n != 1);',
	es: 'nplurals=2; plural=(n != 1);',
	it: 'nplurals=2; plural=(n != 1);',
	nl: 'nplurals=2; plural=(n != 1);',
	tr: 'nplurals=2; plural=(n != 1);',
	fr: 'nplurals=2; plural=(n > 1);',
	pt_BR: 'nplurals=2; plural=(n > 1);',
	zh_CN: 'nplurals=1; plural=0;',
	pl: 'nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
	ru: 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
}

test('every shipped language maps to its own Plural-Forms rule', () => {
	for (const [lang, form] of Object.entries(EXPECTED_PLURAL_FORMS)) {
		assert.equal(pluralFormFor(lang), form, `wrong Plural-Forms for ${lang}`)
	}
	// Chinese has one form, Russian and Polish three — not everyone is German.
	assert.equal(npluralsOf(pluralFormFor('zh_CN')), 1)
	assert.equal(npluralsOf(pluralFormFor('ru')), 3)
	assert.equal(npluralsOf(pluralFormFor('pl')), 3)
	assert.equal(npluralsOf(pluralFormFor('fr')), 2)
})

test('an unknown language code keeps the 2-form default instead of erroring', () => {
	assert.equal(pluralFormFor('xx'), 'nplurals=2; plural=(n != 1);')
	const text = scaffoldFromPot(POT, 'xx')
	assert.match(text, /"Language: xx\\n"/)
	assert.equal(lintCatalogText('xx', text).ok, true)
})

test('init writes exactly as many msgstr[n] slots as the language declares', () => {
	for (const [lang, form] of Object.entries(EXPECTED_PLURAL_FORMS)) {
		const text = scaffoldFromPot(POT, lang)
		assert.ok(text.includes(`"Plural-Forms: ${form}\\n"`), `${lang}: header not stamped`)
		assert.ok(text.includes(`"Language: ${lang}\\n"`), `${lang}: Language line missing`)
		const slots = [...text.matchAll(/^msgstr\[(\d+)\] /gm)].map((m) => Number(m[1]))
		const expected = [...Array(npluralsOf(form)).keys()]
		assert.deepEqual(slots, expected, `${lang}: wrong plural slots`)
		// The whole point: a freshly scaffolded catalogue must pass CI's own lint.
		const { ok, problems } = lintCatalogText(lang, text)
		assert.equal(ok, true, `${lang}: ${JSON.stringify(problems)}`)
	}
})

// ── sync: merging the POT into an existing catalogue, without gettext ────────

/** An existing catalogue: stale refs, one obsolete entry, real translations. */
function existingPo(pluralForms = 'nplurals=2; plural=(n != 1);', slots = ['msgstr[0] "%n Karte"', 'msgstr[1] "%n Karten"']) {
	return [
		'# Kanso translation template.',
		'msgid ""',
		'msgstr ""',
		'"Language: de\\n"',
		'"Project-Id-Version: Kanso\\n"',
		'"Content-Type: text/plain; charset=UTF-8\\n"',
		`"Plural-Forms: ${pluralForms}\\n"`,
		'',
		'#: src/old.vue:9',
		'msgid "Board settings"',
		'msgstr "Er sagte \\"hallo\\"\\nund ging"',
		'',
		'#: src/gone.vue:3',
		'msgid "Removed string"',
		'msgstr "Entfernt"',
		'',
		'#: src/old.vue:4',
		'msgid "%n card"',
		'msgid_plural "%n cards"',
		...slots,
		'',
	].join('\n')
}

test('sync adds new strings empty, keeps existing translations, drops obsolete ones', () => {
	const { text, stats } = mergeCatalog(POT, existingPo())
	assert.equal(stats.total, 3)
	assert.equal(stats.added, 1)
	assert.equal(stats.removed, 1)
	assert.equal(stats.translated, 2)
	// added, with an empty msgstr so it falls back to English until translated
	assert.ok(text.includes('msgid "New string"\nmsgstr ""'))
	// preserved — byte-for-byte, escapes and all
	assert.ok(text.includes('msgstr "Er sagte \\"hallo\\"\\nund ging"'), text)
	assert.ok(text.includes('msgstr[0] "%n Karte"'))
	// removed
	assert.ok(!text.includes('Removed string'))
	assert.ok(!text.includes('Entfernt'))
	assert.equal(lintCatalogText('de', text).ok, true)
})

test('sync refreshes #: source references from the template', () => {
	const { text } = mergeCatalog(POT, existingPo())
	assert.ok(text.includes('#: src/a.vue:1\nmsgid "Board settings"'), text)
	assert.ok(!text.includes('src/old.vue'), 'stale source references survived the merge')
})

test('sync leaves the Plural-Forms header alone and re-slots to what it declares', () => {
	// A 1-form catalogue (Chinese-style) whose entries still carry a stale
	// msgstr[1]: sync must drop the extra slot, not "fix" the header.
	const { text } = mergeCatalog(POT, existingPo('nplurals=1; plural=0;'))
	assert.ok(text.includes('"Plural-Forms: nplurals=1; plural=0;\\n"'), 'header was rewritten')
	assert.ok(text.includes('msgstr[0] "%n Karte"'))
	assert.ok(!text.includes('msgstr[1]'), 'extra plural slot survived')
	assert.equal(lintCatalogText('zh_CN', text).ok, true)

	// A 3-form catalogue (Russian/Polish-style): the two existing forms are
	// kept and the third is added empty.
	const three = mergeCatalog(POT, existingPo('nplurals=3; plural=(n != 1);')).text
	assert.ok(three.includes('msgstr[0] "%n Karte"'))
	assert.ok(three.includes('msgstr[1] "%n Karten"'))
	assert.ok(three.includes('msgstr[2] ""'), 'third plural form not scaffolded')
	assert.equal(lintCatalogText('ru', three).ok, true)
})
