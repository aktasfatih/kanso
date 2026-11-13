// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import test from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { parse } from 'vue/compiler-sfc'
import { scanSource } from './check-enterkeyhint.mjs'
import { spawnSync } from 'node:child_process'

const scan = source => scanSource('fixture.vue', `<template>\n${source}\n</template>`)

for (const tag of ['input', 'NcTextField', 'NcInputField', 'nc-text-field', 'nc-input-field']) {
	test(`requires a hint on ${tag}`, () => {
		assert.equal(scan(`<${tag} @keydown.enter="save" />`).length, 1)
		assert.deepEqual(scan(`<${tag} enterkeyhint="done" @keydown.enter="save" />`), [])
	})
}

for (const handler of [
	'@keydown.enter="save"',
	'@keyup.enter="save"',
	'v-on:keydown.enter="save"',
	'v-on:keyup.ctrl.enter.prevent="save"',
	'@keydown.enter.prevent.exact="save"',
	'@keyup.shift.enter="submitOnEnter($event, save)"',
	'@keydown.enter="submitOnEnter($event, () => save(value))"',
]) {
	test(`recognizes ${handler}`, () => {
		assert.equal(scan(`<input ${handler} />`).length, 1)
		assert.deepEqual(scan(`<input ${handler} enterkeyhint="enter" />`), [])
	})
}

test('does not mistake unrelated handlers or tags for Enter-save inputs', () => {
	assert.deepEqual(scan('<input @keydown="save" /><input @keydown.escape="cancel" /><div @keyup.enter="save" /><textarea @keydown.enter="save" />'), [])
})

test('accepts static hints and decoded bound string literals', () => {
	for (const hint of [
		'enterkeyhint="done"',
		'enterkeyhint="ENTER"',
		':enterkeyhint="\'done\'"',
		'v-bind:enterkeyhint="\'search\'"',
		':enterkeyhint="`send`"',
		':enterkeyhint="\'\\u0064one\'"',
		':enterkeyhint="&quot;done&quot;"',
	]) { assert.deepEqual(scan(`<input ${hint} @keyup.enter="save" />`), [], hint) }
})

test('rejects missing, empty, zero and unverifiable hints', () => {
	for (const hint of [
		'', 'enterkeyhint', 'enterkeyhint=""', 'enterkeyhint=" "', 'enterkeyhint="0"',
		':enterkeyhint="\'\'"', ':enterkeyhint="0"', ':enterkeyhint="false"',
		':enterkeyhint="hint"', ':enterkeyhint="`done${suffix}`"',
		'enterkeyhint="invalid"',
	]) { assert.equal(scan(`<input ${hint} @keydown.enter="save" />`).length, 1, hint) }
})

test('never executes a bound expression', () => {
	assert.equal(scan('<input :enterkeyhint="(globalThis.__kansoHintEvaluated = true, \'done\')" @keyup.enter="save" />').length, 1)
	assert.equal(globalThis.__kansoHintEvaluated, undefined)
})

test('excludes known non-text types, including bound string literals', () => {
	for (const type of ['button', 'checkbox', 'color', 'date', 'datetime-local', 'file', 'hidden', 'image', 'month', 'radio', 'range', 'reset', 'submit', 'time', 'week']) {
		assert.deepEqual(scan(`<input type="${type}" @keydown.enter="save" />`), [], type)
		assert.deepEqual(scan(`<NcInputField :type="'${type}'" @keyup.enter="save" />`), [], type)
	}
})

test('text-entry, invalid and unknown dynamic types still need hints', () => {
	for (const type of ['', 'type="text"', 'type="number"', 'type="email"', 'type="invalid"', 'type=" checkbox "', ':type="type"', ':type="0"']) {
		assert.equal(scan(`<input ${type} @keyup.enter="save" />`).length, 1, type)
	}
})

test('spreads and dynamic attribute names cannot prove a type exclusion or hint', () => {
	assert.equal(scan('<input type="checkbox" v-bind="attrs" @keyup.enter="save" />').length, 1)
	assert.equal(scan('<input enterkeyhint="done" v-bind="attrs" @keyup.enter="save" />').length, 1)
	assert.equal(scan('<input type="date" :[name]="value" @keyup.enter="save" />').length, 1)
})

test('reports once for an input with both Enter handlers', () => {
	assert.equal(scan('<input @keydown.enter="save" @keyup.enter="save" />').length, 1)
})

test('ignores markup lookalikes in script, style and comments', () => {
	const source = '<script setup>const example = \'<input @keydown.enter="save" />\'</script>\n<template><!-- <input @keyup.enter="save" /> --><div>Example</div></template>\n<style>/* <input @keyup.enter="save" /> */</style>'
	assert.deepEqual(scanSource('fixture.vue', source), [])
})

test('reports the input line in the complete SFC', () => {
	const source = '<script setup>\nconst save = () => {}\n</script>\n<template>\n  <input\n    @keyup.enter="save" />\n</template>'
	assert.deepEqual(scanSource('path/fixture.vue', source).map(({ file, line }) => ({ file, line })), [{ file: 'path/fixture.vue', line: 5 }])
})

test('honors an immediately preceding reasoned opt-out for that input only', () => {
	const source = '<!-- enterkeyhint-ok: Next should reach the surname field -->\n  <input @keyup.enter="save" />\n<input @keyup.enter="save" />'
	assert.deepEqual(scan(source).map(item => item.line), [4])
})

test('requires a nonempty opt-out reason and direct sibling attachment', () => {
	for (const prefix of [
		'<!-- enterkeyhint-ok: -->',
		'<!-- enterkeyhint-ok:    -->',
		'<!-- example enterkeyhint-ok: Next is intentional -->',
		'<!-- enterkeyhint-ok: Next is intentional --><span />',
		'<!-- enterkeyhint-ok: Next is intentional --><!-- other comment -->',
	]) { assert.equal(scan(`${prefix}<input @keyup.enter="save" />`).length, 1, prefix) }
	assert.equal(scan('<!-- enterkeyhint-ok: Next is intentional --><div><input @keyup.enter="save" /></div>').length, 1)
})

test('an explicit opt-out can explain a verified dynamic hint', () => {
	assert.deepEqual(scan('<!-- enterkeyhint-ok: The form chooses a validated next/done hint -->\n<input :enterkeyhint="hint" @keyup.enter="save" />'), [])
})

test('malformed and unsupported templates fail the scan', () => {
	for (const source of ['<template><input></div></template>', '<template src="./other.html" />', '<template lang="pug">input</template>', '']) {
		assert.ok(scanSource('fixture.vue', source).length > 0, source)
	}
	assert.deepEqual(scanSource('fixture.vue', '<script>export default {}</script>'), [])
})

test('the real checklist hint removal fails the scanner and CLI without editing source', () => {
	const file = 'src/components/CardDetail.vue'
	const source = readFileSync(new URL(`../${file}`, import.meta.url), 'utf8')
	assert.deepEqual(scanSource(file, source), [])
	const { descriptor } = parse(source, { filename: file })
	function findInput(node) {
		if (node.tag === 'input' && node.props.some(prop => prop.name === 'ref' && prop.value?.content === 'addItemInputRef')) { return node }
		for (const child of node.children ?? []) { const found = findInput(child); if (found) { return found } }
	}
	const input = findInput(descriptor.template.ast)
	assert.ok(input)
	const hint = input.props.find(prop => prop.name === 'enterkeyhint')
	assert.equal(hint.value.content, 'enter')
	const mutant = source.slice(0, hint.loc.start.offset) + source.slice(hint.loc.end.offset)
	assert.equal(source.length - mutant.length, 'enterkeyhint="enter"'.length)
	assert.deepEqual(scanSource(file, mutant).map(({ file, line }) => ({ file, line })), [{ file, line: input.loc.start.line }])
	const dir = mkdtempSync(join(tmpdir(), 'kanso-enterkeyhint-'))
	try {
		mkdirSync(join(dir, 'src/components'), { recursive: true })
		writeFileSync(join(dir, file), mutant)
		const result = spawnSync(process.execPath, [fileURLToPath(new URL('./check-enterkeyhint.mjs', import.meta.url))], { cwd: dir, encoding: 'utf8', timeout: 10_000 })
		assert.equal(result.error, undefined)
		assert.equal(result.status, 1)
		assert.ok(result.stderr.includes(`${file}:${input.loc.start.line} `), result.stderr)
		assert.equal(readFileSync(new URL(`../${file}`, import.meta.url), 'utf8'), source)
	} finally { rmSync(dir, { recursive: true, force: true }) }
})
