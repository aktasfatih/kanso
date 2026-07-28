// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { ref, computed, nextTick } from 'vue'

/**
 * Reusable @-mention autocomplete for a single textarea.
 *
 * @param {object} opts
 * @param {() => string}            opts.getText     - getter returning the current text value
 * @param {(v: string) => void}     opts.setText     - setter to replace the text value
 * @param {import('vue').Ref}       opts.textareaRef - ref to the <textarea> DOM element
 * @param {() => Array<{uid: string, displayName: string}>} opts.getParticipants
 *   - getter returning participant list
 */
export function useMentionAutocomplete({ getText, setText, textareaRef, getParticipants }) {
	// Position in text where the active @-query starts (index of the '@')
	const queryStart = ref(-1)
	// The text after the '@' up to the caret
	const query = ref('')
	// Whether the dropdown is open
	const isOpen = ref(false)
	// Keyboard-highlighted row index
	const highlightedIndex = ref(0)

	// Filtered + sorted participant list (max 6), recomputed when query changes
	const matches = computed(() => {
		if (!isOpen.value) return []
		const q = query.value.toLowerCase()
		const list = getParticipants() ?? []
		return list
			.filter((p) =>
				p.uid.toLowerCase().includes(q)
				|| (p.displayName && p.displayName.toLowerCase().includes(q)),
			)
			.sort((a, b) => (a.displayName || a.uid).localeCompare(b.displayName || b.uid))
			.slice(0, 6)
	})

	/**
	 * Scan the text up to the caret and detect an active @-query.
	 * Rule: '@' must be at string start OR preceded by a non-word, non-'@' char.
	 * Followed by [a-zA-Z0-9_.-]* up to the caret.
	 */
	function detectQuery() {
		const el = textareaRef.value
		if (!el) return

		const pos = el.selectionStart
		const text = getText()
		if (pos === null || pos === undefined) return

		// Walk backwards from caret to find an '@'
		let i = pos - 1
		while (i >= 0 && /[a-zA-Z0-9_.-]/.test(text[i])) {
			i--
		}

		if (i >= 0 && text[i] === '@') {
			// Check boundary: i must be 0 or preceded by a non-word, non-'@' char
			const preceding = i > 0 ? text[i - 1] : null
			const validBoundary = preceding === null || !/[\w@]/.test(preceding)
			if (validBoundary) {
				const q = text.slice(i + 1, pos)
				queryStart.value = i
				query.value = q
				isOpen.value = true
				// Clamp highlighted index to valid range
				if (highlightedIndex.value >= matches.value.length) {
					highlightedIndex.value = 0
				}
				return
			}
		}

		// No active query
		close()
	}

	function close() {
		isOpen.value = false
		query.value = ''
		queryStart.value = -1
		highlightedIndex.value = 0
	}

	/**
	 * Insert the selected participant mention into the text.
	 * Replaces `@<query>` at queryStart with `@<uid> ` (trailing space).
	 */
	async function select(participant) {
		if (!isOpen.value || queryStart.value < 0) return
		const text = getText()
		const el = textareaRef.value
		const insertToken = '@' + participant.uid + ' '
		// Right boundary is derived from the state that produced the dropdown
		// (@ position + query length), NOT the live caret — the DOM caret can
		// drift (paste/composition) and slicing on it drops/dupes characters.
		const queryEnd = queryStart.value + 1 + query.value.length
		const newText = text.slice(0, queryStart.value) + insertToken + text.slice(queryEnd)
		const newCaret = queryStart.value + insertToken.length
		setText(newText)
		close()
		await nextTick()
		if (el) {
			el.focus()
			el.setSelectionRange(newCaret, newCaret)
		}
	}

	/**
	 * Keyboard handler to attach to the textarea's @keydown.
	 * Intercepts ArrowUp/ArrowDown/Enter/Tab/Escape when dropdown is open.
	 * When closed, is a complete no-op so normal textarea keys (Enter = newline) work.
	 */
	function onKeydown(event) {
		// Always track input changes on next tick to detect new '@' queries
		if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown'
			&& event.key !== 'Enter' && event.key !== 'Tab'
			&& event.key !== 'Escape') {
			// Schedule re-detection after value has updated
			nextTick(detectQuery)
			return
		}

		if (!isOpen.value) {
			// No menu open – don't intercept anything, let natural behaviour through
			return
		}

		const len = matches.value.length

		if (event.key === 'Escape') {
			event.preventDefault()
			close()
			return
		}

		if (event.key === 'ArrowDown') {
			event.preventDefault()
			highlightedIndex.value = len > 0 ? (highlightedIndex.value + 1) % len : 0
			return
		}

		if (event.key === 'ArrowUp') {
			event.preventDefault()
			highlightedIndex.value = len > 0 ? (highlightedIndex.value - 1 + len) % len : 0
			return
		}

		if (event.key === 'Enter' || event.key === 'Tab') {
			if (len === 0) return
			event.preventDefault()
			select(matches.value[highlightedIndex.value])
			return
		}
	}

	/**
	 * Call this on the textarea's @input event as well, so paste / composition
	 * events that don't fire keydown are still caught. Optional but recommended.
	 */
	function onInput() {
		nextTick(detectQuery)
	}

	return {
		isOpen,
		query,
		matches,
		highlightedIndex,
		select,
		onKeydown,
		onInput,
		close,
	}
}
