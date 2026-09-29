// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Card 10666 — the "Add to Kanso…" Files action's icon.
//
// `registerFileAction({ iconSvgInline })` hands the Files app a RAW SVG STRING
// that is injected into NcIconSvgWrapper. The only colouring that wrapper does
// is a CSS `fill: currentColor` on the `<svg>` ELEMENT, and a CSS declaration on
// the parent loses to a `fill` PRESENTATION ATTRIBUTE on the shapes inside it.
// Measured live in the Files action menu on NC 34: with `img/app.svg` the rects
// compute to `rgb(255,255,255)` while the wrapper's colour is `rgb(34,34,34)` —
// a white icon on the white menu, i.e. nothing at all in the default theme. With
// `img/app-dark.svg` the same rects compute to the wrapper's colour in both
// themes.
//
// So the invariant is a pair, and both halves are asserted here:
//   * files.js must inline the DARK asset, and
//   * that asset must carry no `fill` of its own (or it stops inheriting), while
//     `img/app.svg` must stay white — it is the app-menu / PWA icon (#162).
//
// This is a source-level guard on purpose: the import is resolved by Vite at
// build time, so there is no runtime seam to assert against, and a revert of the
// import is exactly the regression worth catching.

import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('../..', import.meta.url))
const read = (p) => readFileSync(root + p, 'utf8')

test('the Files action inlines the dark app icon, not the white one', () => {
	const src = read('src/files.js')

	assert.match(
		src,
		/import\s+kansoSvg\s+from\s+'\.\.\/img\/app-dark\.svg\?raw'/,
		'src/files.js must inline img/app-dark.svg — app.svg hardcodes fill="#fff", which beats NcIconSvgWrapper\'s CSS fill and renders the action icon invisible on light themes',
	)
	assert.doesNotMatch(
		src,
		/from\s+'\.\.\/img\/app\.svg\?raw'/,
		'src/files.js must not inline the white app-menu icon',
	)
})

test('app-dark.svg carries no fill of its own so it inherits currentColor', () => {
	const svg = read('img/app-dark.svg')

	assert.doesNotMatch(
		svg,
		/fill\s*[:=]/i,
		'img/app-dark.svg must declare no fill at all — a fill attribute would override the inherited currentColor and pin the icon to one theme',
	)
})

test('app.svg stays white — it is the app-menu and PWA icon', () => {
	const svg = read('img/app.svg')

	assert.match(
		svg,
		/fill\s*=\s*"(#fff(fff)?|white)"/i,
		'img/app.svg must stay white (#162); darkening it would just move the bug to the app menu',
	)
})
