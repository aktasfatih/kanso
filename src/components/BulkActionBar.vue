<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="bulk-action-bar" role="toolbar" :aria-label="t('kanso', 'Bulk card actions')">
		<span class="bulk-action-bar__count">
			{{ t('kanso', '{count} selected', { count }) }}
		</span>

		<!-- INLINE: the three highest-frequency bulk actions. Everything else lives
		     in the single "More" overflow below (#10287). Each inline control shows
		     its name as VISIBLE TEXT from `LABEL_BREAKPOINT` up, and falls back to
		     icon + title/aria-label on narrower viewports where the text cannot fit
		     — the bar has to hold the count, four controls and the close button
		     inside 360px, which is what the width bug was.

		     A data-driven menu with exactly ONE entry is rendered as our own button
		     rather than left to NcActions' collapsed form: collapsed, the library
		     names the control after the ENTRY ("Bug", "alice"), so a screen-reader
		     user on a personal board — exactly one participant by construction —
		     never hears which action the button performs. Naming it after both
		     ("Add label Bug") is the fix, and it keeps the one-tap behaviour the
		     collapsed form has. -->

		<!-- Move to stack.

		     `menu-name` and `aria-label` are BOTH set on purpose, and must stay
		     the same string. The library takes the accessible name from the
		     visible menu name and drops the aria-label whenever a menuName is
		     present (`aria-label: this.menuName ? null : this.ariaLabel`), so the
		     aria-label is what names the control at the icon-only width, where
		     menu-name is `undefined`. Letting the two texts drift would silently
		     change the accessible name at one width only. -->
		<NcActions
			v-if="stacks.length > 1"
			v-bind="menuHint(stacks, t('kanso', 'Move to…'))"
			:menu-name="showLabels ? t('kanso', 'Move to…') : undefined"
			:disabled="disabled"
			:aria-label="t('kanso', 'Move to…')">
			<template #icon>
				<ArrowRightIcon :size="20" />
			</template>
			<NcActionButton
				v-for="stack in stacks"
				:key="stack.id"
				:close-after-click="true"
				@click="$emit('move', stack.id)">
				{{ stack.title }}
			</NcActionButton>
		</NcActions>
		<NcButton
			v-else-if="stacks.length === 1"
			:type="inlineButtonType"
			:disabled="disabled"
			:title="oneStackLabel"
			:aria-label="oneStackLabel"
			@click="$emit('move', stacks[0].id)">
			<template #icon>
				<ArrowRightIcon :size="20" />
			</template>
			<template v-if="showLabels">
				{{ oneStackLabel }}
			</template>
		</NcButton>

		<!-- Add label -->
		<NcActions
			v-if="labels.length > 1"
			v-bind="menuHint(labels, t('kanso', 'Add label…'))"
			:menu-name="showLabels ? t('kanso', 'Add label…') : undefined"
			:disabled="disabled"
			:aria-label="t('kanso', 'Add label…')">
			<template #icon>
				<LabelIcon :size="20" />
			</template>
			<NcActionButton
				v-for="label in labels"
				:key="label.id"
				:close-after-click="true"
				:aria-label="t('kanso', 'Add label {label}', { label: label.title })"
				@click="$emit('add-label', label.id)">
				<template #icon>
					<span
						class="bulk-action-bar__label-dot"
						:style="label.color ? { background: '#' + label.color } : {}" />
				</template>
				{{ label.title }}
			</NcActionButton>
		</NcActions>
		<NcButton
			v-else-if="labels.length === 1"
			:type="inlineButtonType"
			:disabled="disabled"
			:title="oneLabelAddLabel"
			:aria-label="oneLabelAddLabel"
			@click="$emit('add-label', labels[0].id)">
			<!-- The label's own colour dot is what the menu ENTRIES carry; a
			     toolbar control keeps the label glyph instead, so that at the
			     icon-only width it still reads as "labels" rather than as an
			     unexplained coloured circle. The colour is one label's attribute,
			     the icon is the control's meaning. -->
			<template #icon>
				<LabelIcon :size="20" />
			</template>
			<template v-if="showLabels">
				{{ oneLabelAddLabel }}
			</template>
		</NcButton>

		<!-- Mark done -->
		<NcButton
			:type="inlineButtonType"
			:disabled="disabled"
			:title="t('kanso', 'Mark done')"
			:aria-label="t('kanso', 'Mark done')"
			@click="$emit('set-status', 'done')">
			<template #icon>
				<CheckIcon :size="20" />
			</template>
			<template v-if="showLabels">
				{{ t('kanso', 'Mark done') }}
			</template>
		</NcButton>

		<!-- OVERFLOW: everything else, in ONE menu. NcActions cannot nest, so the
		     pickers that moved in here (remove label, assign) are flattened into
		     captioned groups rather than sub-menus; each entry carries the full
		     action as its accessible name, so "Bug" under "Remove label" reads as
		     "Remove label Bug". -->
		<NcActions
			:disabled="disabled"
			:title="t('kanso', 'More actions')"
			:aria-label="t('kanso', 'More actions')">
			<template #icon>
				<DotsHorizontalIcon :size="20" />
			</template>

			<NcActionCaption v-if="labels.length" :name="t('kanso', 'Remove label')" />
			<NcActionButton
				v-for="label in labels"
				:key="'rm-' + label.id"
				:close-after-click="true"
				:aria-label="t('kanso', 'Remove label {label}', { label: label.title })"
				@click="$emit('remove-label', label.id)">
				<template #icon>
					<span
						class="bulk-action-bar__label-dot"
						:style="label.color ? { background: '#' + label.color } : {}" />
				</template>
				{{ label.title }}
			</NcActionButton>

			<NcActionCaption v-if="participants.length" :name="t('kanso', 'Assign')" />
			<NcActionButton
				v-for="participant in participants"
				:key="'assign-' + (participant.uid ?? participant.id)"
				:close-after-click="true"
				:aria-label="t('kanso', 'Assign to {user}', { user: participantName(participant) })"
				@click="$emit('assign', participant.uid ?? participant.id)">
				<template #icon>
					<AccountPlusIcon :size="20" />
				</template>
				{{ participantName(participant) }}
			</NcActionButton>

			<NcActionCaption :name="t('kanso', 'Due date')" />
			<NcActionInput
				type="date"
				:label="t('kanso', 'Due date')"
				:label-outside="false"
				@submit="onDueDateSubmit">
				<template #icon>
					<CalendarClockIcon :size="20" />
				</template>
				{{ t('kanso', 'Set due date') }}
			</NcActionInput>
			<NcActionButton
				:close-after-click="true"
				@click="$emit('set-due', '')">
				<template #icon>
					<CalendarRemoveIcon :size="20" />
				</template>
				{{ t('kanso', 'Clear due date') }}
			</NcActionButton>

			<NcActionSeparator />
			<NcActionButton
				:close-after-click="true"
				@click="$emit('archive')">
				<template #icon>
					<ArchiveIcon :size="20" />
				</template>
				{{ t('kanso', 'Archive selected') }}
			</NcActionButton>
			<NcActionButton
				:close-after-click="true"
				@click="$emit('delete')">
				<template #icon>
					<DeleteIcon :size="20" />
				</template>
				{{ t('kanso', 'Delete selected') }}
			</NcActionButton>
		</NcActions>

		<!-- Close / exit mode. Icon-only at every width: a universally understood
		     glyph, and the width it saves is what keeps the rest reachable at 360px. -->
		<NcButton
			type="tertiary"
			:title="t('kanso', 'Exit selection mode')"
			:aria-label="t('kanso', 'Exit selection mode')"
			class="bulk-action-bar__close"
			@click="$emit('close')">
			<template #icon>
				<CloseIcon :size="20" />
			</template>
		</NcButton>
	</div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActionInput from '@nextcloud/vue/components/NcActionInput'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcButton from '@nextcloud/vue/components/NcButton'
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import LabelIcon from 'vue-material-design-icons/Label.vue'
import AccountPlusIcon from 'vue-material-design-icons/AccountPlus.vue'
import CalendarClockIcon from 'vue-material-design-icons/CalendarClock.vue'
import CalendarRemoveIcon from 'vue-material-design-icons/CalendarRemove.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import ArchiveIcon from 'vue-material-design-icons/Archive.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import DotsHorizontalIcon from 'vue-material-design-icons/DotsHorizontal.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'

const props = defineProps({
	/** Number of currently selected cards. */
	count: {
		type: Number,
		required: true,
	},
	/** Array of stack objects {id, title}. */
	stacks: {
		type: Array,
		default: () => [],
	},
	/** Array of label objects {id, title, color}. */
	labels: {
		type: Array,
		default: () => [],
	},
	/** Array of participant objects {id/uid, displayName}. */
	participants: {
		type: Array,
		default: () => [],
	},
	/** Whether a bulk action is currently in flight. */
	applying: {
		type: Boolean,
		default: false,
	},
})

// Width from which every inline control shows its name as visible text. Chosen
// to clear the longest translated set with room to spare (the German strings are
// ~30% wider than the English ones) rather than to match a device class — below
// it the same controls stay reachable as icons with their title/aria-label.
// This is a separate concern from the `@media (max-width: 480px)` block in the
// <style> below, which tightens the bar's own geometry on a phone: labels stop
// being affordable well before the padding does, so the two thresholds differ on
// purpose and neither derives from the other.
//
// Driven by a viewport media query rather than by a ResizeObserver on the element
// (BoardView's header does the latter): this bar is `position: fixed` and sized by
// its own content, so its width is an OUTPUT of this decision, not an input — the
// viewport is the only width that constrains it.
const LABEL_BREAKPOINT = 768

const showLabels = ref(true)
let mediaQuery = null
const onBreakpointChange = (event) => { showLabels.value = event.matches }

onMounted(() => {
	if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
		return
	}
	mediaQuery = window.matchMedia(`(min-width: ${LABEL_BREAKPOINT}px)`)
	showLabels.value = mediaQuery.matches
	mediaQuery.addEventListener('change', onBreakpointChange)
})

onBeforeUnmount(() => {
	mediaQuery?.removeEventListener('change', onBreakpointChange)
})

const disabled = computed(() => props.applying || props.count === 0)

// NcActions renders its trigger as `secondary` once it carries a visible
// `menuName` and as `tertiary` while it is icon-only. The plain buttons beside it
// follow the same rule so the inline row reads as one set of controls at both
// widths.
const inlineButtonType = computed(() => (showLabels.value ? 'secondary' : 'tertiary'))

/**
 * Display name of a participant, falling back to the uid when the directory
 * lookup produced none.
 *
 * @param {object} participant participant record from the board
 * @return {string} name to show
 */
function participantName(participant) {
	return participant.displayName ?? (participant.uid ?? participant.id)
}

// Single-entry forms: name the ACTION and the entry, so the control is not just
// called "Bug"/"alice" (see the template comment above).
const oneStackLabel = computed(() => t('kanso', 'Move to {stack}', { stack: props.stacks[0]?.title ?? '' }))
const oneLabelAddLabel = computed(() => t('kanso', 'Add label {label}', { label: props.labels[0]?.title ?? '' }))

/**
 * Hover hint for one of the data-driven menu triggers, bound with `v-bind` so
 * the attribute is ABSENT rather than empty when it must not apply.
 *
 * NcActions renders nothing at all for an empty menu, and collapses to a single
 * button that acts immediately when it holds exactly one entry. In that
 * collapsed form the library already sets a better `title` — the entry's own
 * name ("Bug", "Alice") — and a menu label ending in "…" would promise a picker
 * that never opens. Passing `title: null` would not help: a fall-through
 * attribute is merged unconditionally, so it would wipe the library's title
 * instead of leaving it alone. Hence an object with or without the key.
 *
 * @param {Array} items entries the menu will render
 * @param {string} label hint to show while it really is a menu
 * @return {object} `{ title }` for a real menu, `{}` otherwise
 */
function menuHint(items, label) {
	return items.length > 1 ? { title: label } : {}
}

const emit = defineEmits(['move', 'add-label', 'remove-label', 'assign', 'set-due', 'set-status', 'archive', 'delete', 'close'])

function onDueDateSubmit(value) {
	if (!value) return
	const iso = new Date(value + 'T12:00:00Z').toISOString()
	emit('set-due', iso)
}
</script>

<style scoped>
.bulk-action-bar {
	position: fixed;
	bottom: 0;
	left: 50%;
	transform: translateX(-50%);
	z-index: 2000;
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 20px;
	background: var(--color-main-background);
	border-top: 2px solid var(--color-primary-element);
	border-radius: var(--border-radius-large) var(--border-radius-large) 0 0;
	box-shadow: 0 -2px 16px rgba(0, 0, 0, 0.12);
	/* The bar is `position: fixed`, so anything wider than the viewport is
	   silently CLIPPED rather than scrollable — controls at both ends became
	   unreachable on a phone (#10287, measured at 502px against 360px). The
	   overflow menu is what keeps the content narrow; these two lines make the
	   box itself incapable of exceeding the viewport whatever it holds. */
	width: max-content;
	max-width: min(900px, 100vw);
	box-sizing: border-box;
}

.bulk-action-bar__count {
	font-weight: 600;
	font-size: 0.9rem;
	color: var(--color-main-text);
	white-space: nowrap;
	padding-right: 8px;
	border-right: 1px solid var(--color-border);
	margin-right: 4px;
}

.bulk-action-bar__close {
	margin-left: 4px;
}

.bulk-action-bar__label-dot {
	display: inline-block;
	width: 14px;
	height: 14px;
	border-radius: 50%;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	flex-shrink: 0;
}

/* Phone geometry: span the full width instead of centring a fixed box on it, and
   tighten the padding/gap so the count, the inline controls, the overflow menu
   and the close button all fit inside 360px. `flex-wrap` is a safety net only —
   the overflow menu is what does the work; wrapping is there so an unusually long
   translation eats a second row rather than clipping a control off-screen. */
@media (max-width: 480px) {
	.bulk-action-bar {
		left: 0;
		right: 0;
		transform: none;
		width: 100%;
		max-width: 100%;
		padding: 8px;
		gap: 4px;
		flex-wrap: wrap;
		border-radius: 0;
	}

	.bulk-action-bar__count {
		font-size: 0.85rem;
		padding-right: 4px;
		margin-right: 2px;
	}

	.bulk-action-bar__close {
		margin-left: 0;
	}
}
</style>
