<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
	Per-board "move to folder" menu (#3529). Lists the user's folders; picking one
	files the board there, "Remove from folder" un-files it. Emits `assign(groupId)`
	/ `unassign` - the parent owns the mutation + cache invalidation.
-->
<template>
	<!-- Icon-only trigger (no menu-name) so it stays a compact corner button and
	     doesn't overrun the board title; the label lives on aria-label + the caption. -->
	<NcActions :aria-label="t('kanso', 'Move to folder')" :data-test="'board-folder-menu-' + board.id">
		<template #icon>
			<FolderMoveOutlineIcon :size="20" />
		</template>
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
import { translate as t } from '@nextcloud/l10n'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActionText from '@nextcloud/vue/components/NcActionText'
import FolderMoveOutlineIcon from 'vue-material-design-icons/FolderMoveOutline.vue'
import FolderOutlineIcon from 'vue-material-design-icons/FolderOutline.vue'
import FolderRemoveOutlineIcon from 'vue-material-design-icons/FolderRemoveOutline.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'

defineProps({
	board: { type: Object, required: true },
	groups: { type: Array, default: () => [] },
})

defineEmits(['assign', 'unassign'])
</script>
