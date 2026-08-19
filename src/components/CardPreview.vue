<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!--
	CardPreview - a macOS Quick Look style floating, read-only peek of a card.

	Opened by pressing Space while a card is hovered (mouse) or keyboard-focused
	(arrow/hjkl nav) on the board. Anchored near the originating tile. Content is
	cache-first: all meta (title, human id, labels, assignees, priority, due,
	checklist progress) comes straight from the board summary card already in the
	cache; only the markdown description is lazily fetched via useCard(cardId) when
	the preview opens (reusing the same query cache the modal uses).

	Read-only: no field is editable here. An "Open" affordance (Enter, or a click
	on the button/anywhere in the panel body except a link) routes to the full
	card modal. Escape / click-away / mouse-leave / Space-again dismiss it (the
	parent BoardView owns the toggle + the outside-dismiss wiring).
-->
<template>
	<div
		ref="panelRef"
		class="card-preview"
		:style="panelStyle"
		role="dialog"
		:aria-label="t('kanso', 'Card preview')"
		@mouseleave="$emit('close')"
		@click="onPanelClick">
		<!-- Header: human id + title -->
		<div class="card-preview__head">
			<span v-if="cardHumanId" class="card-preview__ref">{{ cardHumanId }}</span>
			<h3 class="card-preview__title">{{ card.title }}</h3>
		</div>

		<!-- Meta row: labels · priority · due · checklist · assignees (cache-first) -->
		<div v-if="hasMeta" class="card-preview__meta">
			<!-- Labels -->
			<span
				v-for="label in cardLabels"
				:key="label.id"
				class="card-preview__label-chip"
				:class="{ 'card-preview__label-chip--no-color': !label.color }"
				:style="label.color ? { background: cssColor(label.color), color: readableColor(label.color) } : {}"
				:title="label.title">
				{{ label.title }}
			</span>

			<!-- Priority -->
			<span
				v-if="card.priority > 0"
				class="card-preview__priority"
				:class="`card-preview__priority--${card.priority}`">
				<FlagIcon :size="12" />
				{{ priorityLabel }}
			</span>

			<!-- Due date -->
			<span v-if="card.duedate" class="card-preview__due" :class="dueDateClass">
				<CalendarIcon :size="13" />
				{{ dueLabel }}
			</span>

			<!-- Checklist progress -->
			<span
				v-if="card.checklist && card.checklist.total > 0"
				class="card-preview__checklist"
				:class="{ 'card-preview__checklist--complete': card.checklist.done === card.checklist.total }">
				<CheckboxMarkedOutlineIcon :size="12" />
				{{ card.checklist.done }}/{{ card.checklist.total }}
			</span>

			<!-- Assignees (pushed right) -->
			<div v-if="assigneeIds.length" class="card-preview__assignees" :aria-label="t('kanso', 'Assignees')">
				<NcAvatar
					v-for="uid in assigneeIds"
					:key="uid"
					:user="uid"
					:display-name="participantName(uid)"
					:size="22"
					:hide-status="true"
					:disable-tooltip="false"
					class="card-preview__avatar" />
			</div>
		</div>

		<!-- Description (markdown-rendered; lazily fetched via useCard) -->
		<div class="card-preview__desc">
			<div v-if="isLoading" class="card-preview__desc-loading">
				<div class="card-preview__sk" style="width:100%" />
				<div class="card-preview__sk" style="width:88%" />
				<div class="card-preview__sk" style="width:62%" />
			</div>
			<div
				v-else-if="renderedDescription"
				class="card-preview__desc-rendered"
				v-html="renderedDescription" />
			<p v-else class="card-preview__desc-empty">{{ t('kanso', 'No description.') }}</p>
		</div>

		<!-- Footer: Open affordance + hint -->
		<div class="card-preview__foot">
			<button type="button" class="card-preview__open" @click.stop="$emit('open')">
				<OpenInNewIcon :size="14" />
				{{ t('kanso', 'Open') }}
			</button>
			<span class="card-preview__hint">{{ t('kanso', 'Enter to open · Esc to close') }}</span>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import FlagIcon from 'vue-material-design-icons/Flag.vue'
import CheckboxMarkedOutlineIcon from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import { useCard } from '../composables/useCard.js'
import { renderMarkdown } from '../services/markdown.js'
import { cssColor, readableColor } from '../services/color.js'
import { humanId } from '../services/humanId.js'
import { PRIORITY_LEVELS } from '../composables/usePriority.js'

const props = defineProps({
	/** Board summary card (title, boardSeq, labelIds, assigneeIds, priority, duedate, checklist). */
	card: {
		type: Object,
		required: true,
	},
	/** Map<labelId, label> from the board payload. */
	labelsById: {
		type: Map,
		default: () => new Map(),
	},
	/** Board human-id prefix (e.g. "KAN"). */
	boardPrefix: {
		type: String,
		default: '',
	},
	/** Participant list from the board (for resolving assignee display names). */
	participants: {
		type: Array,
		default: () => [],
	},
	/** The originating tile's bounding rect, used to anchor the panel. */
	anchorRect: {
		type: Object,
		default: null,
	},
})

const emit = defineEmits(['close', 'open'])

const panelRef = ref(null)

// ── Lazy description fetch (cache-first) ──────────────────────────────────────
// useCard is enabled unconditionally here: the component only mounts once the
// preview is opened, so this is the "don't fetch until preview opens" gate. The
// query cache is shared with the modal, so a card opened before resolves instantly.
const cardId = computed(() => props.card.id)
const { data: cardDetail, isLoading } = useCard(cardId, true)

const renderedDescription = computed(() =>
	renderMarkdown(cardDetail.value?.description || ''),
)

// ── Cache-first meta (all from the board summary card) ────────────────────────
const cardHumanId = computed(() => humanId(props.boardPrefix, props.card.boardSeq))

const cardLabels = computed(() => {
	const ids = Array.isArray(props.card.labelIds) ? props.card.labelIds : []
	return ids.map((id) => props.labelsById.get(id)).filter(Boolean)
})

const assigneeIds = computed(() =>
	Array.isArray(props.card.assigneeIds) ? props.card.assigneeIds : [],
)

const hasMeta = computed(() =>
	cardLabels.value.length > 0
		|| props.card.priority > 0
		|| !!props.card.duedate
		|| (props.card.checklist && props.card.checklist.total > 0)
		|| assigneeIds.value.length > 0,
)

const priorityLabel = computed(() => {
	const level = PRIORITY_LEVELS.find((l) => l.value === Number(props.card.priority ?? 0))
	return level ? t('kanso', level.label) : ''
})

const dueLabel = computed(() => {
	if (!props.card.duedate) return ''
	const d = new Date(props.card.duedate)
	return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
})

const dueDateClass = computed(() => {
	if (!props.card.duedate) return ''
	if (Number(props.card.doneAt) > 0) return ''
	const due = new Date(props.card.duedate)
	const now = new Date()
	if (due < now) return 'card-preview__due--overdue'
	if ((due - now) / (1000 * 60 * 60) <= 24) return 'card-preview__due--soon'
	return ''
})

function participantName(uid) {
	const p = props.participants.find((x) => x.uid === uid)
	return p?.displayName || uid
}

// ── Positioning: anchor near the originating tile, clamped to the viewport ────
const PANEL_W = 380
const GAP = 8
const panelStyle = ref({})

function computePosition() {
	const r = props.anchorRect
	const vw = window.innerWidth
	const vh = window.innerHeight
	let left
	let top
	if (r) {
		// Prefer to the right of the tile; flip to the left if it would overflow.
		left = r.right + GAP
		if (left + PANEL_W > vw - GAP) {
			left = r.left - GAP - PANEL_W
		}
		// If it still overflows on the left, clamp inside the viewport.
		if (left < GAP) left = Math.max(GAP, Math.min(r.left, vw - PANEL_W - GAP))
		top = r.top
	} else {
		// No anchor - center horizontally near the top.
		left = Math.max(GAP, (vw - PANEL_W) / 2)
		top = 80
	}
	// Clamp vertically once the panel height is known (measured post-mount).
	const h = panelRef.value?.offsetHeight ?? 0
	if (h && top + h > vh - GAP) top = Math.max(GAP, vh - h - GAP)
	if (top < GAP) top = GAP
	panelStyle.value = {
		left: `${Math.round(left)}px`,
		top: `${Math.round(top)}px`,
		width: `${PANEL_W}px`,
	}
}

function onPanelClick(e) {
	// A click anywhere in the panel body opens the full card, EXCEPT on a link
	// inside the rendered description (let links behave as links). The Open button
	// stops propagation and emits open itself.
	if (e.target.closest('a')) return
	emit('open')
}

// Live preview that follows keyboard selection (#3908): when the parent feeds a
// new anchor rect (and swaps in the newly focused card), re-anchor the panel to
// the new tile. Re-clamp after nextTick so the height for the new card's meta is
// measured before the vertical clamp. Card id is watched too so a same-position
// swap still repositions if the rect object is reused.
watch(
	() => [props.anchorRect, props.card.id],
	async () => {
		computePosition()
		await nextTick()
		computePosition()
	},
)

onMounted(async () => {
	computePosition()
	// Re-clamp after the description skeleton/content changes the height.
	await nextTick()
	computePosition()
	// Reposition on viewport resize only. The peek is transient and dismisses on
	// Escape / Space / mouse-leave / click-away, so we deliberately DON'T chase
	// board scrolling — the anchor rect is a one-shot capture and a programmatic
	// keyboard-nav scroll settling right after open must not auto-dismiss it.
	window.addEventListener('resize', computePosition)
})

onBeforeUnmount(() => {
	window.removeEventListener('resize', computePosition)
})
</script>

<style scoped>
.card-preview {
	/* Legible error red for the overdue date + urgent priority chips. Mirrors
	 * CardTile (#3905): stock --color-error in light, brighter red under dark. */
	--kanso-error-legible: var(--color-error, #e30000);
	--kanso-error-legible-rgb: var(--color-error-rgb, 227, 0, 0);

	position: fixed;
	z-index: 2100;
	display: flex;
	flex-direction: column;
	max-height: 70vh;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	box-shadow: 0 8px 30px rgba(0, 0, 0, 0.28);
	overflow: hidden;
	cursor: pointer;
}

body.theme--dark .card-preview,
[data-theme-dark] .card-preview,
[data-themes*='dark'] .card-preview {
	--kanso-error-legible: #ff6b6b;
	--kanso-error-legible-rgb: 255, 107, 107;
}

@media (prefers-color-scheme: dark) {
	body.theme--default .card-preview,
	body:not(.theme--light):not(.theme--dark) .card-preview {
		--kanso-error-legible: #ff6b6b;
		--kanso-error-legible-rgb: 255, 107, 107;
	}
}

.card-preview__head {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 14px 16px 8px;
}

.card-preview__ref {
	font-size: 0.68rem;
	font-weight: 600;
	letter-spacing: 0.02em;
	color: var(--color-text-maxcontrast);
	font-family: var(--font-face-monospace, monospace);
}

.card-preview__title {
	margin: 0;
	font-size: 1.05rem;
	font-weight: 700;
	line-height: 1.35;
	color: var(--color-main-text);
	word-break: break-word;
}

.card-preview__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 5px;
	padding: 0 16px 10px;
}

.card-preview__label-chip {
	display: inline-block;
	max-width: 140px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 0.7rem;
	font-weight: 600;
	line-height: 1;
	padding: 3px 7px;
	border-radius: 10px;
	letter-spacing: 0.02em;
}

.card-preview__label-chip--no-color {
	background: var(--color-background-dark);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
}

.card-preview__priority,
.card-preview__due,
.card-preview__checklist {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	font-size: 0.72rem;
	border-radius: 10px;
	padding: 1px 7px;
	border: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
}

.card-preview__priority {
	font-weight: 600;
	border-color: currentColor;
}

.card-preview__priority--1 { color: #888; border-color: #888; background: rgba(136, 136, 136, 0.1); }
.card-preview__priority--2 { color: var(--color-primary-element, #0082c9); border-color: var(--color-primary-element, #0082c9); background: rgba(0, 130, 201, 0.1); }
.card-preview__priority--3 { color: #e07b00; border-color: #e07b00; background: rgba(224, 123, 0, 0.1); }
.card-preview__priority--4 { color: var(--kanso-error-legible); border-color: var(--kanso-error-legible); background: rgba(var(--kanso-error-legible-rgb), 0.1); }

.card-preview__due--overdue {
	color: var(--kanso-error-legible);
	border-color: var(--kanso-error-legible);
	background: rgba(var(--kanso-error-legible-rgb), 0.08);
}

.card-preview__due--soon {
	color: var(--color-warning, #f0a844);
	border-color: var(--color-warning, #f0a844);
	background: rgba(240, 168, 68, 0.08);
}

.card-preview__checklist--complete {
	color: var(--color-success, #46ba61);
	border-color: var(--color-success, #46ba61);
	background: rgba(70, 186, 97, 0.1);
}

.card-preview__assignees {
	display: flex;
	align-items: center;
	margin-left: auto;
}

.card-preview__avatar {
	margin-left: -6px;
	flex-shrink: 0;
	border: 2px solid var(--color-main-background);
	border-radius: 50%;
}

.card-preview__assignees .card-preview__avatar:first-child {
	margin-left: 0;
}

.card-preview__desc {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	padding: 4px 16px 14px;
	border-top: 1px solid var(--color-border);
}

.card-preview__desc-loading {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-top: 8px;
}

.card-preview__sk {
	height: 12px;
	border-radius: 4px;
	background: linear-gradient(90deg, var(--color-border) 25%, var(--color-background-hover) 50%, var(--color-border) 75%);
	background-size: 400px 100%;
	animation: cp-shimmer 1.4s infinite linear;
}

@keyframes cp-shimmer {
	0% { background-position: -400px 0; }
	100% { background-position: 400px 0; }
}

.card-preview__desc-rendered {
	font-size: 0.875rem;
	line-height: 1.5;
	color: var(--color-main-text);
	word-break: break-word;
}

.card-preview__desc-rendered :deep(p) { margin: 0.4em 0; }
.card-preview__desc-rendered :deep(h1),
.card-preview__desc-rendered :deep(h2),
.card-preview__desc-rendered :deep(h3) { margin: 0.6em 0 0.3em; }
.card-preview__desc-rendered :deep(ul),
.card-preview__desc-rendered :deep(ol) { margin: 0.4em 0; padding-left: 1.4em; }
.card-preview__desc-rendered :deep(code) {
	font-family: var(--font-face-monospace, monospace);
	background: var(--color-background-dark);
	padding: 1px 4px;
	border-radius: 4px;
	font-size: 0.85em;
}
.card-preview__desc-rendered :deep(pre) {
	background: var(--color-background-dark);
	padding: 8px 10px;
	border-radius: 6px;
	overflow-x: auto;
}
.card-preview__desc-rendered :deep(a) { color: var(--color-primary-element); }

.card-preview__desc-empty {
	margin: 6px 0 0;
	font-size: 0.85rem;
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

.card-preview__foot {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px 16px;
	border-top: 1px solid var(--color-border);
	background: var(--color-background-hover);
}

.card-preview__open {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	font-size: 0.8rem;
	font-weight: 600;
	padding: 4px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.card-preview__open:hover {
	border-color: var(--color-primary);
	background: var(--color-background-hover);
}

.card-preview__hint {
	font-size: 0.72rem;
	color: var(--color-text-maxcontrast);
}
</style>
