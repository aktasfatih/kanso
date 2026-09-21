<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
	AssigneeAvatars - the one assignee avatar stack every card surface renders.

	It caps the visible avatars (3 by default) and, crucially, *says so*: every
	assignee past the cap is summed into a "+N" badge, so a card with four
	assignees never silently shows three. The kanban tile, the list row and the
	timeline pane all mount this component, which is the point - the list and
	timeline used to hand-roll the same cap without the badge, and quietly hid
	the 4th assignee (#10655). One component means the cap and the badge can no
	longer drift apart.

	The host positions the stack (margin/flex) via a class on this root element;
	spacing *inside* the stack (overlap, borders, badge) lives here.
-->
<template>
	<!-- A <span> root, not a <div>: the list row and the timeline pane mount this
	     inside inline containers. -->
	<span
		v-if="ids.length"
		class="assignee-stack"
		:aria-label="t('kanso', 'Assignees')">
		<NcAvatar
			v-for="uid in visibleIds"
			:key="uid"
			:user="uid"
			:size="size"
			:hide-status="true"
			:disable-tooltip="false"
			class="assignee-stack__avatar" />
		<span
			v-if="overflowCount > 0"
			class="assignee-stack__overflow"
			:style="overflowStyle">
			+{{ overflowCount }}
		</span>
	</span>
</template>

<script setup>
import { computed } from 'vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import { translate as t } from '@nextcloud/l10n'

const props = defineProps({
	/** Assignee uids, in board order. */
	assigneeIds: { type: Array, default: () => [] },
	/** Avatar diameter in px - the overflow badge matches it. */
	size: { type: Number, default: 24 },
	/** How many avatars render before the rest collapse into "+N". */
	max: { type: Number, default: 3 },
})

const ids = computed(() => (Array.isArray(props.assigneeIds) ? props.assigneeIds : []))

const visibleIds = computed(() => ids.value.slice(0, props.max))

const overflowCount = computed(() => Math.max(0, ids.value.length - props.max))

/* The badge is a plain span, so it has to be told the avatar's size - and the
 * label has to shrink with it or "+12" overflows a 20px circle. */
const overflowStyle = computed(() => ({
	width: `${props.size}px`,
	height: `${props.size}px`,
	fontSize: props.size >= 24 ? '0.65rem' : '0.6rem',
}))
</script>

<style scoped>
/* inline-flex, not flex: hosts mount this in both flex rows (the kanban tile's
 * meta row) and inline runs (the list row, the timeline pane). As a flex item it
 * behaves identically to flex; in an inline run it doesn't force a line break. */
.assignee-stack {
	display: inline-flex;
	align-items: center;
}

.assignee-stack__avatar {
	margin-left: -6px;
	flex-shrink: 0;
	aspect-ratio: 1;
	border: 2px solid var(--color-main-background);
	border-radius: 50%;
}

.assignee-stack .assignee-stack__avatar:first-child {
	margin-left: 0;
}

.assignee-stack__overflow {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	aspect-ratio: 1;
	border-radius: 50%;
	background: var(--color-background-dark);
	border: 2px solid var(--color-main-background);
	color: var(--color-text-maxcontrast);
	font-weight: 700;
	margin-left: -6px;
}
</style>
