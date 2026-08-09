<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
	Per-tile "options" overflow menu (#3642). One kebab (⋯) trigger per board
	tile, consolidating the per-user pin toggle (#3632), the move-to-folder
	actions (#3529) and the heavyweight board operations (#3750): duplicate,
	export, archive/unarchive and delete.

	Purely presentational — every entry emits and the parent owns the mutation
	(and the delete confirm step): `toggle-pin`, `assign(groupId)`, `unassign`,
	`duplicate(withCards)`, `export`, `archive`, `unarchive`, `delete`.

	Entry gating mirrors BoardSettingsModal: Export/Duplicate are READ-gated
	server-side so they show for everyone; Archive/Unarchive/Delete are
	MANAGE-only (`board.permissions` bitmask from the boards-list payload).
	On archived tiles the pin/folder block is hidden — both are active-view
	concepts — and Archive flips to Unarchive.
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

		<template v-if="!archived">
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

			<NcActionSeparator />
		</template>

		<!-- Duplicate / Export (#3750) — READ-gated server-side, shown to all. -->
		<NcActionButton
			:data-test="'tile-duplicate-with-cards-' + board.id"
			close-after-click
			@click="$emit('duplicate', true)">
			<template #icon>
				<ContentCopyIcon :size="20" />
			</template>
			{{ t('kanso', 'Duplicate with cards') }}
		</NcActionButton>
		<NcActionButton
			:data-test="'tile-duplicate-empty-' + board.id"
			close-after-click
			@click="$emit('duplicate', false)">
			<template #icon>
				<ContentDuplicateIcon :size="20" />
			</template>
			{{ t('kanso', 'Duplicate without cards') }}
		</NcActionButton>
		<NcActionButton
			:data-test="'tile-export-' + board.id"
			close-after-click
			@click="$emit('export')">
			<template #icon>
				<DownloadIcon :size="20" />
			</template>
			{{ t('kanso', 'Export board') }}
		</NcActionButton>

		<!-- Archive / Delete (#3750) — MANAGE only, destructive last. -->
		<template v-if="canManage">
			<NcActionSeparator />

			<NcActionButton
				v-if="archived"
				:data-test="'tile-unarchive-' + board.id"
				close-after-click
				@click="$emit('unarchive')">
				<template #icon>
					<ArchiveArrowUpIcon :size="20" />
				</template>
				{{ t('kanso', 'Unarchive board') }}
			</NcActionButton>
			<NcActionButton
				v-else
				:data-test="'tile-archive-' + board.id"
				close-after-click
				@click="$emit('archive')">
				<template #icon>
					<ArchiveArrowDownIcon :size="20" />
				</template>
				{{ t('kanso', 'Archive board') }}
			</NcActionButton>

			<NcActionButton
				:data-test="'tile-delete-' + board.id"
				close-after-click
				@click="$emit('delete')">
				<template #icon>
					<DeleteOutlineIcon :size="20" />
				</template>
				{{ t('kanso', 'Delete board') }}
			</NcActionButton>
		</template>
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
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import ContentDuplicateIcon from 'vue-material-design-icons/ContentDuplicate.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import ArchiveArrowDownIcon from 'vue-material-design-icons/ArchiveArrowDown.vue'
import ArchiveArrowUpIcon from 'vue-material-design-icons/ArchiveArrowUp.vue'
import DeleteOutlineIcon from 'vue-material-design-icons/DeleteOutline.vue'

const PERM_MANAGE = 8

const props = defineProps({
	board: { type: Object, required: true },
	groups: { type: Array, default: () => [] },
	// Force the pinned look regardless of payload (the dedicated Pinned section
	// only renders already-pinned boards).
	pinned: { type: Boolean, default: false },
	// Archived-section tile: hides the pin/folder block, flips Archive→Unarchive.
	archived: { type: Boolean, default: false },
})

defineEmits(['toggle-pin', 'assign', 'unassign', 'duplicate', 'export', 'archive', 'unarchive', 'delete'])

// A board is pinned when the payload says so, OR when the parent forces it.
const isPinned = computed(() => props.pinned || !!props.board.pinned)

// Manager-only entries (archive/delete) gate on the boards-list payload's
// permission bitmask (#3750).
const canManage = computed(() => (Number(props.board.permissions ?? 0) & PERM_MANAGE) !== 0)
</script>
