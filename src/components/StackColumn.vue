<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		ref="columnRef"
		class="stack-column"
		:class="{ 'stack-column--dragging': isStackDragging, 'stack-column--collapsed': collapsed }">
		<!-- Left / right stack drop indicators - same visual language as the card tile drop line -->
		<div v-if="stackDropEdge === 'left'" class="stack-column__drop-line stack-column__drop-line--left" />
		<div v-if="stackDropEdge === 'right'" class="stack-column__drop-line stack-column__drop-line--right" />

		<!-- Collapsed rail (#3677): a slim vertical strip showing the (rotated)
		     column title + card count. Clicking anywhere on it expands the column.
		     Rendered ALONGSIDE the full column (which is hidden via v-show below)
		     so the virtualizer and drop targets stay mounted the whole time. -->
		<button
			v-if="collapsed"
			type="button"
			class="stack-column__rail"
			:aria-label="t('kanso', 'Expand column {name}', { name: stack.title })"
			:title="t('kanso', 'Expand column')"
			@click="handleToggleCollapsed">
			<ChevronRightIcon class="stack-column__rail-chevron" :size="18" />
			<span class="stack-column__rail-count">{{ props.cards.length }}</span>
			<span class="stack-column__rail-title">{{ stack.title }}</span>
		</button>

		<!-- Column header - drag handle for stack reordering -->
		<div
			ref="headerRef"
			v-show="!collapsed"
			class="stack-column__header"
			:class="{ 'stack-column__header--colored': !!stack.color }"
			:style="stack.color ? { '--stack-color': cssColor(stack.color) } : {}">
			<div class="stack-column__header-row">
				<!-- Collapse toggle (#3677). A real button that stops propagation so a
				     click folds the column instead of starting the header drag. -->
				<button
					v-if="onToggleCollapsed"
					type="button"
					class="stack-column__collapse-btn"
					:aria-label="t('kanso', 'Collapse column')"
					:aria-expanded="!collapsed"
					:title="t('kanso', 'Collapse column')"
					@click.stop="handleToggleCollapsed"
					@pointerdown.stop
					@keydown.enter.stop>
					<ChevronLeftIcon :size="18" />
				</button>
				<input
					v-if="editingTitle"
					ref="titleInputRef"
					v-model="titleDraft"
					class="stack-column__title-input"
					:aria-label="t('kanso', 'Column name')"
					@keydown.enter.prevent="saveTitle"
					@keydown.esc.prevent="cancelEditTitle"
					@blur="onTitleBlur">
				<span
					v-else
					class="stack-column__title"
					:class="{ 'stack-column__title--editable': !!onRenameStack }"
					:role="onRenameStack ? 'button' : null"
					:tabindex="onRenameStack ? 0 : null"
					:title="onRenameStack ? t('kanso', 'Rename column') : null"
					@click="startEditTitle"
					@keydown.enter="startEditTitle">{{ stack.title }}</span>
				<!-- Plain card count / WIP text (mockup: "12" or "4 / 3"). The count/limit
				     text is kept here (not a pill) so the WIP figure stays visible; the
				     visual "over limit" cue is the meter bar + caption below. -->
				<span
					class="stack-column__badge"
					:class="{ 'stack-column__badge--over-limit': isOverLimit }">
					{{ wipBadgeText }}
				</span>
				<span
					v-if="stack.role > 0"
					class="stack-column__role-chip"
					:class="`stack-column__role-chip--${stack.role}`"
					:title="roleLabel(stack.role)">
					{{ roleLabel(stack.role) }}
				</span>
				<!-- Stack actions menu - rendered whenever at least one edit action is wired -->
			<NcActions
				v-if="onDeleteStack || onRenameStack || onSetRole || onSetWip"
				class="stack-column__actions"
				:force-menu="true"
				:aria-label="t('kanso', 'Column actions')">
				<!-- Rename -->
				<NcActionButton
					v-if="onRenameStack"
					:close-after-click="true"
					@click="startEditTitle">
					<template #icon>
						<PencilIcon :size="20" />
					</template>
					{{ t('kanso', 'Rename column') }}
				</NcActionButton>

				<!-- Set status / role -->
				<template v-if="onSetRole">
					<NcActionSeparator />
					<NcActionRadio
						v-for="[roleVal, roleText] in roleEntries"
						:key="roleVal"
						name="stack-role"
						:value="roleVal"
						:model-value="stack.role ?? 0"
						@update:model-value="handleSetRole(roleVal)">
						{{ roleText }}
					</NcActionRadio>
				</template>

				<!-- Set WIP limit -->
				<template v-if="onSetWip">
					<NcActionSeparator />
					<NcActionInput
						v-model="wipDraft"
						type="number"
						:placeholder="t('kanso', 'WIP limit (0 = none)')"
						:label="t('kanso', 'WIP limit')"
						:label-outside="false"
						@submit="handleSetWip">
						<template #icon>
							<ChevronRightIcon :size="20" />
						</template>
						{{ t('kanso', 'WIP limit') }}
					</NcActionInput>
				</template>

				<!-- Column colour -->
				<template v-if="onSetColor">
					<NcActionSeparator />
					<NcActionButton
						v-for="opt in stackColorOptions"
						:key="opt.hex"
						:close-after-click="true"
						@click="handleSetColor(opt.hex)">
						<template #icon>
							<span class="stack-column__swatch-box">
								<span class="stack-column__swatch" :style="{ background: cssColor(opt.hex) }" />
							</span>
						</template>
						{{ opt.name }}
					</NcActionButton>
					<NcActionButton
						v-if="stack.color"
						:close-after-click="true"
						@click="handleSetColor('')">
						<template #icon>
							<span class="stack-column__swatch-box">
								<span class="stack-column__swatch stack-column__swatch--none" />
							</span>
						</template>
						{{ t('kanso', 'No colour') }}
					</NcActionButton>
				</template>

				<!-- Delete -->
				<template v-if="onDeleteStack">
					<NcActionSeparator v-if="onRenameStack || onSetRole || onSetWip || onSetColor" />
					<NcActionButton
						:close-after-click="true"
						@click="handleDeleteStack">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
						{{ t('kanso', 'Delete column') }}
					</NcActionButton>
				</template>
			</NcActions>
			</div>

			<!-- WIP meter (mockup 1a): a 2px fill bar under the header. With a WIP
			     limit set, the fill tracks fill ratio (capped at 100%) and turns to
			     the warning colour once at/over the limit; without a limit it stays a
			     neutral track. Replaces the old count/limit pill badge. -->
			<div
				class="stack-column__wip-meter"
				:class="{ 'stack-column__wip-meter--over': isOverLimit, 'stack-column__wip-meter--at': isAtLimit && !isOverLimit }"
				role="presentation">
				<div
					v-if="hasWipLimit"
					class="stack-column__wip-fill"
					:style="{ width: wipFillPct + '%' }" />
			</div>
			<div v-if="isOverLimit" class="stack-column__wip-caption">
				{{ t('kanso', '{n} over the WIP limit', { n: cards.length - stack.wipLimit }) }}
			</div>
		</div>

		<!-- Inline card composer at TOP - signature rapid-entry UX -->
		<div v-show="!collapsed" class="card-composer-wrap">
			<form class="card-composer" @submit.prevent="submitCard">
				<input
					ref="composerInputRef"
					v-model="newCardTitle"
					class="card-composer__input"
					type="text"
					:placeholder="t('kanso', 'Add card…')"
					:disabled="isPending"
					@paste="onComposerPaste"
					@keydown.enter.prevent="submitCard" />
			</form>
			<!-- "+ from template" picker (#3409): lists the board's card templates
			     and creates a new card pre-filled from the chosen one. -->
			<NcActions
				v-if="onFetchTemplates && onCreateFromTemplate"
				class="card-composer__templates"
				:force-menu="true"
				:aria-label="t('kanso', 'New card from template')"
				@open="loadTemplates">
				<template #icon>
					<FileDocumentOutlineIcon :size="18" />
				</template>
				<NcActionCaption :name="t('kanso', 'From template')" />
				<NcActionButton
					v-for="tpl in templates"
					:key="tpl.id"
					:close-after-click="true"
					:disabled="isPending"
					@click="createFromTemplate(tpl.id)">
					<template #icon>
						<FileDocumentOutlineIcon :size="20" />
					</template>
					{{ tpl.title }}
				</NcActionButton>
				<NcActionText v-if="templatesLoaded && templates.length === 0">
					{{ t('kanso', 'No templates yet. Open any card and choose "Mark as template" from its actions menu.') }}
				</NcActionText>
				<!-- Discoverable entry to the board-scoped template manager (view /
				     edit / delete / create). Templates are hidden from the board, so
				     this menu is the way to find and manage them. -->
				<template v-if="onManageTemplates">
					<NcActionSeparator />
					<NcActionButton
						:close-after-click="true"
						@click="onManageTemplates">
						<template #icon>
							<CogOutlineIcon :size="20" />
						</template>
						{{ t('kanso', 'Manage templates…') }}
					</NcActionButton>
				</template>
			</NcActions>
		</div>
		<p v-if="composerError && !collapsed" class="card-composer__error">{{ composerError }}</p>

		<!--
			Card list - own scrollable element.
			This element is both the scroll container for TanStack Virtual and the
			column-level drop target / auto-scroll target.
		-->
		<div
			ref="cardListRef"
			class="stack-column__cards"
			:class="{ 'stack-column__cards--drop-over': isDropOver && cards.length === 0 }">

			<!--
				Empty stack placeholder - always present when there are no cards.
				Rendered ALONGSIDE (not instead of) the virtual-host so the scroll
				container keeps its height during the 0→1 card transition and the
				virtualizer never loses its scrollRect.
			-->
			<div v-if="cards.length === 0" class="stack-column__empty-placeholder">
				<span v-if="!collapsed" class="stack-column__empty-hint">
					{{ t('kanso', 'Drop cards here · press n') }}
				</span>
			</div>

			<!--
				Virtualized list: always in the DOM (no v-if guard) so that the
				virtualizer's ResizeObserver is never detached and reattached during
				the 0→1 card transition. When count=0 the virtual-host has height 0
				and renders no items - the placeholder above fills the space.
			-->
			<div
				class="stack-column__virtual-host"
				:style="{ height: virtualizer.getTotalSize() + 'px' }">
				<div
					v-for="vRow in virtualizer.getVirtualItems()"
					:key="cards[vRow.index].id"
					:ref="(el) => measureVirtualEl(el, vRow.index)"
					:data-index="vRow.index"
					class="stack-column__virtual-item"
					:style="{
						position: 'absolute',
						top: 0,
						left: 0,
						width: '100%',
						transform: 'translateY(' + vRow.start + 'px)',
					}">
					<CardTile
						:card="cards[vRow.index]"
						:labels-by-id="labelsById"
						:board-prefix="boardPrefix"
						:lane-key="laneKey"
						:compact="compact"
						:selection-mode="selectionMode"
						:selected="selectedIds.has(cards[vRow.index].id)"
						@click="openCard(cards[vRow.index].id)"
						@hover="(id) => onCardHover?.(id)"
						@select="(p) => onCardSelect?.(p)" />
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import { showUndo } from '@nextcloud/dialogs'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionRadio from '@nextcloud/vue/components/NcActionRadio'
import NcActionInput from '@nextcloud/vue/components/NcActionInput'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActionText from '@nextcloud/vue/components/NcActionText'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import CogOutlineIcon from 'vue-material-design-icons/CogOutline.vue'
import CardTile from './CardTile.vue'
import { cssColor } from '../services/color.js'
import { parseDueToken } from '../utils/dueTokens.js'
import { draggable, dropTargetForElements } from '@atlaskit/pragmatic-drag-and-drop/element/adapter'
import { combine } from '@atlaskit/pragmatic-drag-and-drop/combine'
import { autoScrollForElements } from '@atlaskit/pragmatic-drag-and-drop-auto-scroll/element'
import { attachClosestEdge, extractClosestEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge'
import { useVirtualizer } from '@tanstack/vue-virtual'

const props = defineProps({
	stack: {
		type: Object,
		required: true,
	},
	cards: {
		type: Array,
		default: () => [],
	},
	/**
	 * Async fn (stackId, title, duedate?, allDay?) → Promise - provided by parent
	 * BoardView. duedate/allDay carry an optional natural-date token (#3416).
	 */
	onCreateCard: {
		type: Function,
		required: true,
	},
	/**
	 * Async fn () → Promise<Card[]> - lazily fetches the board's card templates
	 * (#3409) when the composer's "from template" menu opens. When omitted, the
	 * "from template" affordance is hidden.
	 */
	onFetchTemplates: {
		type: Function,
		default: null,
	},
	/**
	 * Async fn (stackId, templateId) → Promise - creates a new card in this stack
	 * pre-filled from the chosen template. Required for the "from template" picker.
	 */
	onCreateFromTemplate: {
		type: Function,
		default: null,
	},
	/**
	 * Fn () → void - opens the board-scoped "Manage templates" surface. Wired only
	 * on the flat board (alongside the template picker); when omitted the "Manage
	 * templates…" entry is hidden.
	 */
	onManageTemplates: {
		type: Function,
		default: null,
	},
	/**
	 * Async fn (stackId) → Promise - called when the user deletes this stack.
	 * Returns the stack id so the parent can show an undo toast.
	 */
	onDeleteStack: {
		type: Function,
		default: null,
	},
	/**
	 * Async fn (stackId) → Promise - called when the user undoes a stack delete.
	 */
	onRestoreStack: {
		type: Function,
		default: null,
	},
	/**
	 * Async fn (stackId, title) → Promise - renames the column. When provided,
	 * the title becomes click-to-edit; omit it to render a read-only title.
	 */
	onRenameStack: {
		type: Function,
		default: null,
	},
	/** Map<labelId, label> from the board payload - passed down from BoardView */
	labelsById: {
		type: Map,
		default: () => new Map(),
	},
	/** Board human-id prefix (e.g. "KAN") - passed down to CardTile for the KAN-<n> badge. */
	boardPrefix: {
		type: String,
		default: '',
	},
	/**
	 * Swimlane key this column instance belongs to (#3406), or '' when swimlanes
	 * are off. Threaded into the column-level drop-target data and down to each
	 * CardTile so the BoardView monitor can keep drops within a lane (cross-lane
	 * drag = reassignment, a documented v1 stretch that is disabled).
	 */
	laneKey: {
		type: String,
		default: '',
	},
	/**
	 * Board's "new cards on top" preference. Governs which end of the stack a
	 * freshly created card lands on server-side; the multi-add loop uses it to
	 * decide the create order so the pasted lines read top-to-bottom in order.
	 */
	newCardsOnTop: {
		type: Boolean,
		default: false,
	},
	/**
	 * Async fn (stackId, role) → Promise - sets the column status/role.
	 * When provided, a role picker appears in the ⋯ menu.
	 */
	onSetRole: {
		type: Function,
		default: null,
	},
	/**
	 * Async fn (stackId, wipLimit) → Promise - sets the WIP limit (0 clears).
	 * When provided, a WIP limit input appears in the ⋯ menu.
	 */
	onSetWip: {
		type: Function,
		default: null,
	},
	/** Optional callback (stackId, color|'') → void — set/clear the column colour. */
	onSetColor: {
		type: Function,
		default: null,
	},
	/**
	 * Optional callback (cardId: number) → void - called when a card tile is
	 * clicked so BoardView can keep focusedCardId in sync with mouse navigation.
	 */
	onCardFocus: {
		type: Function,
		default: null,
	},
	/**
	 * Optional callback (cardId: number|null) → void - called on tile
	 * mouseenter (cardId) / mouseleave (null) so BoardView can track the
	 * hovered card for the Space quick-preview.
	 */
	onCardHover: {
		type: Function,
		default: null,
	},
	/** Whether multi-select mode is active - passed down to each CardTile. */
	selectionMode: {
		type: Boolean,
		default: false,
	},
	/** Set of selected card ids - passed down to each CardTile for highlight. */
	selectedIds: {
		type: Set,
		default: () => new Set(),
	},
	/**
	 * Optional callback ({ id, shiftKey }) → void - called when a card tile
	 * emits 'select' in multi-select mode.
	 */
	onCardSelect: {
		type: Function,
		default: null,
	},
	/**
	 * Whether this column is collapsed to a narrow rail (#3677). Per-user,
	 * view-only state owned by BoardView. When true the card list is hidden
	 * (via v-show + a CSS class — NOT v-if, so the virtualizer and drop targets
	 * stay mounted and the rail remains a valid drop target).
	 */
	collapsed: {
		type: Boolean,
		default: false,
	},
	/**
	 * (stackId) → void — toggle this column's collapsed state. When provided a
	 * collapse/expand button appears in the header; omit to disable collapsing.
	 */
	onToggleCollapsed: {
		type: Function,
		default: null,
	},
	/**
	 * Compact density (#3415): a per-user, view-only toggle owned by BoardView.
	 * Threaded down to each CardTile (which renders denser) and used here to feed
	 * the virtualizer a smaller pre-measure estimateSize. When it flips, a watch
	 * re-measures the mounted rows so the size cache doesn't hold stale heights
	 * (which would jump the scroll position on a long, scrolled stack).
	 */
	compact: {
		type: Boolean,
		default: false,
	},
})

const router = useRouter()
const route = useRoute()

// ── Inline column-title editing ─────────────────────────────────────────────
const editingTitle = ref(false)
const titleDraft = ref('')
const titleInputRef = ref(null)
const titleEditReady = ref(false)

function startEditTitle() {
	if (!props.onRenameStack) return
	titleDraft.value = props.stack.title
	editingTitle.value = true
	// Blur-to-save is armed only after the field settles. When rename is opened
	// from the closing ⋯ menu, the menu's focus-trap returns focus and blurs the
	// just-focused input; without this guard that spurious blur would immediately
	// saveTitle() and hide the field. A blur inside the window re-focuses instead.
	titleEditReady.value = false
	nextTick(() => {
		titleInputRef.value?.focus()
		titleInputRef.value?.select()
		setTimeout(() => { titleEditReady.value = true }, 200)
	})
}

function onTitleBlur() {
	if (!titleEditReady.value) {
		nextTick(() => titleInputRef.value?.focus())
		return
	}
	saveTitle()
}

function cancelEditTitle() {
	titleEditReady.value = true
	editingTitle.value = false
}

async function saveTitle() {
	if (!editingTitle.value) return
	editingTitle.value = false
	const next = titleDraft.value.trim()
	if (next === '' || next === props.stack.title) return
	try {
		await props.onRenameStack(props.stack.id, next)
	} catch (e) {
		// The parent surfaces failures; nothing to recover locally.
	}
}

// ── Role + WIP helpers ────────────────────────────────────────────────────────
const ROLE_LABELS = {
	1: t('kanso', 'Backlog'),
	2: t('kanso', 'To do'),
	3: t('kanso', 'In progress'),
	4: t('kanso', 'Review'),
	5: t('kanso', 'Done'),
}

function roleLabel(role) {
	return ROLE_LABELS[role] ?? ''
}

/** Ordered [value, label] pairs used to render the role radio group. */
const roleEntries = Object.entries(ROLE_LABELS).map(([k, v]) => [Number(k), v])

/** Draft WIP limit - kept in sync with the current stack value. */
const wipDraft = ref(props.stack.wipLimit > 0 ? String(props.stack.wipLimit) : '')

// Keep wipDraft in sync when the stack prop updates (e.g. after optimistic settle).
watch(() => props.stack.wipLimit, (val) => {
	wipDraft.value = val > 0 ? String(val) : ''
})

async function handleSetRole(role) {
	if (!props.onSetRole) return
	try {
		await props.onSetRole(props.stack.id, role)
	} catch {
		// Parent surfaces errors
	}
}

async function handleSetWip() {
	if (!props.onSetWip) return
	const n = parseInt(wipDraft.value, 10)
	const limit = Number.isFinite(n) && n > 0 ? n : 0
	try {
		await props.onSetWip(props.stack.id, limit)
	} catch {
		// Parent surfaces errors
	}
}

// Named palette for the column-colour menu (shares the label preset hexes).
const stackColorOptions = [
	{ hex: 'e74c3c', name: t('kanso', 'Red') },
	{ hex: 'e67e22', name: t('kanso', 'Orange') },
	{ hex: 'f1c40f', name: t('kanso', 'Yellow') },
	{ hex: '2ecc71', name: t('kanso', 'Green') },
	{ hex: '1abc9c', name: t('kanso', 'Teal') },
	{ hex: '3498db', name: t('kanso', 'Blue') },
	{ hex: '9b59b6', name: t('kanso', 'Purple') },
	{ hex: '34495e', name: t('kanso', 'Slate') },
]

async function handleSetColor(color) {
	if (!props.onSetColor) return
	try {
		await props.onSetColor(props.stack.id, color)
	} catch {
		// Parent surfaces errors
	}
}

/**
 * WIP limit is "active" when wipLimit is a positive integer (> 0).
 * Both null and 0 mean "no limit" per the backend spec.
 */
const hasWipLimit = computed(() => typeof props.stack.wipLimit === 'number' && props.stack.wipLimit > 0)
const isOverLimit = computed(() => hasWipLimit.value && props.cards.length > props.stack.wipLimit)
const isAtLimit = computed(() => hasWipLimit.value && props.cards.length >= props.stack.wipLimit)

/** Meter fill ratio (0-100), capped at 100 once at/over the limit. */
const wipFillPct = computed(() => {
	if (!hasWipLimit.value) return 0
	const pct = (props.cards.length / props.stack.wipLimit) * 100
	return Math.max(0, Math.min(100, pct))
})

const wipBadgeText = computed(() => {
	if (!hasWipLimit.value) return String(props.cards.length)
	return `${props.cards.length} / ${props.stack.wipLimit}`
})

const composerInputRef = ref(null)
const cardListRef = ref(null)
const columnRef = ref(null)
const headerRef = ref(null)
const newCardTitle = ref('')
const composerError = ref('')
const isPending = ref(false)
const isDropOver = ref(false)
const isStackDragging = ref(false)
const stackDropEdge = ref(null)
let cleanup = () => {}

// ── TanStack Virtual ──────────────────────────────────────────────────────────
// Pass options as a computed so that cardListRef.value is in the reactive
// dependency graph - when the scroll element mounts, the virtualizer's internal
// watch sees the change and calls _willUpdate() to initialize scroll tracking.
const virtualizerOptions = computed(() => ({
	count: props.cards.length,
	// Reading cardListRef.value inside this computed means Vue will re-evaluate
	// the computed (and re-run the virtualizer's internal watch on getScrollElement)
	// when cardListRef changes from null → DOM element after mount.
	getScrollElement: () => cardListRef.value,
	// Pre-measure estimate only — real heights are measured per-row via
	// measureElement below. Compact tiles are meaningfully shorter, so seed a
	// smaller estimate so the first paint doesn't over-allocate slots (#3415).
	estimateSize: () => (props.compact ? 62 : 90),
	overscan: 6,
	gap: 8,
	// Key the size cache by card id, not index: on a same-length reorder an
	// index-keyed cache would briefly apply the old occupant's height to the
	// new one, jumping the scroll position.
	getItemKey: (index) => props.cards[index]?.id ?? index,
}))

const virtualizer = useVirtualizer(virtualizerOptions)

// Belt-and-suspenders: explicitly call _willUpdate when the scroll element
// appears (covers any edge case where the computed re-evaluation is batched
// after the virtualizer's internal watch fires).
watch(cardListRef, (el) => {
	if (el) virtualizer.value._willUpdate()
})

// Density flip (#3415): the compact ↔ comfortable toggle changes every tile's
// height, but the virtualizer caches measured heights by card id — those cached
// heights are now stale, and estimateSize only governs not-yet-measured rows.
// Force a full re-measure so the total size + row offsets recompute from the new
// tile heights; without this a long, scrolled stack keeps the old slot heights
// until each row re-measures lazily, jumping the scroll position. nextTick lets
// the CardTiles apply their compact class first so measureElement reads the new
// height. measure() clears the size cache and re-measures the mounted rows.
watch(() => props.compact, () => {
	nextTick(() => {
		virtualizer.value.measure()
	})
})

/**
 * Function-ref for each virtual item wrapper.
 * TanStack Virtual's measureElement expects the DOM element so it can attach
 * a ResizeObserver for dynamic-height tracking.
 */
function measureVirtualEl(el, index) {
	if (!el) return
	virtualizer.value.measureElement(el)
}

// ── Drop targets & auto-scroll ────────────────────────────────────────────────
onMounted(() => {
	if (!cardListRef.value) return
	const registrations = [
		// Column-level drop target: catches drops on the empty space below all cards
		dropTargetForElements({
			element: cardListRef.value,
			canDrop: ({ source }) => source.data.type === 'card',
			getData: () => ({ type: 'column', stackId: props.stack.id, laneKey: props.laneKey }),
			onDragEnter: () => { isDropOver.value = true },
			onDragLeave: () => { isDropOver.value = false },
			onDrop: () => { isDropOver.value = false },
		}),
		// Auto-scroll the card list when dragging near the edges
		autoScrollForElements({
			element: cardListRef.value,
		}),
	]

	// Stack reordering is a board-level operation on a shared stack, so it is
	// only wired when NOT inside a swimlane (a stack renders once per lane; per-
	// lane reorder handles would be duplicative and ambiguous). Within a lane
	// only card drag survives — the swimlane requirement.
	if (!props.laneKey && headerRef.value && columnRef.value) {
		registrations.push(
			// Stack reordering: only the header is the drag handle …
			draggable({
				element: headerRef.value,
				getInitialData: () => ({ type: 'stack', stackId: props.stack.id }),
				onDragStart: () => { isStackDragging.value = true },
				onDrop: () => { isStackDragging.value = false },
			}),
			// … but the whole column is the drop target (left/right edges).
			dropTargetForElements({
				element: columnRef.value,
				canDrop: ({ source }) => source.data.type === 'stack' && source.data.stackId !== props.stack.id,
				getData: ({ input, element: el }) => attachClosestEdge(
					{ type: 'stack', stackId: props.stack.id },
					{ input, element: el, allowedEdges: ['left', 'right'] },
				),
				onDrag: ({ self }) => {
					stackDropEdge.value = extractClosestEdge(self.data)
				},
				onDragLeave: () => { stackDropEdge.value = null },
				onDrop: () => { stackDropEdge.value = null },
			}),
		)
	}

	cleanup = combine(...registrations)
})

onUnmounted(() => {
	cleanup()
})

function openCard(cardId) {
	// Notify BoardView so keyboard focusedCardId stays in sync with mouse clicks
	props.onCardFocus?.(cardId)
	router.push({
		name: 'card-modal',
		params: { id: route.params.id, cardId },
	})
}

// ── Exposed API for BoardView keyboard navigation ─────────────────────────────

/**
 * Scroll the virtualizer to the given card index and return after layout.
 * BoardView calls this before focusing the tile DOM element.
 */
function scrollToIndex(index) {
	virtualizer.value.scrollToIndex(index, { align: 'auto' })
}

/**
 * Focus the inline card composer input for rapid card creation.
 */
function focusComposer() {
	composerInputRef.value?.focus()
}

async function handleDeleteStack() {
	if (!props.onDeleteStack) return
	const stackId = props.stack.id
	try {
		await props.onDeleteStack(stackId)
		showUndo(t('kanso', 'Column deleted'), () => {
			if (props.onRestoreStack) {
				props.onRestoreStack(stackId)
			}
		})
	} catch {
		// Parent is responsible for surfacing deletion errors
	}
}

/** Toggle this column's collapsed state (#3677). */
function handleToggleCollapsed() {
	props.onToggleCollapsed?.(props.stack.id)
}

defineExpose({ scrollToIndex, focusComposer })

/**
 * Multi-line paste → quick multi-add. A single-line `<input>` collapses
 * embedded newlines, so intercept the paste: if the clipboard holds more than
 * one non-blank line, create one card per line (in order) instead of pasting
 * the text into the field. A single-line paste falls through to the default
 * behaviour (lands in the input, submitted later on Enter) - no regression.
 */
function onComposerPaste(event) {
	const text = event.clipboardData?.getData('text') ?? ''
	if (splitTitles(text).length < 2) return
	event.preventDefault()
	createCardsFromText(text)
}

/** Split submitted text into trimmed, non-blank card titles, in order. */
function splitTitles(text) {
	return text.split(/\r\n|\r|\n/).map((l) => l.trim()).filter((l) => l !== '')
}

/**
 * Create one card per non-blank line, in submitted order, reusing the single
 * onCreateCard path. Each server-side create positions the new card relative to
 * the current head/tail, so when the board prepends new cards we must submit in
 * reverse: the last-created card lands on top, leaving the first line topmost.
 */
async function createCardsFromText(text) {
	const titles = splitTitles(text)
	if (titles.length === 0) return
	composerError.value = ''
	isPending.value = true
	const ordered = props.newCardsOnTop ? [...titles].reverse() : titles
	try {
		for (const title of ordered) {
			// A recognized trailing natural-date token (#3416) sets the card's due
			// date and is stripped from the title; anything else leaves it intact.
			const { title: parsedTitle, duedate, allDay } = parseDueToken(title)
			await props.onCreateCard(props.stack.id, parsedTitle, duedate, allDay)
		}
		newCardTitle.value = ''
		// Re-focus for rapid entry - the signature UX
		composerInputRef.value?.focus()
	} catch (err) {
		composerError.value =
			err?.response?.data?.error || t('kanso', 'Failed to create card.')
	} finally {
		isPending.value = false
	}
}

async function submitCard() {
	// One card per non-blank line - a single line behaves exactly as before.
	await createCardsFromText(newCardTitle.value)
}

// ── Card templates (#3409) ──────────────────────────────────────────────────
const templates = ref([])
const templatesLoaded = ref(false)

/** Lazily load the board's card templates when the picker menu opens. */
async function loadTemplates() {
	if (!props.onFetchTemplates) return
	try {
		templates.value = await props.onFetchTemplates()
	} catch (err) {
		composerError.value =
			err?.response?.data?.error || t('kanso', 'Failed to load templates.')
	} finally {
		templatesLoaded.value = true
	}
}

/** Create a new card in this stack pre-filled from the chosen template. */
async function createFromTemplate(templateId) {
	if (!props.onCreateFromTemplate) return
	composerError.value = ''
	isPending.value = true
	try {
		await props.onCreateFromTemplate(props.stack.id, templateId)
	} catch (err) {
		composerError.value =
			err?.response?.data?.error || t('kanso', 'Failed to create card from template.')
	} finally {
		isPending.value = false
	}
}
</script>

<style scoped>
/* Quiet-columns (variant 1a): a white / raised column surface that lifts off the
   sunken board canvas. --color-main-background + a soft shadow gives the "raised"
   read against the board-view__stacks-wrap sunken background. */
.stack-column {
	position: relative;
	flex-shrink: 0;
	width: 280px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	max-height: calc(100vh - 140px);
	box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}

.stack-column--dragging {
	opacity: 0.4;
}

/* ── Collapsed column / rail (#3677) ──────────────────────────────────────────
   Collapsed = a narrow rail. The full column body (header, composer, card list)
   stays mounted so the virtualizer + drop targets survive; it's just hidden/
   shrunk. The card list keeps a box (so cardListRef remains a valid drop target)
   but its contents are visually hidden behind the rail overlay. Width animates
   ≤150ms. */
.stack-column--collapsed {
	width: 48px;
	min-width: 48px;
	/* Fill the board height so every collapsed rail is the same length,
	   regardless of how many cards its (hidden) list holds — otherwise a
	   1-card column collapses to a stub while a full one stays tall. Matches
	   the expanded column's max-height cap. */
	height: calc(100vh - 140px);
	padding: 0;
	gap: 0;
	overflow: hidden;
	cursor: pointer;
}
.stack-column--collapsed:hover {
	background: var(--color-background-hover);
}
.stack-column--collapsed .stack-column__cards {
	/* Keep a sized box for the drop target, but hide the cards behind the rail. */
	visibility: hidden;
	overflow: hidden;
}
@media (prefers-reduced-motion: no-preference) {
	.stack-column {
		transition: width 0.15s ease, min-width 0.15s ease;
	}
}

/* The rail overlay: a full-height button covering the collapsed column. */
.stack-column__rail {
	position: absolute;
	inset: 0;
	z-index: 5;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 10px 0;
	border: none;
	/* No own background/border-radius: the parent .stack-column already draws
	   the card (bg + border + radius) and clips via overflow:hidden, so a
	   second rounded rect here just produced mismatched corners. The rail is a
	   transparent overlay that only holds the chevron/count/title + click. */
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
}
.stack-column__rail:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}
.stack-column__rail-chevron {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}
.stack-column__rail-count {
	flex-shrink: 0;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 20px;
	height: 20px;
	padding: 0 6px;
	border-radius: 10px;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 0.75rem;
	font-weight: 600;
}
/* Vertical (bottom-to-top) column title filling the rail. */
.stack-column__rail-title {
	writing-mode: vertical-rl;
	transform: rotate(180deg);
	font-weight: 600;
	font-size: 0.85rem;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	max-height: 100%;
}

/* Collapse toggle button in the expanded header. */
.stack-column__collapse-btn {
	flex-shrink: 0;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	padding: 0;
	margin: -2px 0 -2px -4px;
	border: none;
	border-radius: 4px;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}
.stack-column__collapse-btn:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}
.stack-column__collapse-btn:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -1px;
}

/* Stack drop indicators - same visual language as .card-tile__drop-line */
.stack-column__drop-line {
	position: absolute;
	top: 0;
	bottom: 0;
	width: 2px;
	background: var(--color-primary-element);
	border-radius: 1px;
	z-index: 10;
	pointer-events: none;
}

.stack-column__drop-line--left {
	left: -9px;
}

.stack-column__drop-line--right {
	right: -9px;
}

.stack-column__header {
	display: flex;
	flex-direction: column;
	gap: 0;
	cursor: grab;
}
.stack-column__header-row {
	display: flex;
	align-items: center;
	gap: 8px;
}
/* Coloured column: the meter track below the header takes the stack colour as its
   accent (see --stack-color usage in the meter rules). */
/* Actions menu - sits at the far right; margin-left:auto pushes it right when
   no role chip is present (when a role chip is present it already took the auto
   margin and the menu simply trails it). */
.stack-column__actions {
	flex-shrink: 0;
	margin-left: auto;
}
.stack-column__role-chip + .stack-column__actions {
	margin-left: 4px;
}
/* The swatch sits in NcActionButton's #icon slot, which normally holds an icon
   sized to the full clickable-area box (see .action-button__icon in @nextcloud/vue).
   The .action-button flex row is align-items:flex-start, so a bare 16px dot pins to
   the top and reads as misaligned with its (vertically-centred) label. This box fills
   the same clickable-area square and flex-centres the 16px dot, so dot and label share
   a centre line. */
.stack-column__swatch-box {
	display: flex;
	align-items: center;
	justify-content: center;
	width: var(--default-clickable-area);
	height: var(--default-clickable-area);
	flex: 0 0 auto;
}
.stack-column__swatch {
	display: block;
	width: 16px;
	height: 16px;
	border-radius: 50%;
	border: 1px solid var(--color-border);
}
.stack-column__swatch--none {
	background: var(--color-main-background);
}

.stack-column__title {
	font-weight: 600;
	font-size: 0.95rem;
	color: var(--color-main-text);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	min-width: 0;
}

.stack-column__title--editable {
	cursor: text;
	border-radius: 4px;
	padding: 2px 4px;
	margin: -2px -4px;
}

.stack-column__title--editable:hover {
	background: var(--color-background-hover);
}

.stack-column__title-input {
	flex: 1 1 auto;
	min-width: 0;
	font-weight: 600;
	font-size: 0.95rem;
	color: var(--color-main-text);
	background: var(--color-main-background);
	border: 2px solid var(--color-primary-element);
	border-radius: 4px;
	padding: 2px 4px;
	margin: 0;
}

/* Card count / WIP figure - plain muted text next to the title (mockup 1a).
   No longer a filled pill; the "over limit" signal is the meter + caption. */
.stack-column__badge {
	flex-shrink: 0;
	display: inline-flex;
	align-items: center;
	font-size: 0.8rem;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.stack-column__badge--over-limit {
	color: color-mix(in srgb, var(--color-warning, #eca700) 85%, var(--color-main-text));
	font-weight: 600;
}

/* WIP meter - a 2px track under the header. Neutral track by default; with a
   limit the fill tracks the ratio and shifts to the warning colour at/over it.
   Mirrors the --kanso-tint-* color-mix fallback pattern used elsewhere. */
.stack-column__wip-meter {
	position: relative;
	height: 2px;
	margin-top: 10px;
	border-radius: 1px;
	background: var(--color-border);
	overflow: hidden;
}
/* Coloured column: tint the meter track with the stack colour. */
.stack-column__header--colored .stack-column__wip-meter {
	background: color-mix(in srgb, var(--stack-color) 40%, var(--color-border));
}
.stack-column__wip-fill {
	position: absolute;
	inset: 0 auto 0 0;
	height: 100%;
	background: var(--color-primary-element, #0082c9);
	border-radius: 1px;
	transition: width 0.2s ease;
}
.stack-column__wip-meter--at .stack-column__wip-fill {
	background: color-mix(in srgb, var(--color-warning, #eca700) 90%, transparent);
}
.stack-column__wip-meter--over {
	background: var(--kanso-tint-warning, color-mix(in srgb, var(--color-warning, #eca700) 20%, transparent));
}
.stack-column__wip-meter--over .stack-column__wip-fill {
	background: var(--color-warning, #eca700);
}
.stack-column__wip-caption {
	margin-top: 6px;
	font-size: 0.7rem;
	color: color-mix(in srgb, var(--color-warning, #eca700) 85%, var(--color-main-text));
}

/* Card composer */
.card-composer-wrap {
	display: flex;
	align-items: center;
	gap: 4px;
}

.card-composer {
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex: 1 1 auto;
	min-width: 0;
}

.card-composer__templates {
	flex: 0 0 auto;
}

.card-composer__input {
	width: 100%;
	height: 34px;
	padding: 0 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.875rem;
	transition: border-color 0.15s ease;
}

.card-composer__input:focus {
	outline: none;
	border-color: var(--color-primary);
}

.card-composer__error {
	color: var(--color-error);
	font-size: 0.75rem;
	margin: 0;
}

/* Card list - own scrollable element */
.stack-column__cards {
	overflow-y: auto;
	flex: 1;
	min-height: 0;
	transition: background 0.15s ease;
	/* No flex gap here - gap is handled by the virtualizer's gap option so the
	   measurer sees the true inter-item spacing. */
}

.stack-column__cards--drop-over {
	background: rgba(var(--color-primary-element-rgb, 0, 130, 201), 0.06);
	border-radius: var(--border-radius);
}

/* Virtual host: sized to totalSize so the scroll track is correct */
.stack-column__virtual-host {
	position: relative;
	width: 100%;
}

/* Each absolutely-positioned virtual row wrapper */
.stack-column__virtual-item {
	/* height is dynamic - measured by TanStack Virtual per item */
}

/* Empty-column drop zone (#3905 follow-up): when a column has no cards, the
   placeholder IS the drop target the user aims at. A 48px strip was easy to
   miss — the highlight would fire but the pointer often sat just below it, so
   the drop landed on nothing. Give it a generous min-height so an empty column
   offers a comfortably large, easy-to-hit drop area. It only sizes the empty
   state (rendered solely when cards.length === 0), so populated columns — whose
   card list sizes to its cards — are unaffected. */
.stack-column__empty-placeholder {
	display: flex;
	align-items: flex-start;
	justify-content: center;
	min-height: 160px;
	padding: 16px 8px;
	border-radius: var(--border-radius);
}

/* Subtle onboarding nudge (#3413): shown only while the stack is empty. Faint
   so it reads as a placeholder, and it strengthens when the column is a drop
   target (the parent gets --drop-over). */
.stack-column__empty-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
	opacity: 0.7;
	text-align: center;
	pointer-events: none;
	user-select: none;
}

.stack-column__cards--drop-over .stack-column__empty-hint {
	opacity: 1;
	color: var(--color-primary-element);
}

/* Role chip - pushed to the right edge of the header row (mockup 1a). */
.stack-column__role-chip {
	flex-shrink: 0;
	margin-left: auto;
	display: inline-flex;
	align-items: center;
	height: 18px;
	padding: 0 6px;
	border-radius: 9px;
	font-size: 0.68rem;
	font-weight: 700;
	letter-spacing: 0.02em;
	text-transform: uppercase;
	white-space: nowrap;
	/* default neutral style - overridden per-role below */
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

/* Backlog - muted */
.stack-column__role-chip--1 {
	background: color-mix(in srgb, var(--color-text-maxcontrast) 12%, transparent);
	color: var(--color-text-maxcontrast);
}

/* To do - blue tint */
.stack-column__role-chip--2 {
	background: color-mix(in srgb, var(--color-primary-element, #0082c9) 15%, transparent);
	color: var(--color-primary-element, #0082c9);
}

/* In progress - info/primary */
.stack-column__role-chip--3 {
	background: color-mix(in srgb, var(--color-primary-element, #0082c9) 22%, transparent);
	color: var(--color-primary-element, #0082c9);
}

/* Review - warning */
.stack-column__role-chip--4 {
	background: color-mix(in srgb, var(--color-warning, #eca700) 18%, transparent);
	color: color-mix(in srgb, var(--color-warning, #eca700) 85%, var(--color-main-text));
}

/* Done - success */
.stack-column__role-chip--5 {
	background: color-mix(in srgb, var(--color-success, #46ba61) 18%, transparent);
	color: color-mix(in srgb, var(--color-success, #46ba61) 85%, var(--color-main-text));
}

/* WIP badge over-limit warning */
.stack-column__badge--over-limit {
	background: color-mix(in srgb, var(--color-warning, #eca700) 25%, transparent);
	color: color-mix(in srgb, var(--color-warning, #eca700) 85%, var(--color-main-text));
	outline: 1px solid color-mix(in srgb, var(--color-warning, #eca700) 50%, transparent);
}
</style>
