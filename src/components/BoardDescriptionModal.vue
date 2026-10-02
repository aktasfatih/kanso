<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal
		:name="t('kanso', 'Board description')"
		size="normal"
		@close="$emit('close')">
		<div class="board-description">
			<!-- The BOARD's name as the heading, not a fixed "What this board is
			     for" line: the description is the author's own prose and very often
			     opens with its own heading, which read as a duplicate of ours
			     directly above it. The dialog already says "Board description". -->
			<header class="board-description__header">
				<h2 class="board-description__title">{{ boardTitle }}</h2>
			</header>

			<!-- The description AS WRITTEN: full markdown, so blank lines, lists and
			     headings survive. The modal is the only place it renders, which is
			     why there is no flattened or clipped variant anywhere — a board
			     description is reference material, read in full when it is read at
			     all (#173).

			     eslint-disable-next-line vue/no-v-html — renderMarkdown sanitises
			     via DOMPurify (markdown-it runs with html:false, so markup written
			     INTO a description is shown as the characters typed and nothing it
			     names reaches the page). -->
			<!-- eslint-disable-next-line vue/no-v-html -->
			<div class="board-description__body kanso-md" data-test="board-description" v-html="rendered" />

			<div v-if="canManage" class="board-description__actions">
				<NcButton data-test="board-description-edit" @click="$emit('edit')">
					<template #icon>
						<PencilIcon :size="20" />
					</template>
					{{ t('kanso', 'Edit description') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script setup>
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { renderMarkdown } from '../services/markdown.js'

const props = defineProps({
	/** The board's title, shown as the subtitle so the note is attributable. */
	boardTitle: { type: String, default: '' },
	/** Raw markdown source, straight off the board payload. */
	description: { type: String, default: '' },
	/** MANAGE on this board — gates the "Edit description" shortcut only. */
	canManage: { type: Boolean, default: false },
})

defineEmits(['close', 'edit'])

// No card-reference map is passed: a KAN-12 in a board blurb has no click
// handler on this surface, so it renders as the plain text that was typed
// rather than as a dead link.
const rendered = computed(() => renderMarkdown(props.description))
</script>

<style scoped>
.board-description {
	padding: 20px 24px 24px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	/* The modal body scrolls; a 4000-character description is allowed to be
	   long without the dialog growing past the viewport. */
	max-height: 70vh;
	overflow-y: auto;
	box-sizing: border-box;
}

.board-description__header {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.board-description__title {
	margin: 0;
	font-size: 1.1rem;
	font-weight: 700;
}

.board-description__body {
	min-width: 0;
	overflow-wrap: anywhere;
}

.board-description__body :deep(:first-child) {
	margin-block-start: 0;
}

.board-description__actions {
	display: flex;
	gap: 8px;
	margin-top: 4px;
}
</style>
