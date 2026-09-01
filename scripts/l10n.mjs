// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Kanso translation tool — zero-dependency gettext pipeline.
 *
 *   node scripts/l10n.mjs extract     # scan sources → translationfiles/templates/kanso.pot
 *   node scripts/l10n.mjs compile     # each translationfiles/<lang>/kanso.po → l10n/<lang>.{js,json}
 *   node scripts/l10n.mjs lint        # validate PO syntax + placeholder consistency (CI)
 *   node scripts/l10n.mjs init <lang> # scaffold translationfiles/<lang>/kanso.po from the POT
 *   node scripts/l10n.mjs sync [lang] # merge the POT into existing .po files (no gettext)
 *
 * Why a bespoke tool instead of the Nextcloud `translationtool.phar`: it needs
 * PHP + xgettext on the machine, whereas everything here already runs on Node
 * (the Vite toolchain). The output format is byte-for-byte what Nextcloud loads:
 * `l10n/<lang>.js` (OC.L10N.register, for the Vue frontend) and
 * `l10n/<lang>.json` (for the server-side IL10N used by templates/activity).
 *
 * Extraction covers:
 *   - Frontend (src/**\/*.{js,vue}):  t('kanso', '…')   n('kanso', 'one', 'many', …)
 *     (translate / translatePlural from @nextcloud/l10n are imported as t / n)
 *   - Backend  (lib/**, templates/**):  $l->t('…')   $l->n('one', 'many', …)
 *
 * Built-in enum labels (card types, priority levels, filter facets, …) are
 * wrapped at their definition — label: t('kanso', 'Bug') — and rendered
 * directly, so their English source is a literal the extractor sees here too.
 */

import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const POT = path.join(ROOT, 'translationfiles', 'templates', 'kanso.pot')
const PO_DIR = path.join(ROOT, 'translationfiles')
const L10N_DIR = path.join(ROOT, 'l10n')

const PLURAL_FORM = 'nplurals=2; plural=(n != 1);'

// ── plural forms per language ───────────────────────────────────────────────
//
// A fixed lookup table, deliberately: the alternative (parsing CLDR rules) is a
// library, and Kanso only ever scaffolds catalogues for the handful of
// languages it actually ships. The expressions are the standard gettext ones.
// A language that isn't listed falls back to PLURAL_FORM (the 2-form default a
// translator can then correct by hand) rather than erroring — `init xx` must
// keep working for a language nobody has added here yet.
const PLURAL_FORMS = {
	de: 'nplurals=2; plural=(n != 1);',
	es: 'nplurals=2; plural=(n != 1);',
	it: 'nplurals=2; plural=(n != 1);',
	nl: 'nplurals=2; plural=(n != 1);',
	tr: 'nplurals=2; plural=(n != 1);',
	// Romance languages count 0 as singular.
	fr: 'nplurals=2; plural=(n > 1);',
	pt_BR: 'nplurals=2; plural=(n > 1);',
	// Chinese has no grammatical plural at all — one form for every count.
	zh_CN: 'nplurals=1; plural=0;',
	// Slavic: one / few / many.
	pl: 'nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
	ru: 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
}

/** The Plural-Forms header for a Nextcloud language code. */
function pluralFormFor(lang) {
	return PLURAL_FORMS[lang] || PLURAL_FORM
}

/** How many msgstr[n] slots a Plural-Forms string declares (2 if unreadable). */
function npluralsOf(pluralForm) {
	const m = /nplurals\s*=\s*(\d+)/.exec(pluralForm || '')
	const n = m ? Number(m[1]) : 0
	return n > 0 ? n : 2
}

// ── file walking ────────────────────────────────────────────────────────────

/** Recursively list files under `dir` matching one of `exts`. */
function walk(dir, exts) {
	const abs = path.join(ROOT, dir)
	if (!fs.existsSync(abs)) return []
	return fs
		.readdirSync(abs, { recursive: true, withFileTypes: true })
		.filter((d) => d.isFile() && exts.some((e) => d.name.endsWith(e)))
		.map((d) => path.join(d.parentPath ?? d.path, d.name))
}

/** Turn a JS/PHP string literal body into its real value (common escapes only). */
function unescape(s) {
	return s.replace(/\\(['"\\ntr])/g, (_, c) =>
		({ n: '\n', t: '\t', r: '\r', "'": "'", '"': '"', '\\': '\\' }[c]))
}

// ── extraction ──────────────────────────────────────────────────────────────

// A quoted literal: '…' or "…" honouring backslash escapes.
const STR = String.raw`(['"])((?:\\.|(?!\1).)*)\1`

/**
 * @typedef {{ msgid: string, plural: string|null, refs: Set<string> }} Entry
 */

/** Record a msgid (and optional plural) with its source reference. */
function record(map, msgid, plural, ref) {
	if (!msgid) return
	const key = plural ? `${msgid} ${plural}` : msgid
	let e = map.get(key)
	if (!e) {
		e = { msgid, plural: plural || null, refs: new Set() }
		map.set(key, e)
	}
	e.refs.add(ref)
}

/** Byte offset → "path:line" (relative), 1-based line. */
function refAt(rel, text, index) {
	let line = 1
	for (let i = 0; i < index && i < text.length; i++) if (text[i] === '\n') line++
	return `${rel}:${line}`
}

function extractFrontend(map) {
	const single = new RegExp(String.raw`\b(?:t|translate)\(\s*'kanso'\s*,\s*${STR}`, 'g')
	const plural = new RegExp(String.raw`\b(?:n|translatePlural)\(\s*'kanso'\s*,\s*${STR}\s*,\s*${STR}`, 'g')
	for (const file of walk('src', ['.js', '.vue'])) {
		const rel = path.relative(ROOT, file)
		const text = fs.readFileSync(file, 'utf8')
		for (const m of text.matchAll(plural)) {
			record(map, unescape(m[2]), unescape(m[4]), refAt(rel, text, m.index))
		}
		// Strip plural calls so their first arg isn't also caught as a singular.
		const singleText = text.replace(plural, '')
		for (const m of singleText.matchAll(single)) {
			record(map, unescape(m[2]), null, `${rel}`)
		}
	}
}

function extractBackend(map) {
	const single = new RegExp(String.raw`->t\(\s*${STR}`, 'g')
	const plural = new RegExp(String.raw`->n\(\s*${STR}\s*,\s*${STR}`, 'g')
	for (const file of [...walk('lib', ['.php']), ...walk('templates', ['.php'])]) {
		const rel = path.relative(ROOT, file)
		const text = fs.readFileSync(file, 'utf8')
		for (const m of text.matchAll(plural)) {
			record(map, unescape(m[2]), unescape(m[4]), refAt(rel, text, m.index))
		}
		const singleText = text.replace(plural, '')
		for (const m of singleText.matchAll(single)) {
			record(map, unescape(m[2]), null, `${rel}`)
		}
	}
}

// ── PO / POT serialisation ──────────────────────────────────────────────────

/** Escape a JS/PHP string for a PO `msgid`/`msgstr` value. */
function poEscape(s) {
	return s
		.replace(/\\/g, '\\\\')
		.replace(/"/g, '\\"')
		.replace(/\n/g, '\\n')
		.replace(/\t/g, '\\t')
}

function potHeader() {
	return [
		'# Kanso translation template.',
		'# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>',
		'# SPDX-License-Identifier: AGPL-3.0-or-later',
		'msgid ""',
		'msgstr ""',
		'"Project-Id-Version: Kanso\\n"',
		'"Report-Msgid-Bugs-To: https://github.com/aktasfatih/kanso/issues\\n"',
		'"Content-Type: text/plain; charset=UTF-8\\n"',
		'"Content-Transfer-Encoding: 8bit\\n"',
		`"Plural-Forms: ${PLURAL_FORM}\\n"`,
		'',
	].join('\n')
}

function writePot(map) {
	const entries = [...map.values()].sort((a, b) => a.msgid.localeCompare(b.msgid))
	const blocks = entries.map((e) => {
		const lines = [...e.refs].sort().map((r) => `#: ${r}`)
		lines.push(`msgid "${poEscape(e.msgid)}"`)
		if (e.plural) {
			lines.push(`msgid_plural "${poEscape(e.plural)}"`)
			lines.push('msgstr[0] ""')
			lines.push('msgstr[1] ""')
		} else {
			lines.push('msgstr ""')
		}
		return lines.join('\n')
	})
	fs.mkdirSync(path.dirname(POT), { recursive: true })
	fs.writeFileSync(POT, potHeader() + '\n' + blocks.join('\n\n') + '\n')
	console.log(`Wrote ${path.relative(ROOT, POT)} — ${entries.length} strings`)
}

// ── PO parsing (for compile) ────────────────────────────────────────────────

/** Minimal PO parser → array of { msgid, msgid_plural, msgstr, msgstr[] }. */
function parsePo(text) {
	const entries = []
	let cur = null
	let key = null
	const flush = () => { if (cur) entries.push(cur); cur = null; key = null }
	for (const raw of text.split('\n')) {
		const line = raw.trim()
		if (line === '' || line.startsWith('#')) { if (line === '') flush(); continue }
		let m
		if ((m = line.match(/^msgid "(.*)"$/))) {
			flush()
			cur = { msgid: unquote(m[1]), plural: null, msgstr: '', plurals: {} }
			key = 'msgid'
		} else if ((m = line.match(/^msgid_plural "(.*)"$/))) {
			cur.plural = unquote(m[1]); key = 'plural'
		} else if ((m = line.match(/^msgstr "(.*)"$/))) {
			cur.msgstr = unquote(m[1]); key = 'msgstr'
		} else if ((m = line.match(/^msgstr\[(\d+)\] "(.*)"$/))) {
			cur.plurals[m[1]] = unquote(m[2]); key = 'plural' + m[1]
		} else if ((m = line.match(/^"(.*)"$/))) {
			const v = unquote(m[1])
			if (key === 'msgid') cur.msgid += v
			else if (key === 'plural') cur.plural += v
			else if (key === 'msgstr') cur.msgstr += v
			else if (key && key.startsWith('plural')) cur.plurals[key.slice(6)] += v
		}
	}
	flush()
	return entries
}

function unquote(s) {
	return s.replace(/\\(["\\ntr])/g, (_, c) =>
		({ n: '\n', t: '\t', r: '\r', '"': '"', '\\': '\\' }[c]))
}

// ── PO linting (syntax + placeholder consistency) ───────────────────────────
//
// A separate, stricter parser from `parsePo` above: `parsePo` is deliberately
// lenient (it just wants whatever msgstr text it can get for `compile`), but
// `lint` needs to catch the input `parsePo` would silently mangle — stray
// lines, msgstr[n] with no msgid_plural, a missing/malformed Plural-Forms
// header. Kept separate so hardening `lint` can never change what `compile`
// emits.

/** Truncate a msgid for a one-line error message. */
function trunc(s, n = 60) {
	return s.length > n ? s.slice(0, n) + '…' : s
}

/**
 * Strictly parse PO source into `{ entries, errors }`. Every non-blank,
 * non-comment line must be one of: msgid / msgid_plural / msgstr / msgstr[n],
 * or a `"…"` continuation of whichever came before — anything else (a stray
 * line, a continuation with no open entry, msgstr before any msgid) is
 * recorded in `errors` instead of silently ignored or misattributed.
 */
function parsePoStrict(text) {
	const errors = []
	const entries = []
	let cur = null
	let key = null

	const flush = () => {
		if (cur) {
			if (cur.plural === null && Object.keys(cur.plurals).length > 0) {
				errors.push({ line: cur.msgidLine, message: `msgid "${trunc(cur.msgid)}" has msgstr[n] forms but no msgid_plural` })
			}
			if (cur.plural !== null && cur.msgstr !== '') {
				errors.push({ line: cur.msgidLine, message: `msgid "${trunc(cur.msgid)}" has both msgid_plural and a plain msgstr` })
			}
			entries.push(cur)
		}
		cur = null
		key = null
	}

	const lines = text.split('\n')
	for (let i = 0; i < lines.length; i++) {
		const lineNo = i + 1
		const raw = lines[i]
		const line = raw.trim()
		if (line === '') { flush(); continue }
		if (line.startsWith('#')) continue
		let m
		if ((m = line.match(/^msgid "(.*)"$/))) {
			flush()
			cur = { msgid: unquote(m[1]), plural: null, msgstr: '', plurals: {}, msgidLine: lineNo }
			key = 'msgid'
		} else if ((m = line.match(/^msgid_plural "(.*)"$/))) {
			if (!cur) { errors.push({ line: lineNo, message: 'msgid_plural with no preceding msgid' }); continue }
			cur.plural = unquote(m[1])
			key = 'plural'
		} else if ((m = line.match(/^msgstr "(.*)"$/))) {
			if (!cur) { errors.push({ line: lineNo, message: 'msgstr with no preceding msgid' }); continue }
			cur.msgstr = unquote(m[1])
			key = 'msgstr'
		} else if ((m = line.match(/^msgstr\[(\d+)\] "(.*)"$/))) {
			if (!cur) { errors.push({ line: lineNo, message: 'msgstr[n] with no preceding msgid' }); continue }
			cur.plurals[m[1]] = unquote(m[2])
			key = 'plural' + m[1]
		} else if ((m = line.match(/^"(.*)"$/))) {
			if (!cur || !key) { errors.push({ line: lineNo, message: `stray string continuation: ${raw.trim()}` }); continue }
			const v = unquote(m[1])
			if (key === 'msgid') cur.msgid += v
			else if (key === 'plural') cur.plural += v
			else if (key === 'msgstr') cur.msgstr += v
			else if (key.startsWith('plural')) cur.plurals[key.slice(6)] += v
		} else {
			errors.push({ line: lineNo, message: `unrecognized PO syntax: ${raw.trim()}` })
		}
	}
	flush()
	return { entries, errors }
}

// A placeholder token is either `{ident}` (kept verbatim across languages) or
// a printf-style conversion: `%n` (Nextcloud's plural count), `%s`/`%d`/…, or
// a positional `%1$s`. `%%` is gettext/printf's escaped literal percent, not
// a placeholder, and is matched (and dropped) so it can't be misread as one.
const PLACEHOLDER_RE = /\{[A-Za-z_][A-Za-z0-9_]*\}|%%|%\d+\$[a-zA-Z]|%[a-zA-Z]/g

/** Extract the multiset of placeholder tokens from a msgid/msgstr string. */
function extractPlaceholders(str) {
	if (!str) return []
	const out = []
	for (const m of str.matchAll(PLACEHOLDER_RE)) {
		if (m[0] === '%%') continue
		out.push(m[0])
	}
	return out
}

/** token -> occurrence count. */
function tally(arr) {
	return arr.reduce((m, t) => m.set(t, (m.get(t) || 0) + 1), new Map())
}

/** Diff two placeholder multisets. Order doesn't matter; identity and count do. */
function diffMultisets(expected, actual) {
	const exp = tally(expected)
	const act = tally(actual)
	const missing = []
	const extra = []
	for (const [tok, n] of exp) {
		for (let i = 0; i < n - (act.get(tok) || 0); i++) missing.push(tok)
	}
	for (const [tok, n] of act) {
		for (let i = 0; i < n - (exp.get(tok) || 0); i++) extra.push(tok)
	}
	return { missing, extra }
}

/**
 * Merge the placeholder multisets of `msgid` and `msgid_plural` by taking, per
 * token, the MAX of its count in either form — not the sum. English singular
 * and plural forms usually repeat the same placeholder ("%n card" / "%n
 * cards" both use %n once); summing would demand two %n's in every target
 * msgstr[n], which is wrong — each target form only needs what it actually
 * uses, capped at whichever source form uses the most of that token.
 */
function mergePlaceholderExpectations(msgidTokens, pluralTokens) {
	const a = tally(msgidTokens)
	const b = tally(pluralTokens)
	const keys = new Set([...a.keys(), ...b.keys()])
	const out = []
	for (const k of keys) {
		const n = Math.max(a.get(k) || 0, b.get(k) || 0)
		for (let i = 0; i < n; i++) out.push(k)
	}
	return out
}

/**
 * Lint one catalogue's PO source. Pure function (no I/O, no process.exit) so
 * it can be unit-tested directly. Returns `{ ok, problems }`, where each
 * problem is `{ line, message }`.
 *
 * Empty `msgstr`/`msgstr[n]` values are untranslated-by-design (English
 * fallback) and are never checked — only content a translator actually wrote
 * has to preserve placeholders.
 */
function lintCatalogText(lang, text) {
	const problems = []
	const { entries, errors: parseErrors } = parsePoStrict(text)
	for (const e of parseErrors) {
		problems.push({ line: e.line, message: `[${lang}] ${e.message}` })
	}

	const header = entries.find((e) => e.msgid === '')
	let nplurals = null
	if (!header) {
		problems.push({ line: 1, message: `[${lang}] missing PO header (empty msgid) entry` })
	} else {
		const m = header.msgstr.match(/Plural-Forms:\s*nplurals\s*=\s*(\d+)\s*;/)
		if (!m) {
			problems.push({ line: header.msgidLine, message: `[${lang}] header is missing a valid "Plural-Forms: nplurals=N;" declaration` })
		} else {
			nplurals = Number(m[1])
		}
	}

	for (const e of entries) {
		if (e.msgid === '') continue // the header entry itself
		if (e.plural !== null) {
			if (nplurals !== null) {
				for (const idxStr of Object.keys(e.plurals)) {
					const idx = Number(idxStr)
					if (idx >= nplurals) {
						problems.push({
							line: e.msgidLine,
							message: `[${lang}] msgid "${trunc(e.msgid)}": msgstr[${idx}] present but Plural-Forms declares nplurals=${nplurals} (valid indices 0..${nplurals - 1})`,
						})
					}
				}
			}
			const expected = mergePlaceholderExpectations(extractPlaceholders(e.msgid), extractPlaceholders(e.plural))
			for (const idxStr of Object.keys(e.plurals).sort()) {
				const val = e.plurals[idxStr]
				if (!val) continue // untranslated plural form — allowed
				const { missing, extra } = diffMultisets(expected, extractPlaceholders(val))
				if (missing.length || extra.length) {
					problems.push({
						line: e.msgidLine,
						message: `[${lang}] msgid "${trunc(e.msgid)}" msgstr[${idxStr}]: placeholder mismatch`
							+ (missing.length ? ` — missing ${missing.join(', ')}` : '')
							+ (extra.length ? ` — unexpected ${extra.join(', ')}` : ''),
					})
				}
			}
		} else {
			if (!e.msgstr) continue // untranslated — allowed
			const expected = extractPlaceholders(e.msgid)
			const { missing, extra } = diffMultisets(expected, extractPlaceholders(e.msgstr))
			if (missing.length || extra.length) {
				problems.push({
					line: e.msgidLine,
					message: `[${lang}] msgid "${trunc(e.msgid)}": placeholder mismatch`
						+ (missing.length ? ` — missing ${missing.join(', ')}` : '')
						+ (extra.length ? ` — unexpected ${extra.join(', ')}` : ''),
				})
			}
		}
	}

	return { ok: problems.length === 0, problems }
}

/** Every language with a translationfiles/<lang>/kanso.po. */
function listLangs() {
	return fs.existsSync(PO_DIR)
		? fs.readdirSync(PO_DIR).filter((d) => d !== 'templates'
			&& fs.existsSync(path.join(PO_DIR, d, 'kanso.po')))
		: []
}

/** Lint every translationfiles/<lang>/kanso.po. Prints `::error::` annotations. */
function lintAll() {
	const langs = listLangs()
	if (!langs.length) {
		console.log('No translationfiles/<lang>/kanso.po to lint.')
		return true
	}
	let ok = true
	for (const lang of langs) {
		const file = path.join(PO_DIR, lang, 'kanso.po')
		const rel = path.relative(ROOT, file)
		const text = fs.readFileSync(file, 'utf8')
		const { ok: fileOk, problems } = lintCatalogText(lang, text)
		if (!fileOk) {
			ok = false
			for (const p of problems) {
				console.error(`::error file=${rel},line=${p.line}::${p.message}`)
			}
		} else {
			console.log(`${rel} — OK`)
		}
	}
	return ok
}

// ── compile PO → l10n/<lang>.{js,json} ──────────────────────────────────────

function compileLang(lang) {
	const po = path.join(PO_DIR, lang, 'kanso.po')
	if (!fs.existsSync(po)) return false
	const entries = parsePo(fs.readFileSync(po, 'utf8'))
	// Honour the catalog's own Plural-Forms header (languages differ: German has
	// 2 forms, Chinese 1, Polish/Russian 3) — fall back to the 2-form default.
	const header = entries.find((e) => e.msgid === '')
	const pluralForm = (header?.msgstr.match(/Plural-Forms:\s*([^\n]+?)\s*$/m)?.[1]) || PLURAL_FORM
	/** @type {Record<string, string|string[]>} */
	const translations = {}
	for (const e of entries) {
		if (!e.msgid) continue // header
		if (e.plural) {
			const forms = Object.keys(e.plurals).sort().map((k) => e.plurals[k])
			if (forms.some((f) => f && f.length)) {
				translations[`_${e.msgid}_::_${e.plural}_`] = forms
			}
		} else if (e.msgstr) {
			translations[e.msgid] = e.msgstr
		}
	}
	fs.mkdirSync(L10N_DIR, { recursive: true })

	const jsBody = Object.entries(translations)
		.map(([k, v]) => `    ${JSON.stringify(k)} : ${JSON.stringify(v)}`)
		.join(',\n')
	const js = `OC.L10N.register(\n    "kanso",\n    {\n${jsBody}\n},\n"${pluralForm}");\n`
	fs.writeFileSync(path.join(L10N_DIR, `${lang}.js`), js)

	const json = JSON.stringify({ translations, pluralForm }, null, 4) + '\n'
	fs.writeFileSync(path.join(L10N_DIR, `${lang}.json`), json)

	console.log(`Compiled ${lang} — ${Object.keys(translations).length} translated strings`)
	return true
}

// ── block-level PO/POT rewriting (init + sync) ──────────────────────────────
//
// `parsePo`/`parsePoStrict` above decode PO *values*, which is what compile and
// lint need. Scaffolding and merging instead need to MOVE existing lines
// around without changing them, so this third reader keeps every value as its
// raw source lines: an existing translation is re-emitted byte-for-byte, with
// no unquote → re-escape round trip that could quietly rewrite a translator's
// text.

/** Split PO source into its blank-line-separated blocks of raw lines. */
function splitBlocks(text) {
	const blocks = []
	let cur = []
	for (const raw of text.split('\n')) {
		if (raw.trim() === '') {
			if (cur.length) blocks.push(cur)
			cur = []
			continue
		}
		cur.push(raw)
	}
	if (cur.length) blocks.push(cur)
	return blocks
}

/**
 * @typedef {{ comments: string[], msgid: string|null, msgidLines: string[],
 *   plural: string|null, pluralLines: string[], msgstrLines: string[],
 *   slots: Map<number, string[]> }} Block
 */

/** Parse one block into its parts, keeping every value line verbatim. */
function parseBlockRaw(lines) {
	/** @type {Block} */
	const b = { comments: [], msgid: null, msgidLines: [], plural: null, pluralLines: [], msgstrLines: [], slots: new Map() }
	let sink = null
	let text = null
	for (const raw of lines) {
		const line = raw.trim()
		if (line.startsWith('#')) { b.comments.push(raw); continue }
		let m
		if ((m = line.match(/^msgid "(.*)"$/))) {
			b.msgid = unquote(m[1]); sink = b.msgidLines; text = 'msgid'
		} else if ((m = line.match(/^msgid_plural "(.*)"$/))) {
			b.plural = unquote(m[1]); sink = b.pluralLines; text = 'plural'
		} else if (/^msgstr "(.*)"$/.test(line)) {
			sink = b.msgstrLines; text = null
		} else if ((m = line.match(/^msgstr\[(\d+)\] "(.*)"$/))) {
			sink = []; b.slots.set(Number(m[1]), sink); text = null
		} else if ((m = line.match(/^"(.*)"$/))) {
			if (!sink) continue
			if (text === 'msgid') b.msgid += unquote(m[1])
			else if (text === 'plural') b.plural += unquote(m[1])
		} else {
			continue // malformed — `lint` owns reporting it; rewriting just skips it
		}
		sink.push(raw)
	}
	return b
}

/** Serialise blocks (arrays of raw lines) back into PO source. */
function renderBlocks(blocks) {
	return blocks.map((b) => b.join('\n')).join('\n\n') + '\n'
}

/** Re-label a msgstr value's first line (`msgstr` ⇄ `msgstr[n]`), value intact. */
function reKey(lines, keyword) {
	if (!lines.length) return []
	const out = lines.slice()
	out[0] = out[0].replace(/^(\s*)msgstr(?:\[\d+\])? /, `$1${keyword} `)
	return out
}

/** True if a msgstr value's raw lines carry any actual text. */
function hasText(lines) {
	return lines.some((l) => !/^\s*(?:msgstr(?:\[\d+\])? )?""\s*$/.test(l))
}

/** Rebuild an entry block: POT msgid + refreshed refs + the given msgstr lines. */
function entryBlock(potBlock, oldBlock, valueLines) {
	const comments = [
		// Translator comments / flags stay; `#:` source refs come from the POT.
		...(oldBlock ? oldBlock.comments.filter((c) => !c.trim().startsWith('#:')) : []),
		...potBlock.comments.filter((c) => c.trim().startsWith('#:')),
	]
	const lines = [...comments, ...potBlock.msgidLines]
	if (potBlock.plural !== null) lines.push(...potBlock.pluralLines)
	lines.push(...valueLines)
	return lines
}

/**
 * Scaffold a catalogue for `lang` from POT source: the language's own
 * Plural-Forms header, and exactly that many empty `msgstr[n]` slots on every
 * plural entry. Pure function over text so it is unit-testable.
 */
function scaffoldFromPot(potText, lang, pluralForm = pluralFormFor(lang)) {
	const nplurals = npluralsOf(pluralForm)
	const out = []
	for (const b of splitBlocks(potText).map(parseBlockRaw)) {
		if (b.msgid === '') {
			const msgstr = []
			for (const raw of b.msgstrLines) {
				if (raw.trim().startsWith('"Plural-Forms:')) {
					msgstr.push(`"Plural-Forms: ${pluralForm}\\n"`)
					continue
				}
				msgstr.push(raw)
				if (raw.trim() === 'msgstr ""') msgstr.push(`"Language: ${lang}\\n"`)
			}
			out.push([...b.comments, ...b.msgidLines, ...msgstr])
			continue
		}
		if (b.msgid === null) continue
		const value = b.plural !== null
			? Array.from({ length: nplurals }, (_, i) => `msgstr[${i}] ""`)
			: ['msgstr ""']
		out.push(entryBlock(b, null, value))
	}
	return renderBlocks(out)
}

/**
 * Merge POT source into an existing catalogue — the msgmerge step, without
 * gettext. Adds new msgids empty, drops msgids the POT no longer has, refreshes
 * `#:` source references, and re-slots plural entries to however many forms the
 * catalogue's OWN Plural-Forms header declares. The header block itself is
 * copied through untouched: the language's plural rule is the translator's
 * call, not this tool's. Pure function over text, returns `{ text, stats }`.
 */
function mergeCatalog(potText, poText) {
	const potBlocks = splitBlocks(potText).map(parseBlockRaw)
	const poBlocks = splitBlocks(poText).map(parseBlockRaw)

	const headerAt = poBlocks.findIndex((b) => b.msgid === '')
	if (headerAt < 0) throw new Error('catalogue has no PO header entry (empty msgid)')
	const header = poBlocks[headerAt]
	const nplurals = npluralsOf(header.msgstrLines.join('\n'))

	/** @type {Map<string, Block>} */
	const existing = new Map()
	for (const b of poBlocks) if (b.msgid) existing.set(b.msgid, b)

	const out = [
		// A comment-only block above the header (a PO editor can split the SPDX
		// header off) is kept — dropping it would strip the file's licence.
		...poBlocks.slice(0, headerAt).filter((b) => b.msgid === null).map((b) => b.comments),
		[...header.comments, ...header.msgidLines, ...header.msgstrLines],
	]
	const stats = { total: 0, added: 0, removed: 0, translated: 0 }
	const seen = new Set()

	for (const p of potBlocks) {
		if (!p.msgid) continue // header (or unparseable) block
		seen.add(p.msgid)
		const old = existing.get(p.msgid)
		if (!old) stats.added++

		let value
		if (p.plural !== null) {
			value = []
			for (let i = 0; i < nplurals; i++) {
				// An entry that used to be singular keeps its translation in slot 0.
				const raw = old?.slots.get(i)
					?? (i === 0 && old && old.plural === null && hasText(old.msgstrLines)
						? reKey(old.msgstrLines, 'msgstr[0]')
						: null)
				value.push(...(raw && raw.length ? raw : [`msgstr[${i}] ""`]))
			}
		} else {
			// …and one that used to be plural keeps slot 0 as its singular.
			const raw = old && hasText(old.msgstrLines)
				? old.msgstrLines
				: (old?.slots.get(0) && hasText(old.slots.get(0)) ? reKey(old.slots.get(0), 'msgstr') : null)
			value = raw && raw.length ? raw : ['msgstr ""']
		}
		if (hasText(value)) stats.translated++
		stats.total++
		out.push(entryBlock(p, old, value))
	}

	for (const msgid of existing.keys()) if (!seen.has(msgid)) stats.removed++

	return { text: renderBlocks(out), stats }
}

// ── scaffold a new PO from the POT ──────────────────────────────────────────

function initLang(lang) {
	if (!fs.existsSync(POT)) { console.error('Run extract first — no POT.'); process.exit(1) }
	const dest = path.join(PO_DIR, lang, 'kanso.po')
	if (fs.existsSync(dest)) { console.error(`${path.relative(ROOT, dest)} already exists.`); process.exit(1) }
	const pluralForm = pluralFormFor(lang)
	fs.mkdirSync(path.dirname(dest), { recursive: true })
	fs.writeFileSync(dest, scaffoldFromPot(fs.readFileSync(POT, 'utf8'), lang, pluralForm))
	if (!PLURAL_FORMS[lang]) {
		console.warn(`No plural rule known for "${lang}" — defaulted to "${PLURAL_FORM}". Correct the Plural-Forms header, then run \`sync ${lang}\`.`)
	}
	console.log(`Scaffolded ${path.relative(ROOT, dest)} (${pluralForm}) — fill in the msgstr values.`)
}

// ── merge the POT into existing catalogues ──────────────────────────────────

function syncLang(lang) {
	const file = path.join(PO_DIR, lang, 'kanso.po')
	if (!fs.existsSync(file)) {
		console.error(`${path.relative(ROOT, file)} does not exist — run \`init ${lang}\` first.`)
		return false
	}
	const { text, stats } = mergeCatalog(fs.readFileSync(POT, 'utf8'), fs.readFileSync(file, 'utf8'))
	fs.writeFileSync(file, text)
	console.log(`Synced ${path.relative(ROOT, file)} — ${stats.total} entries `
		+ `(+${stats.added} new, -${stats.removed} obsolete, ${stats.translated} translated kept)`)
	return true
}

// ── main ────────────────────────────────────────────────────────────────────
//
// Guarded so `l10n.lint.test.mjs` can `import { lintCatalogText, … }` from
// this file without also running the CLI dispatch below (and calling
// `process.exit` out from under the test runner).

const isMain = process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)
if (isMain) {
	const cmd = process.argv[2]
	if (cmd === 'extract') {
		const map = new Map()
		extractFrontend(map)
		extractBackend(map)
		writePot(map)
	} else if (cmd === 'compile') {
		const langs = listLangs()
		if (!langs.length) console.log('No translationfiles/<lang>/kanso.po to compile.')
		langs.forEach(compileLang)
	} else if (cmd === 'lint') {
		process.exit(lintAll() ? 0 : 1)
	} else if (cmd === 'init') {
		const lang = process.argv[3]
		if (!lang) { console.error('Usage: node scripts/l10n.mjs init <lang>'); process.exit(1) }
		initLang(lang)
	} else if (cmd === 'sync') {
		if (!fs.existsSync(POT)) { console.error('Run extract first — no POT.'); process.exit(1) }
		const lang = process.argv[3]
		const langs = lang ? [lang] : listLangs()
		if (!langs.length) console.log('No translationfiles/<lang>/kanso.po to sync.')
		let ok = true
		for (const l of langs) if (!syncLang(l)) ok = false
		process.exit(ok ? 0 : 1)
	} else {
		console.error('Usage: node scripts/l10n.mjs <extract|compile|lint|init <lang>|sync [lang]>')
		process.exit(1)
	}
}

export {
	parsePoStrict, extractPlaceholders, diffMultisets, mergePlaceholderExpectations, lintCatalogText,
	pluralFormFor, npluralsOf, scaffoldFromPot, mergeCatalog,
}
