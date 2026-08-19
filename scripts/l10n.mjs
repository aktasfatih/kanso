// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Kanso translation tool — zero-dependency gettext pipeline.
 *
 *   node scripts/l10n.mjs extract     # scan sources → translationfiles/templates/kanso.pot
 *   node scripts/l10n.mjs compile     # each translationfiles/<lang>/kanso.po → l10n/<lang>.{js,json}
 *   node scripts/l10n.mjs init <lang> # scaffold translationfiles/<lang>/kanso.po from the POT
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
 * Strings translated at render time via a variable — t('kanso', someVar) — cannot
 * be seen here; their English keys are enumerated in src/l10n-extra.js so this
 * extractor still picks them up.
 */

import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const POT = path.join(ROOT, 'translationfiles', 'templates', 'kanso.pot')
const PO_DIR = path.join(ROOT, 'translationfiles')
const L10N_DIR = path.join(ROOT, 'l10n')

const PLURAL_FORM = 'nplurals=2; plural=(n != 1);'

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

// ── scaffold a new PO from the POT ──────────────────────────────────────────

function initLang(lang) {
	if (!fs.existsSync(POT)) { console.error('Run extract first — no POT.'); process.exit(1) }
	const dest = path.join(PO_DIR, lang, 'kanso.po')
	if (fs.existsSync(dest)) { console.error(`${path.relative(ROOT, dest)} already exists.`); process.exit(1) }
	const pot = fs.readFileSync(POT, 'utf8').replace(
		'msgid ""\nmsgstr ""\n',
		`msgid ""\nmsgstr ""\n"Language: ${lang}\\n"\n`,
	)
	fs.mkdirSync(path.dirname(dest), { recursive: true })
	fs.writeFileSync(dest, pot)
	console.log(`Scaffolded ${path.relative(ROOT, dest)} — fill in the msgstr values.`)
}

// ── main ────────────────────────────────────────────────────────────────────

const cmd = process.argv[2]
if (cmd === 'extract') {
	const map = new Map()
	extractFrontend(map)
	extractBackend(map)
	writePot(map)
} else if (cmd === 'compile') {
	const langs = fs.existsSync(PO_DIR)
		? fs.readdirSync(PO_DIR).filter((d) => d !== 'templates'
			&& fs.existsSync(path.join(PO_DIR, d, 'kanso.po')))
		: []
	if (!langs.length) console.log('No translationfiles/<lang>/kanso.po to compile.')
	langs.forEach(compileLang)
} else if (cmd === 'init') {
	const lang = process.argv[3]
	if (!lang) { console.error('Usage: node scripts/l10n.mjs init <lang>'); process.exit(1) }
	initLang(lang)
} else {
	console.error('Usage: node scripts/l10n.mjs <extract|compile|init <lang>>')
	process.exit(1)
}
