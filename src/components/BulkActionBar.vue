<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="bulk-action-bar" role="toolbar" :aria-label="t('kanso', 'Bulk card actions')">
		<span class="bulk-action-bar__count">
			{{ t('kanso', '{count} selected', { count }) }}
		</span>

		<!-- The bar is icon-only, so every control also carries a `title` for the
		     hover tooltip a sighted mouse user relies on (#10275). On NcActions
		     `title` is a fall-through attribute: it lands on the wrapping
		     `.action-item` element rather than the trigger button, which is still
		     what the browser resolves the tooltip from when the button itself has
		     none. `menuName` is deliberately NOT used — it nulls the trigger's
		     aria-label and widens the control. The data-driven menus bind their
		     hint through menuHint() so it disappears along with the menu; see
		     that helper for why. -->

		<!-- Move to stack -->
		<NcActions
			v-bind="menuHint(stacks, t('kanso', 'Move to…'))"
			:disabled="applying || count === 0"
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

		<!-- Add label -->
		<NcActions
			v-bind="menuHint(labels, t('kanso', 'Add label…'))"
			:disabled="applying || count === 0"
			:aria-label="t('kanso', 'Add label…')">
			<template #icon>
				<LabelIcon :size="20" />
			</template>
			<NcActionButton
				v-for="label in labels"
				:key="label.id"
				:close-after-click="true"
				@click="$emit('add-label', label.id)">
				<template #icon>
					<span
						class="bulk-action-bar__label-dot"
						:style="label.color ? { background: '#' + label.color } : {}" />
				</template>
				{{ label.title }}
			</NcActionButton>
		</NcActions>

		<!-- Remove label -->
		<NcActions
			v-bind="menuHint(labels, t('kanso', 'Remove label…'))"
			:disabled="applying || count === 0"
			:aria-label="t('kanso', 'Remove label…')">
			<template #icon>
				<LabelOffIcon :size="20" />
			</template>
			<NcActionButton
				v-for="label in labels"
				:key="label.id"
				:close-after-click="true"
				@click="$emit('remove-label', label.id)">
				<template #icon>
					<span
						class="bulk-action-bar__label-dot"
						:style="label.color ? { background: '#' + label.color } : {}" />
				</template>
				{{ label.title }}
			</NcActionButton>
		</NcActions>

		<!-- Assign user -->
		<NcActions
			v-bind="menuHint(participants, t('kanso', 'Assign…'))"
			:disabled="applying || count === 0"
			:aria-label="t('kanso', 'Assign…')">
			<template #icon>
				<AccountPlusIcon :size="20" />
			</template>
			<NcActionButton
				v-for="participant in participants"
				:key="participant.uid ?? participant.id"
				:close-after-click="true"
				@click="$emit('assign', participant.uid ?? participant.id)">
				{{ participant.displayName ?? (participant.uid ?? participant.id) }}
			</NcActionButton>
		</NcActions>

		<!-- Set due date — a fixed two-entry menu, so it never collapses and can
		     carry its hint directly. -->
		<NcActions
			:disabled="applying || count === 0"
			:title="t('kanso', 'Set due date…')"
			:aria-label="t('kanso', 'Set due date…')">
			<template #icon>
				<CalendarClockIcon :size="20" />
			</template>
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
		</NcActions>

		<!-- Mark done -->
		<NcButton
			type="tertiary"
			:disabled="applying || count === 0"
			:title="t('kanso', 'Mark done')"
			:aria-label="t('kanso', 'Mark done')"
			@click="$emit('set-status', 'done')">
			<template #icon>
				<CheckIcon :size="20" />
			</template>
		</NcButton>

		<!-- Archive -->
		<NcButton
			type="tertiary"
			:disabled="applying || count === 0"
			:title="t('kanso', 'Archive selected')"
			:aria-label="t('kanso', 'Archive selected')"
			@click="$emit('archive')">
			<template #icon>
				<ArchiveIcon :size="20" />
			</template>
		</NcButton>

		<!-- Delete -->
		<NcButton
			type="error"
			:disabled="applying || count === 0"
			:title="t('kanso', 'Delete selected')"
			:aria-label="t('kanso', 'Delete selected')"
			@click="$emit('delete')">
			<template #icon>
				<DeleteIcon :size="20" />
			</template>
		</NcButton>

		<!-- Close / exit mode -->
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
import { translate as t } from '@nextcloud/l10n'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionInput from '@nextcloud/vue/components/NcActionInput'
import NcButton from '@nextcloud/vue/components/NcButton'
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import LabelIcon from 'vue-material-design-icons/Label.vue'
import LabelOffIcon from 'vue-material-design-icons/LabelOff.vue'
import AccountPlusIcon from 'vue-material-design-icons/AccountPlus.vue'
import CalendarClockIcon from 'vue-material-design-icons/CalendarClock.vue'
import CalendarRemoveIcon from 'vue-material-design-icons/CalendarRemove.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import ArchiveIcon from 'vue-material-design-icons/Archive.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'

defineProps({
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
	max-width: 900px;
	width: max-content;
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
</style>
