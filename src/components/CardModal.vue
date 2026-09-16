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
		@update:show="onShowUpdate">
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
import { usePageTitle } from '../composables/usePageTitle.js'

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

// The open card owns the browser tab title (#125) for as long as the dialog is
// up. This component mounts INSIDE BoardView (nested card-modal route) or on top
// of a cross-board View, and usePageTitle is a stack — so the card title wins
// while it is open and the board's (or the View's) comes back on close, with
// neither side knowing about the other. `modalTitle` is empty until CardDetail
// reports the loaded card, which leaves the underlying title in place rather
// than flashing a placeholder.
usePageTitle(modalTitle)

// The X button mirrors an Escape at the card root: if an attribute popover is
// open, dismiss it first rather than closing the whole card. CardDetail owns that
// popover-first precedence (and the unsaved-work prompt), so route the X through it.
//
// Why `@update:show` and not `@close` (#146): NcModal's own close handler sets its
// `show` model to false immediately and only emits `close` 300ms later. Asking the
// user about unsaved work from `@close` therefore raised the prompt over a card that
// had ALREADY vanished — and cancelling it left the dialog hidden forever, because
// `show` is the literal `true` here and can never change back to un-hide it.
//
// `:show` + `@update:show` makes that binding a real two-way model: Vue's `useModel`
// only writes its own LOCAL value when the prop is passed WITHOUT an `onUpdate:show`
// listener (`hasVModel === false`). With this listener attached it just emits, the
// prop stays `true`, and NcModal never hides itself — this component decides. A
// cancelled `requestClose()` therefore leaves the card visible with zero flash, and
// a confirmed one navigates away and unmounts the whole dialog. That subtlety is why
// tests/e2e/card-unsaved-guard.spec.js pins the X-button path.
//
// The `setTimeout` is for the OTHER thing NcModal funnels in here: its Escape
// hotkey, which listens on `window` in the CAPTURE phase and so runs BEFORE
// CardDetail's own root Escape handler. Acting straight away would let the shell
// close the card out from under an Escape that CardDetail was about to spend on
// dismissing an open attribute popover. Yielding to the task queue (a microtask is
// NOT enough — the browser drains those between listeners, still mid-dispatch) puts
// this after the whole event has been dispatched, so CardDetail's handler goes first
// and requestClose() declines a keystroke it has already claimed. A mouse-driven
// close (the X, the backdrop) claims nothing, so it just runs a tick later.
function onShowUpdate(value) {
	if (value) {
		return
	}
	setTimeout(() => detailRef.value?.requestClose?.(), 0)
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
