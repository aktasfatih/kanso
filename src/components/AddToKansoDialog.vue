<!--
  - SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('kanso', 'Add to Kanso')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="add-to-kanso">
			<p class="add-to-kanso__intro">
				{{ t('kanso', 'Attach “{file}” to a card. A copy is stored on the card.', { file: fileName }) }}
			</p>
			<CardSearchPicker
				:disabled-card-ids="disabledCardIds"
				:error="error"
				@pick="onPick" />
			<p v-if="busy" class="add-to-kanso__busy">
				{{ t('kanso', 'Attaching…') }}
			</p>
		</div>
	</NcDialog>
</template>

<script setup>
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import CardSearchPicker from './CardSearchPicker.vue'
import { attachCardFileFromFiles } from '../services/api.js'

const props = defineProps({
	// The Files-app node id to copy onto a card.
	fileId: {
		type: Number,
		required: true,
	},
	// Display name of the file, for the intro copy.
	fileName: {
		type: String,
		default: '',
	},
})

const emit = defineEmits(['close'])

const open = ref(true)
const busy = ref(false)
const error = ref('')
// Cards already receiving this file this session, so a double-click can't
// double-attach; the picker renders them checked + non-selectable.
const disabledCardIds = ref(new Set())

function onUpdateOpen(value) {
	open.value = value
	if (!value) {
		emit('close')
	}
}

async function onPick(result) {
	if (busy.value || disabledCardIds.value.has(result.cardId)) return
	error.value = ''
	busy.value = true
	try {
		await attachCardFileFromFiles(result.cardId, props.fileId)
		disabledCardIds.value = new Set([...disabledCardIds.value, result.cardId])
		showSuccess(t('kanso', 'Added “{file}” to {card}', { file: props.fileName, card: result.title }))
		onUpdateOpen(false)
	} catch (err) {
		const message = err?.response?.data?.error || t('kanso', 'Could not attach the file.')
		error.value = message
		showError(message)
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.add-to-kanso {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px 4px;
	min-height: 120px;
}

.add-to-kanso__intro {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.add-to-kanso__busy {
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}
</style>
