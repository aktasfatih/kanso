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
	     inside inline containers. role="group" is load-bearing, not decoration: a
	     bare <span> maps to role="generic", and ARIA PROHIBITS aria-label on
	     generic, so without the role the label below is computed away and the
	     stack ships with no accessible name at all. Not role="img" - that would
	     collapse the stack into one label and hide the individual assignee names. -->
	<span
		v-if="ids.length"
		class="assignee-stack"
		role="group"
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
 * label has to shrink with it or "+12" crowds a 20px circle.
 *
 * min-width, NOT width: the circle is sized in px (from `size`) while the label
 * is sized in rem, so they do not scale together - raise the browser/OS font
 * size for accessibility and a fixed-width circle spills its text sideways over
 * the neighbouring avatar (which sits at margin-left: -6px). A min-width lets
 * the badge grow into a pill instead of overflowing, while still rendering as a
 * plain circle at the ordinary "+1".."+9" widths. The label stays in rem so it
 * keeps honouring the user's font preference; pinning it to px would hide this
 * overflow by ignoring that preference, which is the worse a11y trade. */
const overflowStyle = computed(() => ({
	minWidth: `${props.size}px`,
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
	/* No `aspect-ratio: 1` and no `border-radius: 50%`: both assume a fixed
	 * square. The width is content-driven above a `min-width` of one avatar
	 * diameter, so a 999px radius is what keeps it a circle at that minimum and
	 * a stadium (never an ellipse) once a wide label pushes it out. box-sizing
	 * is stated rather than inherited so the min-width IS the avatar diameter,
	 * border and padding included, and the stack keeps its rhythm.
	 *
	 * 1px of side padding, measured in the app rather than guessed: at
	 * Nextcloud's 15px root the widest label leaves ~1.8px of slack per side on
	 * the smallest (compact, 20px) avatar, so 1px is the value that keeps every
	 * default-font badge - "+9" everywhere, and "+12" at sizes 22 and 24 - a
	 * plain circle, while still giving the label a gutter on the sizes where it
	 * genuinely has to expand. */
	box-sizing: border-box;
	padding: 0 1px;
	border-radius: 999px;
	background: var(--color-background-dark);
	border: 2px solid var(--color-main-background);
	color: var(--color-text-maxcontrast);
	font-weight: 700;
	margin-left: -6px;
}
</style>
