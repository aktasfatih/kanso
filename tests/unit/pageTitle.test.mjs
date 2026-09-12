// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #125 — the browser tab title. Nextcloud renders `<title>` once, server-side,
// and Kanso is a hash-router SPA, so every route shared the bare app name.
//
// The two things that can silently go wrong here are both about the BASE title,
// and both are covered below:
//
//   * COMPOUNDING. The suffix is whatever Nextcloud already put in the tab,
//     captured once at module load. Re-reading it per navigation would capture
//     an already-prefixed title and grow it without end ("Board B - Board A - …").
//   * FALL-THROUGH. Titles arrive from async queries that may never resolve (a
//     403 permanently disables the board query), and the card route is NESTED
//     inside the board route, so two components hold a claim at once. An empty
//     claim must defer to the one underneath — never write `undefined`.
//
// The stack is what makes closing a card restore the BOARD title rather than
// the app name, which is the behaviour most likely to regress.
//
// Rig: `document` must exist BEFORE the composable is imported, because that
// import is when the base title is captured — a lazy stub would test a
// different (and wrong) implementation. But it must NOT exist before `vue` is
// imported: @vue/runtime-dom calls document.createElement at ITS module load,
// so a title-only stub crashes the import. Hence the exact order below — window
// stub, vue, document stub, composable — which is also why the imports are
// dynamic (as in the other composable tests here).

import test from 'node:test'
import assert from 'node:assert/strict'

const BASE = 'Kanso - Nextcloud'

globalThis.window = {
	location: { href: 'http://localhost/' },
	addEventListener() {},
	removeEventListener() {},
}
const { effectScope, nextTick, ref, computed } = await import('vue')

globalThis.document = { title: BASE }

const { usePageTitle } = await import('../../src/composables/usePageTitle.js')

/**
 * Run `fn` inside its own effect scope, the way a component's setup() does, and
 * hand back a disposer that stands in for unmounting that component.
 *
 * @param {Function} fn - the "setup" body, which calls usePageTitle
 * @return {{result: any, unmount: Function}} whatever fn returned, plus an unmount
 */
function mount(fn) {
	const scope = effectScope()
	const result = scope.run(fn)
	return { result, unmount: () => scope.stop() }
}

test('a static title is prefixed onto the server-rendered base with core\'s separator', () => {
	const view = mount(() => usePageTitle('My tasks'))
	assert.equal(document.title, 'My tasks - ' + BASE)
	view.unmount()
})

test('unmounting the view restores the base title', () => {
	const view = mount(() => usePageTitle('Projects'))
	assert.equal(document.title, 'Projects - ' + BASE)
	view.unmount()
	assert.equal(document.title, BASE)
})

test('a title that loads late updates the tab, and never writes undefined first', async () => {
	// Exactly the board/card/project/view shape: a query whose data is undefined
	// at first paint.
	const board = ref(undefined)
	const view = mount(() => usePageTitle(() => board.value?.title ?? ''))

	assert.equal(document.title, BASE, 'no title yet ⇒ the bare app name')

	board.value = { title: 'Personal' }
	await nextTick()
	assert.equal(document.title, 'Personal - ' + BASE)

	// A rename while the page is open follows too.
	board.value = { title: 'Personal (2026)' }
	await nextTick()
	assert.equal(document.title, 'Personal (2026) - ' + BASE)

	view.unmount()
	assert.equal(document.title, BASE)
})

test('a board query that never resolves leaves the base title, not "undefined - …"', () => {
	// The 403/404 case: useBoard disables the query for good, so data stays
	// undefined forever.
	const view = mount(() => usePageTitle(() => undefined))
	assert.equal(document.title, BASE)
	assert.ok(!document.title.includes('undefined'))
	view.unmount()
})

test('an open card wins over the board underneath it, and closing gives the board back', () => {
	// BoardView mounts first; the nested card-modal route mounts inside it.
	const boardView = mount(() => usePageTitle('Personal'))
	assert.equal(document.title, 'Personal - ' + BASE)

	const cardModal = mount(() => usePageTitle('Buy milk'))
	assert.equal(document.title, 'Buy milk - ' + BASE, 'the open card owns the tab')

	// Closing the card must restore the BOARD, not the app name. This is the
	// whole reason the claims are a stack.
	cardModal.unmount()
	assert.equal(document.title, 'Personal - ' + BASE)

	boardView.unmount()
	assert.equal(document.title, BASE)
})

test('the outer claim can be released FIRST without stranding the inner one', () => {
	// This is the order Vue actually unmounts in, and the reason claims are
	// spliced by identity rather than popped: a component's effect scope is
	// stopped BEFORE its subtree is unmounted, so BoardView releases before the
	// nested card modal does. Popping would drop the modal's claim on BoardView's
	// release and then the board's on the modal's — leaving the tab on a card
	// that is no longer open.
	const boardView = mount(() => usePageTitle('Personal'))
	const cardModal = mount(() => usePageTitle('Buy milk'))
	assert.equal(document.title, 'Buy milk - ' + BASE)

	boardView.unmount()
	assert.equal(document.title, 'Buy milk - ' + BASE, 'the open card still owns the tab')

	cardModal.unmount()
	assert.equal(document.title, BASE, 'and nothing is stranded behind it')
})

test('a card whose title has not loaded yet defers to the board instead of blanking it', async () => {
	const boardView = mount(() => usePageTitle('Personal'))
	const cardTitle = ref('')
	const cardModal = mount(() => usePageTitle(cardTitle))

	assert.equal(document.title, 'Personal - ' + BASE, 'empty claim falls through')

	cardTitle.value = 'Buy milk'
	await nextTick()
	assert.equal(document.title, 'Buy milk - ' + BASE)

	cardModal.unmount()
	boardView.unmount()
})

test('titles never compound across navigations', () => {
	// Board A → Board B → card → back to the boards list. If the base title were
	// re-read per navigation instead of captured once, each step would prefix the
	// PREVIOUS tab title and the tab would grow without bound.
	const a = mount(() => usePageTitle('Board A'))
	a.unmount()
	const b = mount(() => usePageTitle('Board B'))
	assert.equal(document.title, 'Board B - ' + BASE)
	const card = mount(() => usePageTitle('Some card'))
	assert.equal(document.title, 'Some card - ' + BASE)
	card.unmount()
	b.unmount()
	assert.equal(document.title, BASE)
	assert.equal(document.title.split(' - ').length, 2)
})

test('a composed sub-page title reads as "<board> · <section>"', () => {
	const boardTitle = ref('')
	const view = mount(() => usePageTitle(() => (boardTitle.value
		? boardTitle.value + ' · Analytics'
		: '')))
	// Cold deep-link: the board cache is empty, so no half-built "· Analytics".
	assert.equal(document.title, BASE)
	boardTitle.value = 'Personal'
	return nextTick().then(() => {
		assert.equal(document.title, 'Personal · Analytics - ' + BASE)
		view.unmount()
		assert.equal(document.title, BASE)
	})
})

test('whitespace-only titles are treated as no title at all', () => {
	const view = mount(() => usePageTitle('   '))
	assert.equal(document.title, BASE)
	view.unmount()
})

test('a computed source is accepted as well as a ref or a getter', async () => {
	const raw = ref('personal')
	const title = computed(() => raw.value.toUpperCase())
	const view = mount(() => usePageTitle(title))
	assert.equal(document.title, 'PERSONAL - ' + BASE)
	raw.value = 'work'
	await nextTick()
	assert.equal(document.title, 'WORK - ' + BASE)
	view.unmount()
})
