// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #9981 — the drag-handle re-registration must not leave a latched drag class.
//
// CardTile and StackColumn hold their pragmatic draggable apart from the rest
// of their cleanup because it is the one registration that comes and goes: a
// board ACL change reaches the client on the periodic board refetch, not
// through a remount, so both re-run a sync function when `canEditBoard` flips.
//
// Tearing the draggable down destroys it, which means its `onDrop` will never
// run. If the flip lands DURING a drag (an admin demoting the viewer to a
// read-only member while they hold a tile), the component's dragging flag —
// which only `onDrop` clears — stays true and the visual drag state
// (`card-tile-wrap--dragging` / `stack-column--dragging`) latches on for the
// rest of the session. The fix is one line per component: clear the flag as
// part of the teardown, since it describes the registration being destroyed.
//
// Asserted at the source level, in the same spirit as the main.js guard in
// queryKeys.test.mjs. The alternative would be mounting two SFCs with a stubbed
// pragmatic adapter, which needs a bundler + DOM this suite deliberately does
// not carry; and manufacturing the race in Playwright needs an admin demotion
// to land inside a live HTML5 drag, which is not worth the e2e budget. What can
// regress here is the line going missing, and that is exactly what this catches.

import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

/** The body of `function <name>() { … }`, brace-matched. */
function functionBody(source, name) {
	const start = source.indexOf(`function ${name}() {`)
	assert.notEqual(start, -1, `${name}() not found — was it renamed?`)
	let depth = 0
	for (let i = source.indexOf('{', start); i < source.length; i++) {
		if (source[i] === '{') depth++
		else if (source[i] === '}' && --depth === 0) return source.slice(start, i + 1)
	}
	assert.fail(`unbalanced braces in ${name}()`)
}

const cases = [
	{
		file: 'CardTile.vue',
		fn: 'syncDragHandle',
		cleanup: 'dragCleanup()',
		flag: 'isDragging.value = false',
	},
	{
		file: 'StackColumn.vue',
		fn: 'syncStackDragHandle',
		cleanup: 'stackDragCleanup()',
		flag: 'isStackDragging.value = false',
	},
]

for (const { file, fn, cleanup, flag } of cases) {
	test(`${file}: ${fn} clears the dragging flag when it tears the draggable down`, async () => {
		const source = await readFile(
			new URL(`../../src/components/${file}`, import.meta.url), 'utf8',
		)
		const body = functionBody(source, fn)

		const teardownAt = body.indexOf(cleanup)
		const flagAt = body.indexOf(flag)
		const reregisterAt = body.indexOf('draggable({')

		assert.notEqual(teardownAt, -1, `${fn}() no longer tears the draggable down`)
		assert.notEqual(reregisterAt, -1, `${fn}() no longer re-registers a draggable`)
		assert.notEqual(flagAt, -1,
			`${fn}() must reset the dragging flag — a flip mid-drag destroys the `
			+ 'draggable before its onDrop can run, latching the drag class on')

		assert.ok(flagAt > teardownAt && flagAt < reregisterAt,
			`the reset must sit between the teardown and the re-registration in ${fn}()`)
	})
}
