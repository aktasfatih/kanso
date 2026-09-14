// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Shared editor preferences — module-level reactive state so every editor
 * instance across the app reacts to changes from the settings dialog without
 * any prop-drilling or additional query layer.
 *
 * Seeded twice, on purpose (#10460): main.js sets it SYNCHRONOUSLY from the
 * server-rendered initial state before the app mounts, so the first editor paint
 * already matches the user's choice instead of flashing the toolbar; App.vue
 * then reconciles it from getSettings() on mount and updates it on each toggle.
 * CardDetail.vue reads it via :show-toolbar.
 */
import { ref } from 'vue'

/** true = toolbar hidden; false = toolbar shown (default). */
const editorToolbarHidden = ref(false)

function setEditorToolbarHidden(hidden) {
	editorToolbarHidden.value = !!hidden
}

export function useEditorPrefs() {
	return { editorToolbarHidden, setEditorToolbarHidden }
}
