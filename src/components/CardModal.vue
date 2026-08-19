<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
	Thin dialog shell around the shared <CardDetail> (#3817).

	This component owns nothing but the modal chrome: the NcModal overlay, its
	accessible name, and the width override for the two-pane layout. All card
	content, data fetching, optimistic mutations, realtime patching and permission
	gating live in CardDetail, which the full-page CardPage shell renders too - so
	the modal and the page can never drift apart.

	Rendered as the nested /board/:id/card/:cardId child route on BoardView, exactly
	as before the extraction. CardDetail reads the board id from route.params.id here.
-->
<template>
	<NcModal
		:show="true"
		:name="modalName"
		size="large"
		class="card-modal-modal"
		@close="onModalClose">
		<CardDetail
			ref="detailRef"
			mode="modal"
			:card-id="cardId"
			:controlled="controlled"
			:board-id="boardId"
			@update:title="modalTitle = $event"
			@navigate="$emit('navigate', $event)"
			@close="onDetailClose" />
	</NcModal>
</template>

<script setup>
import { ref, computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcModal from '@nextcloud/vue/components/NcModal'
import CardDetail from './CardDetail.vue'

defineProps({
	cardId: {
		type: String,
		required: true,
	},
	// Controlled mode (#3950): rendered as an in-place overlay by a parent that owns
	// the open/close state (a cross-board View), NOT via the nested card-modal route.
	// CardDetail then closes by emitting `close` with no router navigation, so the
	// parent's URL/surface is preserved.
	controlled: {
		type: Boolean,
		default: false,
	},
	// Explicit board id for the controlled overlay — the View has no board id in its
	// URL, so the parent passes the card's own boardId. Ignored by the routed variant
	// (which reads route.params.id), so it's null there.
	boardId: {
		type: [String, Number],
		default: null,
	},
})

const emit = defineEmits(['close', 'navigate'])

const detailRef = ref(null)
const modalTitle = ref('')
const modalName = computed(() => modalTitle.value || t('kanso', 'Card'))

// The X button (NcModal @close) mirrors an Escape at the card root: if an
// attribute popover is open, dismiss it first rather than closing the whole card.
// CardDetail owns that popover-first precedence, so route the X through it.
function onModalClose() {
	detailRef.value?.requestClose?.()
}

// CardDetail decided the card should actually close (Escape with no popover open,
// backdrop click, or an action that removes the card). In the routed variant it
// already performed the navigation and nothing more is needed; in controlled mode
// it did NOT navigate, so bubble `close` up to the parent that owns the overlay.
function onDetailClose() {
	emit('close')
}
</script>

<!-- Widen the modal container for the two-pane card view (teleported outside
     scoped styles, so this block is intentionally global). -->
<style>
.card-modal-modal .modal-container,
.modal-container.card-modal-modal {
	width: min(1180px, 94vw) !important;
	max-width: min(1180px, 94vw) !important;
}
</style>
