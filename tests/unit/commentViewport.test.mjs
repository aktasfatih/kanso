// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import test from 'node:test'
import assert from 'node:assert/strict'
import { observeCommentViewport } from '../../src/composables/useCommentViewport.js'

function style() {
	const values = new Map()
	return {
		setProperty(key, value, priority = '') { values.set(key, [value, priority]) },
		getPropertyValue(key) { return values.get(key)?.[0] ?? '' },
		getPropertyPriority(key) { return values.get(key)?.[1] ?? '' },
		removeProperty(key) { values.delete(key) },
	}
}

function harness({ width = 390, height = 800 } = {}) {
	const viewport = Object.assign(new EventTarget(), { height, offsetTop: 0, scale: 1 })
	const frameCallbacks = new Map()
	let nextFrame = 1
	const view = Object.assign(new EventTarget(), {
		visualViewport: viewport,
		innerHeight: height,
		innerWidth: width,
		requestAnimationFrame(callback) { const id = nextFrame++; frameCallbacks.set(id, callback); return id },
		cancelAnimationFrame(id) { frameCallbacks.delete(id) },
		getComputedStyle() { return { overflowY: 'visible' } },
		scrollBy() {},
	})
	const container = { style: style() }
	container.style.setProperty('height', 'calc(100% - 50px)')
	const classes = new Set()
	let scrolls = 0
	const group = {
		classList: { add(value) { classes.add(value) }, remove(value) { classes.delete(value) } },
		style: style(),
		parentElement: null,
		scrollIntoView() { scrolls++ },
		getBoundingClientRect() { return { top: 100, bottom: 280 } },
	}
	const editorScroller = { scrollTop: 0, getBoundingClientRect() { return { top: 100, bottom: 200 } } }
	let caretBottom = 250
	const editor = {
		closest(selector) { return selector === '.kanso-md-editor' ? editorScroller : group },
		contains() { return true },
	}
	const doc = Object.assign(new EventTarget(), { activeElement: editor })
	doc.getSelection = () => ({
		rangeCount: 1,
		anchorNode: editor,
		getRangeAt() {
			return { getBoundingClientRect() { return { top: caretBottom - editorScroller.scrollTop - 18, bottom: caretBottom - editorScroller.scrollTop, height: 18 } } }
		},
	})
	const root = Object.assign(new EventTarget(), {
		ownerDocument: doc,
		contains(element) { return element === editor },
		closest() { return container },
	})
	const stop = observeCommentViewport(root, view)
	return {
		viewport, view, root, doc, container, group, editor, classes, stop, editorScroller,
		setCaretBottom(value) { caretBottom = value },
		scrolls: () => scrolls,
		flush() { for (const [id, callback] of frameCallbacks) { frameCallbacks.delete(id); callback() } },
	}
}

test('comment focus plus a keyboard viewport fits the modal and keeps correcting while typing', () => {
	const h = harness()
	h.viewport.height = 400
	h.viewport.offsetTop = 30
	h.viewport.dispatchEvent(new Event('resize'))
	h.flush()
	assert.equal(h.container.style.getPropertyValue('height'), '400px')
	assert.equal(h.container.style.getPropertyPriority('height'), 'important')
	assert.equal(h.container.style.getPropertyValue('top'), '30px')
	assert.equal(h.classes.has('card-modal__comment-viewport'), true)
	assert.equal(h.scrolls(), 1)
	assert.equal(h.editorScroller.scrollTop, 54)
	h.setCaretBottom(300)
	h.root.dispatchEvent(new Event('input'))
	h.doc.dispatchEvent(new Event('selectionchange'))
	h.flush()
	assert.equal(h.scrolls(), 2)
	assert.equal(h.editorScroller.scrollTop, 104)
	h.stop()
	assert.equal(h.container.style.getPropertyValue('height'), 'calc(100% - 50px)')
	assert.equal(h.container.style.getPropertyPriority('height'), '')
	assert.equal(h.container.style.getPropertyValue('top'), '')
	assert.equal(h.classes.size, 0)
	h.viewport.dispatchEvent(new Event('resize'))
	h.root.dispatchEvent(new Event('input'))
	h.flush()
	assert.equal(h.scrolls(), 2)
})

test('leaving the comment restores modal sizing and unrelated desktop focus stays untouched', () => {
	const h = harness()
	h.viewport.height = 400
	h.root.dispatchEvent(new Event('focusin'))
	h.flush()
	h.doc.activeElement = {}
	h.root.dispatchEvent(new Event('focusout'))
	h.flush()
	assert.equal(h.container.style.getPropertyValue('height'), 'calc(100% - 50px)')
	assert.equal(h.classes.size, 0)
	h.view.innerWidth = 1200
	h.viewport.dispatchEvent(new Event('resize'))
	h.flush()
	assert.equal(h.container.style.getPropertyValue('position'), '')
	h.stop()
})

test('keyboard dismissal restores layout even while the comment remains focused', () => {
	const h = harness()
	h.viewport.height = 400
	h.root.dispatchEvent(new Event('focusin'))
	h.flush()
	assert.equal(h.container.style.getPropertyValue('height'), '400px')
	h.viewport.height = 800
	h.viewport.dispatchEvent(new Event('resize'))
	h.flush()
	assert.equal(h.container.style.getPropertyValue('height'), 'calc(100% - 50px)')
	assert.equal(h.classes.size, 0)
	h.stop()
})

test('rotation to portrait refreshes the layout height while the comment stays focused', () => {
	const h = harness({ width: 844, height: 390 })
	h.view.innerWidth = 390
	h.view.innerHeight = 844
	h.viewport.height = 500
	h.view.dispatchEvent(new Event('resize'))
	h.flush()
	assert.equal(h.container.style.getPropertyValue('height'), '500px')
	assert.equal(h.classes.has('card-modal__comment-viewport'), true)

	h.view.innerHeight = 500
	h.view.dispatchEvent(new Event('resize'))
	h.flush()
	assert.equal(h.container.style.getPropertyValue('height'), '500px')

	h.view.innerHeight = 844
	h.viewport.height = 844
	h.viewport.dispatchEvent(new Event('resize'))
	h.flush()
	assert.equal(h.container.style.getPropertyValue('height'), 'calc(100% - 50px)')
	assert.equal(h.classes.size, 0)
	h.stop()
})

test('a narrower layout height replaces the previous orientation baseline', () => {
	const h = harness({ width: 390, height: 844 })
	h.view.innerWidth = 420
	h.view.innerHeight = 600
	h.viewport.height = 600
	h.view.dispatchEvent(new Event('resize'))
	h.flush()
	assert.equal(h.classes.size, 0)

	h.view.innerHeight = 350
	h.viewport.height = 350
	h.view.dispatchEvent(new Event('resize'))
	h.flush()
	assert.equal(h.container.style.getPropertyValue('height'), '350px')

	h.view.innerHeight = 600
	h.viewport.height = 600
	h.viewport.dispatchEvent(new Event('resize'))
	h.flush()
	assert.equal(h.container.style.getPropertyValue('height'), 'calc(100% - 50px)')
	assert.equal(h.classes.size, 0)
	h.stop()
})
