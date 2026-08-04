<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
	Per-tile "options" overflow menu (#3642). One kebab (⋯) trigger per active
	board tile, consolidating the per-user pin toggle (#3632) and the
	move-to-folder actions (#3529) into a single corner control.

	Emits `toggle-pin` (parent owns the optimistic pin/unpin), `assign(groupId)`
	and `unassign` (parent owns the folder mutation + cache invalidation).
-->
<template>
	<!-- Icon-only kebab trigger so it stays a compact corner button and doesn't
	     overrun the board title; the label lives on aria-label. NcActions manages
	     its own click, so opening the menu never bubbles to the tile's open-board
	     hit area. -->
	<NcActions :aria-label="t('kanso', 'Board options')" :data-test="'board-options-menu-' + board.id">
		<template #icon>
			<DotsHorizontalIcon :size="20" />
		</template>

		<!-- Pin / Unpin (#3632) -->
		<NcActionButton
			:data-test="'toggle-pin-' + board.id"
			close-after-click
			@click="$emit('toggle-pin')">
			<template #icon>
				<StarIcon v-if="isPinned" :size="20" />
				<StarOutlineIcon v-else :size="20" />
			</template>
			{{ isPinned ? t('kanso', 'Unpin board') : t('kanso', 'Pin board') }}
		</NcActionButton>

		<NcActionSeparator />

		<!-- Move to folder (#3529) -->
		<NcActionCaption :name="t('kanso', 'Move to folder')" />
		<NcActionButton
			v-for="group in groups"
			:key="group.id"
			:data-test="'move-to-folder-' + group.id"
			close-after-click
			@click="$emit('assign', group.id)">
			<template #icon>
				<CheckIcon v-if="Number(board.groupId) === Number(group.id)" :size="20" />
				<FolderOutlineIcon v-else :size="20" />
			</template>
			{{ group.name }}
		</NcActionButton>
		<NcActionButton
			v-if="board.groupId !== null && board.groupId !== undefined"
			data-test="remove-from-folder"
			close-after-click
			@click="$emit('unassign')">
			<template #icon>
				<FolderRemoveOutlineIcon :size="20" />
			</template>
			{{ t('kanso', 'Remove from folder') }}
		</NcActionButton>
		<NcActionText v-if="groups.length === 0">
			{{ t('kanso', 'No folders yet') }}
		</NcActionText>
	</NcActions>
</template>

<script setup>
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcActionText from '@nextcloud/vue/components/NcActionText'
import DotsHorizontalIcon from 'vue-material-design-icons/DotsHorizontal.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import StarOutlineIcon from 'vue-material-design-icons/StarOutline.vue'
import FolderOutlineIcon from 'vue-material-design-icons/FolderOutline.vue'
import FolderRemoveOutlineIcon from 'vue-material-design-icons/FolderRemoveOutline.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'

const props = defineProps({
	board: { type: Object, required: true },
	groups: { type: Array, default: () => [] },
	// Force the pinned look regardless of payload (the dedicated Pinned section
	// only renders already-pinned boards).
	pinned: { type: Boolean, default: false },
})

defineEmits(['toggle-pin', 'assign', 'unassign'])

// A board is pinned when the payload says so, OR when the parent forces it.
const isPinned = computed(() => props.pinned || !!props.board.pinned)
</script>
