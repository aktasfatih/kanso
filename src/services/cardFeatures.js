// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { computed, inject } from 'vue'

/**
 * Built-in card sections a board manager can switch off (#5894).
 *
 * The keys mirror `OCA\Kanso\Db\CardFeatures::ALL` exactly — the board payload
 * carries `board.cardFeatures` as a NORMALISED map ({contacts: true,
 * attachments: false, …}), so the client never parses the raw storage and a
 * board that predates the feature reads as all-enabled.
 *
 * Switching a feature off HIDES its UI and nothing else. Attachments, contact
 * links, GitHub links, time entries and cover colours are never deleted, a
 * running timer is not stopped, and old activity entries stay readable — so
 * re-enabling brings everything back exactly as it was.
 *
 * Enforcement is CLIENT-SIDE ONLY and deliberately so: the API still accepts an
 * upload or a timer start on a board whose switch is off. See the class
 * docblock on `lib/Db/CardFeatures.php` for why.
 */
export const CARD_FEATURE_KEYS = ['contacts', 'attachments', 'github', 'timeTracking', 'coverColor']

/** Every feature on — the shape used whenever a board hasn't said otherwise. */
export const ALL_CARD_FEATURES_ENABLED = Object.freeze(
	Object.fromEntries(CARD_FEATURE_KEYS.map((key) => [key, true])),
)

/**
 * Injection key for the board's feature map, provided by BoardView so tiles and
 * list rows reach it without threading a prop through four intermediate
 * components (same shape as NEST_ENABLED in ./cardNesting.js).
 */
export const CARD_FEATURES = Symbol('kanso:cardFeatures')

/**
 * Normalises whatever the board payload carried into a complete map. A missing
 * or malformed value means "everything enabled" — a feature must never
 * disappear because a payload was odd; only an explicit `false` hides one.
 *
 * @param {object|null|undefined} raw the board's `cardFeatures` value
 * @return {Record<string, boolean>} every known key, `true` = enabled
 */
export function normalizeCardFeatures(raw) {
	const map = {}
	for (const key of CARD_FEATURE_KEYS) {
		map[key] = raw?.[key] !== false
	}
	return map
}

/**
 * Reads the provided feature map inside a descendant of BoardView. Surfaces
 * with no provider — a cross-board View, the public share bundle — get
 * all-enabled, so gating is strictly opt-in per surface.
 *
 * @return {import('vue').ComputedRef<Record<string, boolean>>} the feature map
 */
export function useCardFeatures() {
	return inject(CARD_FEATURES, computed(() => ALL_CARD_FEATURES_ENABLED))
}
