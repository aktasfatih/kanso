// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { onMounted, onBeforeUnmount } from 'vue'

const COMMENT_GROUP = '.card-modal__composer, .card-modal__reply-compose, .card-modal__comment-main'

export function observeCommentViewport(root, view = window) {
	const viewport = view.visualViewport
	if (!viewport) return () => {}
	const doc = root.ownerDocument
	let baselineHeight = view.innerHeight
	let layoutWidth = view.innerWidth
	let frame = null
	let restoreContainer = null
	let activeGroup = null

	function clear() {
		restoreContainer?.()
		restoreContainer = null
		activeGroup?.classList.remove('card-modal__comment-viewport')
		activeGroup?.style.removeProperty('--kanso-comment-visible-height')
		activeGroup = null
	}

	function fitContainer() {
		const container = root.closest('.modal-container')
		if (!container || restoreContainer) return
		const properties = ['height', 'max-height', 'top', 'position']
		const previous = properties.map((property) => [property, container.style.getPropertyValue(property), container.style.getPropertyPriority(property)])
		restoreContainer = () => {
			for (const [property, value, priority] of previous) {
				if (value) container.style.setProperty(property, value, priority)
				else container.style.removeProperty(property)
			}
		}
	}

	function update() {
		frame = null
		if (view.innerWidth !== layoutWidth) {
			layoutWidth = view.innerWidth
			baselineHeight = view.innerHeight
		} else {
			baselineHeight = Math.max(baselineHeight, view.innerHeight)
		}
		const editor = doc.activeElement
		const group = root.contains(editor) ? editor.closest(COMMENT_GROUP) : null
		if (!group || view.innerWidth > 680 || viewport.scale > 1.05) {
			clear()
			return
		}
		if (baselineHeight - viewport.height < 80) {
			clear()
			return
		}
		if (activeGroup !== group) {
			clear()
			activeGroup = group
			group.classList.add('card-modal__comment-viewport')
		}
		group.style.setProperty('--kanso-comment-visible-height', `${viewport.height}px`)
		fitContainer()
		const container = root.closest('.modal-container')
		if (container) {
			container.style.setProperty('height', `${viewport.height}px`, 'important')
			container.style.setProperty('max-height', `${viewport.height}px`, 'important')
			container.style.setProperty('top', `${viewport.offsetTop}px`, 'important')
			container.style.setProperty('position', 'fixed', 'important')
		}
		const selection = doc.getSelection?.()
		const editorScroller = editor.closest('.kanso-md-editor')
		if (editorScroller && selection?.rangeCount && editor.contains(selection.anchorNode)) {
			const caret = selection.getRangeAt(0).getBoundingClientRect()
			const bounds = editorScroller.getBoundingClientRect()
			if (caret.height > 0) {
				const delta = caret.bottom > bounds.bottom - 4
					? caret.bottom - bounds.bottom + 4
					: caret.top < bounds.top + 4 ? caret.top - bounds.top - 4 : 0
				editorScroller.scrollTop += delta
			}
		}
		group.scrollIntoView({ block: 'nearest', inline: 'nearest' })
		const rect = group.getBoundingClientRect()
		const top = viewport.offsetTop + 8
		const bottom = viewport.offsetTop + viewport.height - 8
		let delta = rect.bottom > bottom ? rect.bottom - bottom : rect.top < top ? rect.top - top : 0
		for (let parent = group.parentElement; parent && delta; parent = parent.parentElement) {
			if (!/(auto|scroll)/.test(view.getComputedStyle(parent).overflowY)) continue
			const previous = parent.scrollTop
			parent.scrollTop += delta
			delta -= parent.scrollTop - previous
		}
		if (delta) view.scrollBy(0, delta)
	}

	function schedule() {
		if (frame == null) frame = view.requestAnimationFrame(update)
	}

	const rootEvents = ['focusin', 'focusout', 'input']
	for (const event of rootEvents) root.addEventListener(event, schedule)
	doc.addEventListener('selectionchange', schedule)
	viewport.addEventListener('resize', schedule)
	viewport.addEventListener('scroll', schedule)
	view.addEventListener('resize', schedule)
	return () => {
		if (frame != null) view.cancelAnimationFrame(frame)
		for (const event of rootEvents) root.removeEventListener(event, schedule)
		doc.removeEventListener('selectionchange', schedule)
		viewport.removeEventListener('resize', schedule)
		viewport.removeEventListener('scroll', schedule)
		view.removeEventListener('resize', schedule)
		clear()
	}
}

export function useCommentViewport(root) {
	let stop
	onMounted(() => { if (root.value) stop = observeCommentViewport(root.value) })
	onBeforeUnmount(() => stop?.())
}
