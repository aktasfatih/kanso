<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- `bulk-action-bar` is deliberately NOT renamed to match this component: it
	     is the selector ~20 e2e assertions across six spec files locate the bar
	     by, including mobile-pwa's `document.querySelector` geometry probe at
	     360x740. Renaming it is a test change, not a free rename. -->
	<div class="bulk-action-bar" role="toolbar" :aria-label="t('kanso', 'Bulk card actions')">
		<span class="bulk-action-bar__count">
			{{ t('kanso', '{count} selected', { count }) }}
		</span>

		<!-- The surface's own actions. The board fills this with the full bulk
		     toolbar (BulkActionBar); the Archived page fills it with the one action
		     that makes sense there (#10440). The shell is shared so a user who
		     learned multi-select on the board meets the same bar everywhere: same
		     position, same count, same way out. -->
		<slot />

		<!-- Close / exit mode. Icon-only at every width: a universally understood
		     glyph, and the width it saves is what keeps the rest reachable at 360px. -->
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
import NcButton from '@nextcloud/vue/components/NcButton'
import CloseIcon from 'vue-material-design-icons/Close.vue'

defineProps({
	/** Number of currently selected cards. */
	count: {
		type: Number,
		required: true,
	},
})

defineEmits(['close'])
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
	/* The bar is `position: fixed`, so anything wider than the viewport is
	   silently CLIPPED rather than scrollable — controls at both ends became
	   unreachable on a phone (#10287, measured at 502px against 360px). The
	   overflow menu is what keeps the content narrow; these two lines make the
	   box itself incapable of exceeding the viewport whatever it holds. */
	width: max-content;
	max-width: min(900px, 100vw);
	box-sizing: border-box;
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

/* Phone geometry: span the full width instead of centring a fixed box on it, and
   tighten the padding/gap so the count, the inline controls, the overflow menu
   and the close button all fit inside 360px. `flex-wrap` is a safety net only —
   the overflow menu is what does the work; wrapping is there so an unusually long
   translation eats a second row rather than clipping a control off-screen. */
@media (max-width: 480px) {
	.bulk-action-bar {
		left: 0;
		right: 0;
		transform: none;
		width: 100%;
		max-width: 100%;
		padding: 8px;
		gap: 4px;
		flex-wrap: wrap;
		border-radius: 0;
	}

	.bulk-action-bar__count {
		font-size: 0.85rem;
		padding-right: 4px;
		margin-right: 2px;
	}

	.bulk-action-bar__close {
		margin-left: 0;
	}
}
</style>
