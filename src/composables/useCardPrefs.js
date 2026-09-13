// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Shared card-view preferences (#10408) — module-level reactive state so every
 * open card view reacts to a change made in the settings dialog without any
 * prop-drilling or extra query layer. Mirrors useEditorPrefs.
 *
 * The value is a *server-side* per-user preference (NC user config, via
 * GET/PUT /api/settings), unlike the card view's split width and collapse flag
 * which stay in localStorage: the whole point of this one is that it follows the
 * user across devices. App.vue seeds it from getSettings() on mount and writes
 * it back on each toggle; CardDetail.vue reads it to pick its layout.
 */
import { computed, ref } from 'vue'

/** 'side' = panel beside the card (default, today's layout); 'bottom' = below it. */
const discussionPosition = ref('side')

/** Anything unrecognised degrades to today's layout rather than a broken one. */
function setDiscussionPosition(position) {
	discussionPosition.value = position === 'bottom' ? 'bottom' : 'side'
}

const discussionAtBottom = computed(() => discussionPosition.value === 'bottom')

export function useCardPrefs() {
	return { discussionAtBottom, setDiscussionPosition }
}
