<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div ref="scrollRef" class="board-list-table">
		<p v-if="rows.length === 0" class="board-list-table__empty">
			{{ t('kanso', 'No cards to show.') }}
		</p>
		<div
			v-else
			class="board-list-table__host"
			:style="{ height: virtualizer.getTotalSize() + 'px' }">
			<div
				v-for="vRow in virtualizer.getVirtualItems()"
				:key="rows[vRow.index].id"
				:data-index="vRow.index"
				class="board-list-table__vrow"
				:style="{ position: 'absolute', top: 0, left: 0, width: '100%', height: `${vRow.size}px`, transform: `translateY(${vRow.start}px)` }">

				<!-- Stack group bar: chevron, title, count/WIP, aggregate progress -->
				<button
					v-if="rows[vRow.index].type === 'header'"
					class="board-list-group"
					:aria-expanded="!isCollapsed(rows[vRow.index].key)"
					@click="toggleGroup(rows[vRow.index].key)">
					<ChevronDownIcon
						v-if="!isCollapsed(rows[vRow.index].key)"
						:size="18"
						class="board-list-group__chevron" />
					<ChevronRightIcon
						v-else
						:size="18"
						class="board-list-group__chevron" />
					<span
						class="board-list-group__dot"
						:style="rows[vRow.index].color ? { background: cssColor(rows[vRow.index].color) } : {}" />
					<span class="board-list-group__title">{{ rows[vRow.index].title }}</span>

					<!-- WIP indicator when a limit is set, plain count otherwise -->
					<span
						v-if="rows[vRow.index].wip"
						class="board-list-group__wip"
						:class="{ 'board-list-group__wip--over': rows[vRow.index].wip.over }">
						{{ rows[vRow.index].wip.count }} / {{ rows[vRow.index].wip.limit }}
					</span>
					<span v-else class="board-list-group__count">{{ rows[vRow.index].count }}</span>

					<!-- Per-group hints: overdue / blocked counts, only when nonzero -->
					<span
						v-if="rows[vRow.index].hints && (rows[vRow.index].hints.overdue || rows[vRow.index].hints.blocked)"
						class="board-list-group__hints">
						<span
							v-if="rows[vRow.index].hints.overdue"
							class="board-list-group__hint board-list-group__hint--overdue">
							{{ t('kanso', '{n} overdue', { n: rows[vRow.index].hints.overdue }) }}
						</span>
						<span
							v-if="rows[vRow.index].hints.blocked"
							class="board-list-group__hint">
							{{ t('kanso', '{n} blocked', { n: rows[vRow.index].hints.blocked }) }}
						</span>
					</span>

					<!-- Aggregate done/total progress across the group -->
					<span
						v-if="rows[vRow.index].progress"
						class="board-list-group__progress">
						{{ rows[vRow.index].progress.done }}/{{ rows[vRow.index].progress.total }}
					</span>
				</button>

				<!-- Quick-add composer row -->
				<div
					v-else-if="rows[vRow.index].type === 'add'"
					class="card-composer-wrap">
					<form
						class="card-composer"
						@submit.prevent="submitCard(rows[vRow.index].stackId)">
						<input
							:ref="(el) => setComposerRef(rows[vRow.index].stackId, el)"
							:value="draftByStack[rows[vRow.index].stackId] ?? ''"
							class="card-composer__input"
							type="text"
							maxlength="100"
							:placeholder="t('kanso', 'Add card…')"
							:disabled="isPendingByStack[rows[vRow.index].stackId] ?? false"
							:data-stack-id="rows[vRow.index].stackId"
							@input="(e) => { draftByStack[rows[vRow.index].stackId] = e.target.value }"
							@paste="(e) => onComposerPaste(e, rows[vRow.index].stackId)"
							@keydown.enter.prevent="submitCard(rows[vRow.index].stackId)" />
					</form>

					<!-- "+ from template" picker: hidden when callbacks absent -->
					<NcActions
						v-if="props.onFetchTemplates && props.onCreateFromTemplate"
						class="card-composer__templates"
						:force-menu="true"
						:aria-label="t('kanso', 'New card from template')"
						@open="loadTemplates(rows[vRow.index].stackId)">
						<template #icon>
							<FileDocumentOutlineIcon :size="18" />
						</template>
						<NcActionCaption :name="t('kanso', 'From template')" />
						<NcActionButton
							v-for="tpl in templatesByStack[rows[vRow.index].stackId] ?? []"
							:key="tpl.id"
							:close-after-click="true"
							:disabled="isPendingByStack[rows[vRow.index].stackId] ?? false"
							@click="createFromTemplate(rows[vRow.index].stackId, tpl.id)">
							<template #icon>
								<FileDocumentOutlineIcon :size="20" />
							</template>
							{{ tpl.title }}
						</NcActionButton>
						<NcActionText
							v-if="(templatesLoadedByStack[rows[vRow.index].stackId] ?? false) && (templatesByStack[rows[vRow.index].stackId] ?? []).length === 0">
							{{ t('kanso', 'No templates yet. Open any card and choose "Mark as template" from its actions menu.') }}
						</NcActionText>
					</NcActions>

					<!-- Inline error, scoped per stack -->
					<p
						v-if="errorByStack[rows[vRow.index].stackId]"
						class="card-composer__error">
						{{ errorByStack[rows[vRow.index].stackId] }}
					</p>
				</div>

				<!-- Card row. Top-level AND child rows are draggable: the list draws the
				     indent, so dragging a sub-card out of it is a gesture the user can
				     see themselves make (the kanban board draws no indent, so a tile
				     drag there never touches the parent). -->
				<div
					v-else-if="rows[vRow.index].type === 'card'"
					v-card-dnd="{ card: rows[vRow.index].card, sortMode: props.sortMode, rowParentId: rows[vRow.index].isChild ? rows[vRow.index].card.parentCardId : null, canEdit: canEditBoard }"
					class="board-list-row-wrap"
					:class="{
						'board-list-row-wrap--dragging': isDraggingCard === rows[vRow.index].card.id,
						'board-list-row-wrap--drag-over-top': dropTargetCardId === rows[vRow.index].card.id && dropEdge === 'top',
						'board-list-row-wrap--drag-over-bottom': dropTargetCardId === rows[vRow.index].card.id && dropEdge === 'bottom',
						'board-list-row-wrap--nest-target': dropTargetCardId === rows[vRow.index].card.id && dropMode === 'nest',
					}">
					<button
						class="board-list-row"
						:class="{
							'board-list-row--draggable': props.sortMode === 'manual' && canEditBoard,
							'board-list-row--child': rows[vRow.index].isChild,
						}"
						@click="openCard(rows[vRow.index].card)">

						<!-- Expand/collapse caret for parent cards with children; spacer for
						     all other top-level rows. A child row has neither (its own indent
						     already carries the hierarchy). -->
						<span
							v-if="rows[vRow.index].hasChildren"
							class="board-list-row__caret"
							role="button"
							:aria-label="isCardExpanded(rows[vRow.index].card.id) ? t('kanso', 'Collapse sub-cards') : t('kanso', 'Expand sub-cards')"
							:aria-expanded="isCardExpanded(rows[vRow.index].card.id)"
							tabindex="0"
							@click.stop="toggleCard(rows[vRow.index].card.id)"
							@keydown.enter.stop="toggleCard(rows[vRow.index].card.id)"
							@keydown.space.stop.prevent="toggleCard(rows[vRow.index].card.id)">
							<ChevronDownIcon
								v-if="isCardExpanded(rows[vRow.index].card.id)"
								:size="14"
								class="board-list-row__caret-icon" />
							<ChevronRightIcon
								v-else
								:size="14"
								class="board-list-row__caret-icon" />
						</span>
						<!-- Spacer keeps status dot aligned when there is no caret -->
						<span
							v-else-if="!rows[vRow.index].isChild"
							class="board-list-row__caret-spacer" />

						<span
							class="board-list-row__status"
							:class="`board-list-row__status--${statusOf(rows[vRow.index].card)}`"
							:title="statusLabel(rows[vRow.index].card)" />

						<!-- Human reference id (KAN-123) -->
						<span
							v-if="cardHumanId(rows[vRow.index].card)"
							class="board-list-row__id">
							{{ cardHumanId(rows[vRow.index].card) }}
						</span>

						<!-- Labels (colour dots) -->
						<span
							v-if="(rows[vRow.index].card.labelIds || []).length"
							class="board-list-row__labels">
							<span
								v-for="labelId in (rows[vRow.index].card.labelIds || []).slice(0, 4)"
								:key="labelId"
								class="board-list-row__label-dot"
								:title="labelTitle(labelId)"
								:style="{ background: labelColor(labelId) }" />
						</span>

						<span
							class="board-list-row__title"
							:class="{ 'board-list-row__title--done': isDone(rows[vRow.index].card) }">
							{{ rows[vRow.index].card.title }}
						</span>

						<span class="board-list-row__meta">
							<!-- Priority -->
							<span
								v-if="rows[vRow.index].card.priority > 0"
								class="board-list-row__priority"
								:class="`board-list-row__priority--${rows[vRow.index].card.priority}`">
								{{ priorityLabel(rows[vRow.index].card.priority) }}
							</span>

							<!-- Progress (checklist, else child cards) -->
							<span
								v-if="cardProgress(rows[vRow.index].card)"
								class="board-list-row__count">
								<CheckboxMarkedOutlineIcon :size="14" />
								{{ cardProgress(rows[vRow.index].card).done }}/{{ cardProgress(rows[vRow.index].card).total }}
							</span>

							<!-- Due date -->
							<span
								v-if="rows[vRow.index].card.duedate"
								class="board-list-row__due"
								:class="{ 'board-list-row__due--overdue': isOverdue(rows[vRow.index].card) }">
								<CalendarIcon :size="14" />
								{{ formatDue(rows[vRow.index].card) }}
							</span>

							<!-- Comments -->
							<span v-if="rows[vRow.index].card.commentCount > 0" class="board-list-row__count">
								<CommentOutlineIcon :size="14" />
								{{ rows[vRow.index].card.commentCount }}
							</span>

							<!-- Review state -->
							<CheckDecagramIcon
								v-if="rows[vRow.index].card.reviewState === 'approved'"
								:size="15"
								class="board-list-row__review board-list-row__review--approved" />
							<AlertDecagramIcon
								v-else-if="rows[vRow.index].card.reviewState === 'changes_requested'"
								:size="15"
								class="board-list-row__review board-list-row__review--changes" />

							<!-- Timer running (#73) -->
							<TimerOutlineIcon
								v-if="rows[vRow.index].card.timerRunning && cardFeatures.timeTracking"
								:size="15"
								class="board-list-row__timer-running"
								:title="t('kanso', 'Timer running')" />

							<!-- Assignees -->
							<span
								v-if="(rows[vRow.index].card.assigneeIds || []).length"
								class="board-list-row__assignees">
								<NcAvatar
									v-for="uid in (rows[vRow.index].card.assigneeIds || []).slice(0, 3)"
									:key="uid"
									:user="uid"
									:size="24"
									:hide-status="true" />
							</span>
						</span>
					</button>
					<!-- Drop indicator lines (top / bottom edge). The line is indented to
					     the level the card would land at, so leaving a parent is visible
					     as the line jumping back to the left margin; an un-nesting drop
					     also switches it to the dashed detach style. -->
					<div
						v-if="dropTargetCardId === rows[vRow.index].card.id && dropEdge === 'top'"
						class="board-list-drop-indicator board-list-drop-indicator--top"
						:class="{
							'board-list-drop-indicator--nested': dropNested,
							'board-list-drop-indicator--unnest': dropIntent === 'unnest',
						}" />
					<div
						v-if="dropTargetCardId === rows[vRow.index].card.id && dropEdge === 'bottom'"
						class="board-list-drop-indicator board-list-drop-indicator--bottom"
						:class="{
							'board-list-drop-indicator--nested': dropNested,
							'board-list-drop-indicator--unnest': dropIntent === 'unnest',
						}" />
					<!-- Nest hint (#5885): centre-band drop makes the dragged card a
					     sub-card. A reorder drop that lands among ANOTHER card's children
					     adopts it into that parent, so it carries the same label. -->
					<span
						v-if="dropTargetCardId === rows[vRow.index].card.id && (dropMode === 'nest' || dropIntent === 'reparent')"
						class="board-list-nest-hint">
						{{ t('kanso', 'Drop to nest as sub-card') }}
					</span>
					<!-- Un-nest hint: this reorder position is outside the dragged card's
					     parent, so the drop takes it out. Deliberately worded as the
					     action, and styled apart from the nest hint. -->
					<span
						v-else-if="dropTargetCardId === rows[vRow.index].card.id && dropIntent === 'unnest'"
						class="board-list-nest-hint board-list-nest-hint--unnest">
						{{ t('kanso', 'Drop to remove from parent') }}
					</span>
				</div>

				<!-- Group-level column drop target for empty groups (appends to end).
				     Rendered as the last slot inside each header vrow so it occupies the
				     header's full area; the monitor's columnTarget branch handles it. -->
				<div
					v-if="rows[vRow.index].type === 'header' && rows[vRow.index].stackId"
					:ref="(el) => setGroupDropRef(rows[vRow.index].stackId, el)"
					class="board-list-group-drop"
					:data-stack-id="rows[vRow.index].stackId" />
			</div>
		</div>

		<!-- Column composer (#9853). List view had no way to create a column: the
		     ⋯ More → "Add column" action revealed a composer that only exists on the
		     kanban track, so in list view it silently did nothing. This is the list's
		     own target for that action, and a standing affordance in its own right —
		     the same shape as the per-group "Add card…" composer, at the end of the
		     group list.

		     Deliberately rendered OUTSIDE the virtualizer (a sibling of the row host,
		     not a virtual row): the list recycles row elements as it scrolls, which
		     would blow away the input's focus and half-typed draft. Out here it is a
		     plain, stable DOM node.

		     Editors only — BoardView passes the callback only when the user may edit
		     the board, so a viewer gets no affordance at all. The cross-board Views
		     path (props.groups, whose rows have no stack behind them) never passes
		     one either. -->
		<form
			v-if="addColumnEnabled"
			class="add-column-composer"
			@submit.prevent="submitNewColumn">
			<input
				ref="addColumnInputRef"
				v-model="addColumnTitle"
				class="add-column-composer__input"
				type="text"
				maxlength="100"
				data-test="list-add-column"
				:aria-label="t('kanso', 'Add column…')"
				:placeholder="t('kanso', 'Add column…')"
				:disabled="addColumnPending"
				@keydown.enter.prevent="submitNewColumn">
			<p v-if="addColumnError" class="add-column-composer__error">{{ addColumnError }}</p>
		</form>
	</div>
</template>

<script setup>
import { ref, computed, inject, nextTick, reactive, watch, onMounted, onBeforeUnmount, getCurrentInstance } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import { useVirtualizer } from '@tanstack/vue-virtual'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActionText from '@nextcloud/vue/components/NcActionText'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import CheckboxMarkedOutlineIcon from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import CommentOutlineIcon from 'vue-material-design-icons/CommentOutline.vue'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import AlertDecagramIcon from 'vue-material-design-icons/AlertDecagram.vue'
import TimerOutlineIcon from 'vue-material-design-icons/TimerOutline.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import { draggable, dropTargetForElements, monitorForElements } from '@atlaskit/pragmatic-drag-and-drop/element/adapter'
import { combine } from '@atlaskit/pragmatic-drag-and-drop/combine'
import { extractClosestEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge'
import { buildCardDragData, buildCardDropData, NEST_ENABLED } from '../services/cardNesting.js'
import { useBoardCanEdit } from '../services/boardPermissions.js'
import { useCardFeatures } from '../services/cardFeatures.js'
import { autoScrollForElements } from '@atlaskit/pragmatic-drag-and-drop-auto-scroll/element'
import { cssColor } from '../services/color.js'
import { humanId } from '../services/humanId.js'
import { PRIORITY_LEVELS } from '../composables/usePriority.js'
import { formatCardDate } from '../utils/dateDisplay.js'
import { parseDueToken } from '../utils/dueTokens.js'

const props = defineProps({
	/** Non-archived stacks in display order (already filtered by BoardView). */
	stacks: { type: Array, default: () => [] },
	/** Map<stackId, card[]> - already filter+sort applied by BoardView. */
	cardsByStack: { type: Object, default: null },
	/**
	 * Generalized group-by mode (#3815): an ordered list of arbitrary groups
	 * `[{ key, title, cards }]` (e.g. cross-board Views grouped by status /
	 * priority / assignee / board). When provided it DRIVES the row model
	 * instead of the stack/cardsByStack path - so the same virtualized list,
	 * collapse and card row render for a board or a cross-board View. Backward
	 * compatible: absent → the classic per-stack rendering is unchanged.
	 */
	groups: { type: Array, default: null },
	/** Map<labelId, label>. */
	labelsById: { type: Object, required: true },
	/** Board human-id prefix (e.g. "KAN") - composed with card.boardSeq. */
	boardPrefix: { type: String, default: '' },
	/**
	 * The deep-link board id for a card. For a single-board list every card
	 * shares this; for a cross-board View pass null and each card's own boardId
	 * is used (see openCard).
	 */
	boardId: { type: [String, Number], default: null },
	/**
	 * Async fn (stackId, title, duedate?, allDay?) → Promise - creates a card.
	 * When provided (and props.groups is absent) the quick-add composer appears
	 * at the top of each group (matching the kanban column composer).
	 */
	onCreateCard: { type: Function, default: null },
	/**
	 * Async fn () → Promise<Card[]> - lazily fetches board card templates when
	 * the "from template" picker menu opens. When absent the template button is hidden.
	 */
	onFetchTemplates: { type: Function, default: null },
	/**
	 * Async fn (stackId, templateId) → Promise - creates a card from a template.
	 */
	onCreateFromTemplate: { type: Function, default: null },
	/**
	 * Async fn (title) → Promise - creates a column (#9853). When provided (and
	 * props.groups is absent) an "Add column…" composer renders at the end of the
	 * group list, and `focusAddColumn()` (exposed below) is what the board's
	 * ⋯ More → "Add column" action targets while list view is active. BoardView
	 * passes it only to editors, so a viewer sees no add-column affordance.
	 */
	onCreateStack: { type: Function, default: null },
	/**
	 * Board's "new cards on top" preference. When true, multi-line pastes are
	 * submitted in reverse order so the first pasted line appears topmost.
	 */
	newCardsOnTop: { type: Boolean, default: false },
	/**
	 * Display sort mode from BoardView. DnD reordering is only wired when this
	 * is 'manual' (fractional order) — other sort modes are view-only projections
	 * and must not overwrite sort keys on drop. The BoardView card monitor also
	 * guards this; we gate draggable registration here as an additional safety net
	 * and to show the correct grab cursor only when dragging is meaningful.
	 */
	sortMode: { type: String, default: 'manual' },
})

const emit = defineEmits(['open'])

// When a parent handles `@open` (a cross-board View owns a card-detail overlay,
// #3950), emit the card up and let the parent open it in place — do NOT route to
// card-modal (a child of the board route, which would swap the whole View out for
// the board). Inside an actual board no `@open` is attached, so the classic
// router.push deep-link behaviour is preserved unchanged.
// A parent's `@open` presence is fixed at mount (it never toggles at runtime), so
// this is a one-off static read, not a reactive dependency.
const hasOpenHandler = !!getCurrentInstance()?.vnode?.props?.onOpen

const router = useRouter()
const scrollRef = ref(null)

// ── Drop state (shared across the directive instances via component refs) ──────
// These are set/cleared by the vCardDnd directive's dropTarget callbacks and
// consumed by the template to render the drop-indicator lines.
const isDraggingCard = ref(null)    // cardId currently being dragged (for opacity)
const dropTargetCardId = ref(null)  // cardId the pointer is hovering over
const dropEdge = ref(null)          // 'top' | 'bottom' | null
const dropMode = ref(null)          // 'reorder' | 'nest' | null (#5885)
// What a reorder drop would do to the dragged card's PARENT: 'unnest' (it
// leaves its parent), 'reparent' (it joins another card's children) or
// 'reorder' (parent untouched). Drives the drop affordance so the two are never
// confusable before the pointer is released.
const dropIntent = ref('reorder')
// Whether the dragged card would land INSIDE a parent at the hovered position.
// Read from the drop data rather than from the hovered row's own level, so the
// indicator is drawn at the indent the card actually takes — a refused adoption
// (a card that has children of its own cannot become one) lands top-level even
// though the row under the pointer is indented.
const dropNested = ref(false)

// Whether this surface offers drag-to-nest (#5885). Provided as true only by a
// board that owns the card drag monitor and is in manual sort; a cross-board
// View (which renders this component with `groups` and has no monitor) gets the
// false default, so no dead affordance is shown there.
const nestEnabled = inject(NEST_ENABLED, computed(() => false))

// Built-in card sections this board still shows (#5894). A cross-board View
// renders this component without a provider and gets all-enabled, since its
// rows can mix boards with different settings.
const cardFeatures = useCardFeatures()

// Whether the viewer may EDIT this board. A row drag ends in a card move, which
// the server refuses without PERMISSION_EDIT, so a read-only member gets no drag
// handle and no grab cursor. A cross-board View renders this component without a
// provider and keeps its drag (default true) — see ../services/boardPermissions.js.
const canEditBoard = useBoardCanEdit()

// ── Group-level drop targets (column drop zone) ────────────────────────────────
// One dropTargetForElements per group header element, keyed by stackId. These
// carry { type:'column', stackId, laneKey:'' } — exactly the shape the BoardView
// card monitor's `columnTarget` branch reads. They are the ONLY drop target an
// empty group has — with no cards there is no card row to aim at — so without
// them a card can never be moved into an empty column from the list.
const groupDrops = new Map() // stackId → { el, cleanup }

const DROP_ACTIVE_CLASS = 'board-list-group-drop--active'
const DROP_OVER_CLASS = 'board-list-group-drop--over'

// Whether a card drag is currently in flight. Deliberately a plain `let`, not a
// reactive ref: the overlay's live/inert state is applied imperatively via
// classList, because ANY re-render re-invokes the inline `:ref` callbacks below
// and would otherwise tear down + re-register every group drop target in the
// middle of a drag.
let cardDragActive = false

function applyDragActive(el, active) {
	el.classList.toggle(DROP_ACTIVE_CLASS, active)
	if (!active) el.classList.remove(DROP_OVER_CLASS)
}

function setCardDragActive(active) {
	cardDragActive = active
	for (const { el } of groupDrops.values()) applyDragActive(el, active)
}

function setGroupDropRef(stackId, el) {
	// Only wire the column drop target on the classic per-stack path (stackId is
	// real). The cross-board groups path yields stackId:null → skipped here.
	if (!stackId) return
	const existing = groupDrops.get(stackId)
	// Same element on a re-render → keep the live registration. Re-registering
	// would break an in-flight drop target the pointer is already hovering.
	if (existing && existing.el === el) return
	// Vue clears a function ref with `null` before re-applying it on every
	// re-render, so only tear down when the element is genuinely gone.
	if (el === null && existing && existing.el.isConnected) return
	if (existing) {
		existing.cleanup()
		groupDrops.delete(stackId)
	}
	if (!el) return
	const cleanup = dropTargetForElements({
		element: el,
		canDrop: ({ source }) => source.data.type === 'card',
		// Match StackColumn's column drop-target data shape exactly so the BoardView
		// card monitor's `columnTarget` branch resolves it correctly. Deliberately
		// no `parentChange`: this overlay covers a whole group header and paints
		// only a generic highlight, so a drop here must stay a plain move. Taking a
		// card out of its parent needs the visible gesture — dragging the row out
		// of the indent onto another top-level row.
		getData: () => ({ type: 'column', stackId, laneKey: '' }),
		onDragEnter: () => el.classList.add(DROP_OVER_CLASS),
		onDragLeave: () => el.classList.remove(DROP_OVER_CLASS),
		onDrop: () => el.classList.remove(DROP_OVER_CLASS),
	})
	groupDrops.set(stackId, { el, cleanup })
	// A header scrolled into view mid-drag has to be droppable immediately.
	applyDragActive(el, cardDragActive)
}

// ── vCardDnd custom directive ──────────────────────────────────────────────────
// Attaches draggable + dropTarget (identical data contract as CardTile) to EVERY
// card row wrapper — top-level and indented child rows alike. The directive
// lives on the outermost <div class="board-list-row-wrap"> so the full row area
// is the drag handle and drop target. Because virtualizer recycles DOM nodes, we
// use mounted/updated/unmounted to always re-sync the registration with the
// current card binding.
//
// Data contract (built by buildCardDragData, so it cannot drift from CardTile):
//   draggable getInitialData: { type:'card', cardId, stackId, sortKey, laneKey:null,
//                               parentCardId, hasChildren }
//   dropTarget getData:       buildCardDropData(…) → either
//                               { same fields, dropMode:'nest' }               (centre band)
//                             or attachClosestEdge({ … , dropMode:'reorder',
//                                                    parentChange })           (edges)
//   dropTarget canDrop:       source.data.type === 'card' && source.data.cardId !== cardId
//
// laneKey is null (not '') because the list view is always the flat/no-swimlane
// path; the BoardView monitor's swimlane guard checks `laneKey ?? ''` so null
// collapses to '' and cross-lane drops are correctly rejected.
//
// `rowParentId` is what makes drag-OUT possible here and nowhere else: it is the
// level the row sits at (null for a top-level row, the parent's id for an
// indented child row), so a reorder drop resolves the parent the card takes at
// that position — dropping a sub-card among its own siblings keeps it a
// sub-card, dropping it at a top-level position takes it out. The kanban tile
// passes no rowParentId at all, so a tile reorder or column move can never
// change a parent the board does not draw. Detaching also stays available from
// the card's own detail panel (the keyboard-accessible path).

function makeCardDndBinding(el, { card, sortMode: mode, rowParentId }) {
	// Only wire DnD when we are in the classic per-stack path (card has a real
	// stackId), the display sort is manual, and the viewer may actually edit the
	// board — a viewer's move is refused server-side, so the grab is dead. The
	// permission is read from the injected computed rather than the binding value
	// so it cannot be weakened by a caller; the binding still CARRIES it (as
	// `canEdit`) purely so the `updated` hook below notices a mid-session flip.
	if (!card?.id || !card?.stackId || mode !== 'manual' || !canEditBoard.value) return () => {}

	const cardId = card.id
	// The row's rendered level rides BOTH payloads: as a drag source it says what
	// indent the card is being dragged out of, as a drop target what indent the
	// position offers.
	const base = buildCardDragData(card, null, rowParentId ?? null)

	return combine(
		draggable({
			element: el,
			getInitialData: () => ({ ...base }),
			onDragStart: () => { isDraggingCard.value = cardId },
			onDrop: () => { isDraggingCard.value = null },
		}),
		dropTargetForElements({
			element: el,
			canDrop: ({ source }) => source.data.type === 'card' && source.data.cardId !== cardId,
			getData: ({ input, element: el2, source }) => buildCardDropData({
				base,
				input,
				element: el2,
				source,
				nestEnabled: nestEnabled.value === true,
				rowParentId: rowParentId ?? null,
			}),
			onDrag: ({ self }) => {
				if (dropTargetCardId.value !== cardId) dropTargetCardId.value = cardId
				const nesting = self.data.dropMode === 'nest'
				const nextMode = nesting ? 'nest' : 'reorder'
				if (dropMode.value !== nextMode) dropMode.value = nextMode
				const intent = nesting ? 'reorder' : (self.data.parentChange?.intent ?? 'reorder')
				if (dropIntent.value !== intent) dropIntent.value = intent
				const nested = !nesting && self.data.landsNested === true
				if (dropNested.value !== nested) dropNested.value = nested
				// Edge line and nest highlight are mutually exclusive, so the user
				// can always tell which drop is pending before releasing.
				const edge = nesting ? null : extractClosestEdge(self.data)
				if (dropEdge.value !== edge) dropEdge.value = edge
			},
			onDragLeave: () => {
				if (dropTargetCardId.value === cardId) {
					dropTargetCardId.value = null
					dropEdge.value = null
					dropMode.value = null
					dropIntent.value = 'reorder'
					dropNested.value = false
				}
			},
			onDrop: () => {
				dropTargetCardId.value = null
				dropEdge.value = null
				dropMode.value = null
				dropIntent.value = 'reorder'
				dropNested.value = false
			},
		}),
	)
}

// Element → cleanup map. We key by the DOM element (not by cardId) because the
// virtualizer reuses elements — the same element may render different cards across
// scrolls. We always tear down the previous cleanup before installing the new one.
const cardDndCleanups = new WeakMap()

// Re-registrations postponed because a drag is in flight, flushed when it ends.
// Pragmatic's element adapter only re-evaluates a drop target on a native
// dragover, so tearing one down and re-creating it under a pointer that is
// already parked on it silently kills the drop: the new registration never
// hears about the hover. A realtime delta landing mid-drag (someone else moved
// or re-parented a card) is exactly that scenario, so re-registration waits.
const pendingRebinds = new Map() // el → binding value

function rebindCardDnd(el, value) {
	const oldCleanup = cardDndCleanups.get(el)
	if (oldCleanup) oldCleanup()
	cardDndCleanups.set(el, makeCardDndBinding(el, value))
}

function flushPendingRebinds() {
	for (const [el, value] of pendingRebinds) {
		if (el.isConnected) rebindCardDnd(el, value)
	}
	pendingRebinds.clear()
}

const vCardDnd = {
	mounted(el, binding) {
		const cleanup = makeCardDndBinding(el, binding.value)
		cardDndCleanups.set(el, cleanup)
	},
	updated(el, binding) {
		// Re-run when the bound card or sortMode changes (virtualizer recycle path).
		const prev = binding.oldValue
		const next = binding.value
		const sameCard = prev?.card?.id === next?.card?.id
			&& prev?.card?.stackId === next?.card?.stackId
			&& prev?.card?.sortKey === next?.card?.sortKey
			&& prev?.sortMode === next?.sortMode
			// The nest rules (#5885) are baked into the drop data, so a card that
			// gained/lost a parent or a child must be re-registered too.
			&& (prev?.card?.parentCardId ?? null) === (next?.card?.parentCardId ?? null)
			&& Number(prev?.card?.childProgress?.total ?? 0) === Number(next?.card?.childProgress?.total ?? 0)
			// The row's own level decides what a reorder drop does to the parent,
			// so a row that moved between the indent and the margin must re-register.
			&& (prev?.rowParentId ?? null) === (next?.rowParentId ?? null)
			// Losing (or gaining) EDIT mid-session must add or drop the drag handle.
			&& (prev?.canEdit !== false) === (next?.canEdit !== false)
		if (sameCard) {
			pendingRebinds.delete(el)
			return
		}
		if (cardDragActive) {
			// Keep the live registration until the drop; the drop data the monitor
			// actually consumes (target card id + stack) is re-resolved from the
			// cache at drop time anyway.
			pendingRebinds.set(el, next)
			return
		}
		rebindCardDnd(el, next)
	},
	unmounted(el) {
		pendingRebinds.delete(el)
		const cleanup = cardDndCleanups.get(el)
		if (cleanup) {
			cleanup()
			cardDndCleanups.delete(el)
		}
	},
}

// ── Autoscroll ────────────────────────────────────────────────────────────────
// Register autoScrollForElements on the virtualizer scroll container so dragging
// near the top/bottom edge scrolls the list, revealing off-screen groups.
let autoscrollCleanup = null

// Board-level drag monitor: flips the group drop overlays from inert to live for
// the duration of a card drag (see the .board-list-group-drop CSS for why they
// cannot be permanently live).
let dragMonitorCleanup = null

onMounted(() => {
	if (scrollRef.value) {
		autoscrollCleanup = autoScrollForElements({ element: scrollRef.value })
	}
	dragMonitorCleanup = monitorForElements({
		canMonitor: ({ source }) => source.data.type === 'card',
		onDragStart: () => setCardDragActive(true),
		// Fires for a cancelled drag too, so the overlays always go inert again.
		onDrop: () => {
			setCardDragActive(false)
			// Any row registration we held back for the duration of the drag can
			// now safely be rebuilt.
			flushPendingRebinds()
		},
	})
})

onBeforeUnmount(() => {
	if (autoscrollCleanup) {
		autoscrollCleanup()
		autoscrollCleanup = null
	}
	if (dragMonitorCleanup) {
		dragMonitorCleanup()
		dragMonitorCleanup = null
	}
	// Tear down all group drop targets
	for (const { cleanup } of groupDrops.values()) cleanup()
	groupDrops.clear()
	pendingRebinds.clear()
})

// Persisted collapse scope: per board for the classic path, or a fixed 'views'
// scope for the generalized group-by path (#3815) where there is no board id.
const collapseScope = computed(() => (props.groups ? 'views' : props.boardId))
const collapsedKey = computed(() => `kanso.listCollapsed.${collapseScope.value}`)
const collapsed = ref(loadCollapsed())

function loadCollapsed() {
	try {
		const saved = localStorage.getItem(`kanso.listCollapsed.${props.groups ? 'views' : props.boardId}`)
		if (saved) return new Set(JSON.parse(saved))
	} catch (e) { /* localStorage unavailable - default to all expanded */ }
	return new Set()
}

// Reload persisted state when the scope changes (component reused across boards/views).
watch(collapseScope, () => { collapsed.value = loadCollapsed() })

function isCollapsed(groupKey) {
	return collapsed.value.has(groupKey)
}

function toggleGroup(groupKey) {
	const next = new Set(collapsed.value)
	if (next.has(groupKey)) next.delete(groupKey)
	else next.add(groupKey)
	collapsed.value = next
	try {
		localStorage.setItem(collapsedKey.value, JSON.stringify([...next]))
	} catch (e) { /* localStorage unavailable - collapse is in-memory only */ }
}

// Per-card sub-card expand/collapse. The key is `card:<id>`.
// Absence from `collapsed` means expanded (sub-cards visible by default).
function cardCollapseKey(cardId) {
	return `card:${cardId}`
}

function isCardExpanded(cardId) {
	return !collapsed.value.has(cardCollapseKey(cardId))
}

function toggleCard(cardId) {
	const key = cardCollapseKey(cardId)
	const next = new Set(collapsed.value)
	if (next.has(key)) next.delete(key)
	else next.add(key)
	collapsed.value = next
	try {
		localStorage.setItem(collapsedKey.value, JSON.stringify([...next]))
	} catch (e) { /* localStorage unavailable - collapse is in-memory only */ }
}

// Aggregate done/total across a group's cards. A card counts toward "done" when
// it's marked done; the total is the whole group. Returns null for empty groups.
function groupProgress(cards) {
	if (!cards.length) return null
	const done = cards.reduce((n, c) => n + (Number(c.doneAt) > 0 ? 1 : 0), 0)
	return { done, total: cards.length }
}

// Reactive clock so overdue counts recompute over time (e.g. a board left open
// across midnight) rather than freezing until the card data changes.
const now = ref(Date.now())
let clockTimer = null
onMounted(() => { clockTimer = setInterval(() => { now.value = Date.now() }, 60_000) })
onBeforeUnmount(() => { if (clockTimer !== null) { clearInterval(clockTimer); clockTimer = null } })

// Secondary group hints: overdue (not done, past-due) and blocked counts. Mirrors
// the row-level isOverdue / card.blocked so the header echoes what's in the list.
function groupHints(cards) {
	let overdue = 0
	let blocked = 0
	for (const c of cards) {
		if (isOverdue(c)) overdue++
		if (c.blocked === true) blocked++
	}
	return { overdue, blocked }
}

// Normalize the two grouping sources into one shape: { key, title, color,
// wipLimit, stackId, cards }. Classic path = one group per stack (carrying its
// color + WIP limit + real stackId); generalized path (#3815) = the
// caller-supplied groups (no stack/WIP concept, stackId null → no quick-add).
const normalizedGroups = computed(() => {
	if (props.groups) {
		return props.groups.map((g) => ({ key: g.key, title: g.title, color: null, wipLimit: null, stackId: null, cards: g.cards ?? [] }))
	}
	const byStack = props.cardsByStack
	return props.stacks.map((stack) => ({
		key: `stack:${stack.id}`,
		title: stack.title,
		color: stack.color ?? null,
		wipLimit: (typeof stack.wipLimit === 'number' && stack.wipLimit > 0) ? stack.wipLimit : null,
		stackId: stack.id,
		cards: (byStack && byStack.get(stack.id)) ?? [],
	}))
})

// Quick-add is enabled only when we have the callback AND this is the classic
// per-stack path (not the generalized cross-board groups path).
const quickAddEnabled = computed(() => !props.groups && typeof props.onCreateCard === 'function')

// A flat row model: one header per group, then (when not collapsed + quick-add
// enabled) an 'add' row at the TOP, then its cards.
// Within each group, cards are arranged as a one-level parent/child tree (#4178):
//   - A card is a "child" if its parentCardId matches another card in the same group.
//   - Child rows are emitted immediately after their parent, only when expanded.
//   - A child whose parent is NOT in the same group is treated as top-level
//     (never hidden just because the parent is filtered out / in another column).
// Collapsed groups drop both the 'add' row and all card rows.
const rows = computed(() => {
	const out = []
	for (const group of normalizedGroups.value) {
		const cards = group.cards

		// Build a set of card ids present in this group so we can resolve
		// parent/child relationships within the group boundary.
		const cardIdSet = new Set(cards.map((c) => c.id))

		// Partition into top-level cards and children. A card is a child only
		// when its parentCardId is non-empty AND that parent exists in this group.
		const topLevel = []
		const childrenByParent = new Map() // parentId → child[]
		for (const card of cards) {
			const pid = card.parentCardId
			if (pid && cardIdSet.has(pid)) {
				// This card is a child of another card in the same group.
				if (!childrenByParent.has(pid)) childrenByParent.set(pid, [])
				childrenByParent.get(pid).push(card)
			} else {
				topLevel.push(card)
			}
		}

		out.push({
			type: 'header',
			id: `h${group.key}`,
			key: group.key,
			title: group.title,
			color: group.color,
			count: cards.length,
			wip: group.wipLimit !== null ? { count: cards.length, limit: group.wipLimit, over: cards.length > group.wipLimit } : null,
			progress: groupProgress(cards),
			hints: groupHints(cards),
			// stackId needed by the template to wire the column-level drop target
			// on the header element (only present in the classic per-stack path).
			stackId: group.stackId ?? null,
		})

		if (isCollapsed(group.key)) continue

		// Quick-add composer sits at the TOP of the group (above cards), matching
		// the kanban column composer position. Only when quick-add is enabled and
		// the group has a real stackId (i.e. not the cross-board groups path).
		if (quickAddEnabled.value && group.stackId != null) {
			out.push({
				type: 'add',
				id: `add${group.key}`,
				stackId: group.stackId,
				groupKey: group.key,
			})
		}

		for (const card of topLevel) {
			const children = childrenByParent.get(card.id) ?? []
			const hasChildren = children.length > 0
			out.push({ type: 'card', id: `c${card.id}`, card, hasChildren, isChild: false })

			// Emit children only when the parent is expanded (absent from collapsed = expanded).
			if (hasChildren && isCardExpanded(card.id)) {
				for (const child of children) {
					out.push({ type: 'card', id: `c${child.id}`, card: child, hasChildren: false, isChild: true })
				}
			}
		}
	}
	return out
})

// Fixed row heights (a table is uniform) — no per-row measureElement, so the
// virtualizer's positions never thrash on a data refresh.
const HEADER_H = 40
const ROW_H = 36
const ADD_H = 40
const virtualizer = useVirtualizer(computed(() => ({
	count: rows.value.length,
	getScrollElement: () => scrollRef.value,
	estimateSize: (i) => {
		const r = rows.value[i]
		if (!r) return ROW_H
		if (r.type === 'header') return HEADER_H
		if (r.type === 'add') return ADD_H
		return ROW_H
	},
	overscan: 10,
	getItemKey: (i) => rows.value[i]?.id ?? i,
})))

function openCard(card) {
	// A parent-owned overlay (cross-board View, #3950) takes precedence: hand the
	// card up and stay on the current surface instead of navigating.
	if (hasOpenHandler) {
		emit('open', card)
		return
	}
	// Prefer the card's own boardId (cross-board Views, #3815); fall back to the
	// single-board prop for the classic per-board list.
	const boardId = card.boardId ?? props.boardId
	router.push({ name: 'card-modal', params: { id: String(boardId), cardId: String(card.id) } })
}

function cardHumanId(card) {
	return humanId(props.boardPrefix, card.boardSeq)
}

// Per-card progress: prefer the checklist, fall back to child-card progress.
function cardProgress(card) {
	if (card.checklist && card.checklist.total > 0) return card.checklist
	if (card.childProgress && card.childProgress.total > 0) return card.childProgress
	return null
}

function isDone(card) {
	return Number(card.doneAt) > 0
}

function statusOf(card) {
	if (Number(card.doneAt) > 0) return 'done'
	if (Number(card.startedAt) > 0) return 'in_progress'
	return 'not_started'
}

function statusLabel(card) {
	return { done: t('kanso', 'Done'), in_progress: t('kanso', 'In progress'), not_started: t('kanso', 'Not started') }[statusOf(card)]
}

function labelColor(labelId) {
	const l = props.labelsById.get(labelId)
	return l && l.color ? cssColor(l.color) : 'var(--color-border-dark)'
}

function labelTitle(labelId) {
	const l = props.labelsById.get(labelId)
	return l ? l.title : ''
}

function priorityLabel(value) {
	return PRIORITY_LEVELS.find((l) => l.value === value)?.label ?? ''
}

function formatDue(card) {
	const d = new Date(card.duedate)
	if (Number.isNaN(d.getTime())) return ''
	// All-day dates are stored at UTC midnight; format them in UTC so the row
	// shows the picked calendar day even west of UTC (not the previous day).
	return formatCardDate(card.duedate, card.allDay === true, { month: 'short', day: 'numeric' })
}

function isOverdue(card) {
	if (!card.duedate || isDone(card)) return false
	const d = new Date(card.duedate)
	return !Number.isNaN(d.getTime()) && d.getTime() < now.value
}

// ── Quick-add composer (per-stack state) ────────────────────────────────────

/**
 * Per-stack reactive state for the composer. Keyed by stackId.
 * Using plain reactive objects lets us add keys without replacing the whole
 * object, which would lose reactivity on already-bound template expressions.
 */
const draftByStack = reactive({})        // stackId → string draft
const isPendingByStack = reactive({})    // stackId → boolean
const errorByStack = reactive({})        // stackId → string|''
const templatesByStack = reactive({})    // stackId → template[]
const templatesLoadedByStack = reactive({}) // stackId → boolean

// Composer input refs, keyed by stackId. Using a plain object (not reactive) so
// we can set DOM element refs without triggering unnecessary re-renders.
const composerRefs = {}

function setComposerRef(stackId, el) {
	if (el) {
		composerRefs[stackId] = el
	} else {
		delete composerRefs[stackId]
	}
}

/** Split submitted text into trimmed, non-blank card titles, in order. */
function splitTitles(text) {
	return text.split(/\r\n|\r|\n/).map((l) => l.trim()).filter((l) => l !== '')
}

/**
 * Multi-line paste → quick multi-add (mirrors StackColumn.onComposerPaste).
 * A single-line paste falls through to the default behaviour.
 */
function onComposerPaste(event, stackId) {
	const text = event.clipboardData?.getData('text') ?? ''
	if (splitTitles(text).length < 2) return
	event.preventDefault()
	createCardsFromText(stackId, text)
}

/**
 * Create one card per non-blank line for the given stack. When newCardsOnTop
 * is set, reverse the order so the first pasted line ends up topmost.
 */
async function createCardsFromText(stackId, text) {
	const titles = splitTitles(text)
	if (titles.length === 0) return
	errorByStack[stackId] = ''
	isPendingByStack[stackId] = true
	const ordered = props.newCardsOnTop ? [...titles].reverse() : titles
	try {
		for (const title of ordered) {
			const { title: parsedTitle, duedate, allDay } = parseDueToken(title)
			await props.onCreateCard(stackId, parsedTitle, duedate, allDay)
		}
		draftByStack[stackId] = ''
		// Re-focus for rapid entry
		composerRefs[stackId]?.focus()
	} catch (err) {
		errorByStack[stackId] = err?.response?.data?.error || t('kanso', 'Failed to create card.')
	} finally {
		isPendingByStack[stackId] = false
	}
}

async function submitCard(stackId) {
	const text = draftByStack[stackId] ?? ''
	await createCardsFromText(stackId, text)
}

/** Lazily load templates when the picker opens for a given stack. */
async function loadTemplates(stackId) {
	if (!props.onFetchTemplates) return
	try {
		templatesByStack[stackId] = await props.onFetchTemplates()
	} catch (err) {
		errorByStack[stackId] = err?.response?.data?.error || t('kanso', 'Failed to load templates.')
	} finally {
		templatesLoadedByStack[stackId] = true
	}
}

/** Create a card from a template in the given stack. */
async function createFromTemplate(stackId, templateId) {
	if (!props.onCreateFromTemplate) return
	errorByStack[stackId] = ''
	isPendingByStack[stackId] = true
	try {
		await props.onCreateFromTemplate(stackId, templateId)
	} catch (err) {
		errorByStack[stackId] = err?.response?.data?.error || t('kanso', 'Failed to create card from template.')
	} finally {
		isPendingByStack[stackId] = false
	}
}

// ── Column composer (#9853) ──────────────────────────────────────────────────
// Only on the classic per-stack path: the cross-board Views path renders
// arbitrary groups with no stack behind them, so "add column" is meaningless
// there (the same reason setGroupDropRef skips a null stackId). Non-manual sort
// modes are NOT gated: unlike a drag, creating a column doesn't touch any sort
// key, and kanban offers it in every sort mode.
const addColumnEnabled = computed(() => !props.groups && typeof props.onCreateStack === 'function')

const addColumnTitle = ref('')
const addColumnError = ref('')
const addColumnPending = ref(false)
const addColumnInputRef = ref(null)

// A failure message describes the title that failed, so it goes stale the moment
// that title is edited — otherwise the user retypes and still stares at the old
// error next to text that is now fine.
watch(addColumnTitle, () => { addColumnError.value = '' })

async function submitNewColumn() {
	const title = addColumnTitle.value.trim()
	if (!title || addColumnPending.value || !props.onCreateStack) return
	addColumnError.value = ''
	addColumnPending.value = true
	try {
		await props.onCreateStack(title)
		addColumnTitle.value = ''
	} catch (err) {
		addColumnError.value = err?.response?.data?.error || t('kanso', 'Failed to create column.')
	} finally {
		addColumnPending.value = false
	}
	// Re-focus for rapid entry, mirroring the card composer — and after a failure
	// too, so the title can be corrected and retried. Must come after the pending
	// flag clears: a disabled input cannot take focus.
	await nextTick()
	addColumnInputRef.value?.focus()
}

/**
 * Reveal + focus the column composer. This is what the board's ⋯ More →
 * "Add column" action calls while list view is active — one menu action, two
 * targets, instead of one that quietly aims at the hidden kanban track.
 */
function focusAddColumn() {
	if (!addColumnEnabled.value) return
	addColumnError.value = ''
	nextTick(() => {
		addColumnInputRef.value?.scrollIntoView?.({ block: 'nearest' })
		addColumnInputRef.value?.focus()
	})
}

defineExpose({ focusAddColumn })
</script>

<style scoped>
.board-list-table {
	/* Legible status colours for the row chips (priority, overdue, review). NC's
	 * base --color-error / --color-warning / --color-success dark shades are near
	 * black (#552121 / #3D3010 / #11321A) and vanish as text on the dark list
	 * surface; brighten them under dark themes while keeping the stock values in
	 * light mode (#3905/#4054 pattern). Scoped so they can't leak. */
	--kanso-error-legible: var(--color-error, #e30000);
	--kanso-warning-legible: var(--color-warning, #c98600);
	--kanso-success-legible: var(--color-success, #2fb344);


	flex: 1;
	min-height: 0;
	overflow-y: auto;
	padding: 8px 24px 24px 52px;
}

/* Explicit dark themes (theme picker) + auto (prefers-color-scheme) dark. */
body.theme--dark .board-list-table,
[data-theme-dark] .board-list-table,
[data-themes*='dark'] .board-list-table {
	--kanso-error-legible: #ff6b6b;
	--kanso-warning-legible: #d29922;
	--kanso-success-legible: #5ad07f;
}

@media (prefers-color-scheme: dark) {
	body.theme--default .board-list-table,
	body:not(.theme--light):not(.theme--dark) .board-list-table {
		--kanso-error-legible: #ff6b6b;
		--kanso-warning-legible: #d29922;
		--kanso-success-legible: #5ad07f;
	}
}


.board-list-table__host {
	position: relative;
	width: 100%;
	max-width: 1100px;
}

.board-list-table__empty {
	color: var(--color-text-maxcontrast);
	padding: 24px 0;
}

.board-list-table__vrow {
	overflow: hidden;
}

.board-list-group {
	display: flex;
	align-items: center;
	gap: 10px;
	box-sizing: border-box;
	width: 100%;
	height: 100%;
	padding: 0 8px;
	background: var(--color-background-hover);
	border: none;
	border-bottom: 1px solid var(--color-border);
	border-radius: 0;
	text-align: start;
	cursor: pointer;
	color: var(--color-main-text);
}

.board-list-group:hover {
	background: var(--color-background-dark);
}

.board-list-group__chevron {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
}

.board-list-group__dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: var(--color-primary-element);
	flex: 0 0 auto;
}

.board-list-group__title {
	font-weight: 700;
	text-transform: uppercase;
	font-size: 0.8rem;
	letter-spacing: 0.03em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.board-list-group__count {
	color: var(--color-text-maxcontrast);
	font-weight: 400;
	font-size: 0.8rem;
	flex: 0 0 auto;
}

.board-list-group__wip {
	display: inline-flex;
	align-items: center;
	height: 20px;
	padding: 0 7px;
	border-radius: 10px;
	font-size: 0.72rem;
	font-weight: 700;
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
}

.board-list-group__wip--over {
	color: color-mix(in srgb, var(--kanso-warning-legible) 85%, var(--color-main-text));
	background: color-mix(in srgb, var(--kanso-warning-legible) 25%, transparent);
	outline: 1px solid color-mix(in srgb, var(--kanso-warning-legible) 50%, transparent);
}

/* Secondary hints (overdue · blocked) sit just after the count, muted; the
   progress badge still anchors to the far end. */
.board-list-group__hints {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	flex: 0 0 auto;
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.board-list-group__hint {
	white-space: nowrap;
}

.board-list-group__hint + .board-list-group__hint::before {
	content: '·';
	margin-inline-end: 6px;
	color: var(--color-text-maxcontrast);
}

.board-list-group__hint--overdue {
	color: color-mix(in srgb, var(--kanso-error-legible) 80%, var(--color-text-maxcontrast));
	font-weight: 600;
}

.board-list-group__progress {
	margin-inline-start: auto;
	font-size: 0.75rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	flex: 0 0 auto;
}

/* Drop target overlaid on the group header so drops onto the header area (an
   empty group, which has no card row to aim at) route to the column-target
   branch in the BoardView monitor.

   It must be inert while nothing is being dragged — it covers the header
   button, whose click toggles the group collapse. But it must NOT be inert
   during a drag: Pragmatic's element adapter resolves drop targets from the
   native drag event's `target`, and native dragenter/dragover hit-testing skips
   `pointer-events: none` elements, so a permanently-inert overlay can never
   receive a drop. Hence inert by default, live only while a card drag is in
   flight (class toggled imperatively from the drag monitor in the script). */
.board-list-group-drop {
	position: absolute;
	inset: 0;
	pointer-events: none;
}

.board-list-group-drop--active {
	pointer-events: auto;
}

/* Highlight the whole group header while a dragged card hovers it, so the
   pending drop is visible (the card rows use edge lines instead). */
.board-list-group-drop--over {
	background: color-mix(in srgb, var(--color-primary-element) 18%, transparent);
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
	border-radius: var(--border-radius, 3px);
}

/* ── Quick-add composer ─────────────────────────────────────────────────────── */

/* The composer row aligns with card rows: same left padding as a top-level
   card row (8px) so the input left-edge lines up with the card title. */
.card-composer-wrap {
	display: flex;
	align-items: center;
	box-sizing: border-box;
	width: 100%;
	height: 100%;
	padding: 0 8px;
	gap: 4px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-hover);
}

.card-composer {
	flex: 1;
	min-width: 0;
	display: flex;
}

.card-composer__input {
	flex: 1;
	min-width: 0;
	height: 28px;
	padding: 0 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius, 3px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.875rem;
}

.card-composer__input:focus {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -1px;
	border-color: transparent;
}

.card-composer__input::placeholder {
	color: var(--color-text-maxcontrast);
}

.card-composer__templates {
	flex: 0 0 auto;
}

.card-composer__error {
	flex: 0 0 auto;
	font-size: 0.75rem;
	color: var(--kanso-error-legible);
	margin: 0;
	white-space: nowrap;
}

/* ── Column composer ────────────────────────────────────────────────────────── */

/* Sits after the virtualized row host (outside the virtualizer), so it reads as
   the end of the group list. Same max-width as the host so its input lines up
   with the rows above it. */
.add-column-composer {
	display: flex;
	align-items: center;
	gap: 8px;
	box-sizing: border-box;
	width: 100%;
	max-width: 1100px;
	padding: 10px 8px 0;
}

.add-column-composer__input {
	flex: 1;
	min-width: 0;
	max-width: 320px;
	height: 28px;
	padding: 0 8px;
	border: 1px dashed var(--color-border-dark);
	border-radius: var(--border-radius, 3px);
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.875rem;
}

.add-column-composer__input:focus {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -1px;
	border-color: transparent;
	background: var(--color-main-background);
}

.add-column-composer__input::placeholder {
	color: var(--color-text-maxcontrast);
}

.add-column-composer__error {
	flex: 0 0 auto;
	font-size: 0.75rem;
	color: var(--kanso-error-legible);
	margin: 0;
}

/* ── Card row wrapper (DnD host) ────────────────────────────────────────────── */

/* The wrapper is a block that fills the vrow slot and positions the drop
   indicator lines (absolute) relative to itself. */
.board-list-row-wrap {
	position: relative;
	width: 100%;
	height: 100%;
}

/* While dragging THIS card, dim it slightly so the ghost is distinguishable. */
.board-list-row-wrap--dragging > .board-list-row {
	opacity: 0.45;
}

/* ── Drop indicator lines ───────────────────────────────────────────────────── */

/* A 2px coloured line that appears at the top or bottom edge of the hovered row,
   matching the kanban card tile's closest-edge indicator style. */
.board-list-drop-indicator {
	position: absolute;
	inset-inline: 0;
	height: 2px;
	background: var(--color-primary-element);
	border-radius: 1px;
	pointer-events: none;
	z-index: 10;
}

.board-list-drop-indicator--top {
	top: 0;
}

.board-list-drop-indicator--bottom {
	bottom: 0;
}

/* The line is drawn at the INDENT of the position it marks: flush left for a
   top-level slot, inset to the child gutter for a slot inside a parent's
   children. Dragging a sub-card out of its parent is then visible as the line
   snapping back to the margin, before anything is committed. */
.board-list-drop-indicator--nested {
	/* Logical, so it matches the child row's own padding-inline-start under RTL. */
	inset-inline-start: 46px;
}

/* …and an un-nesting drop (the card leaves its parent) is drawn apart from a
   plain reorder: a thicker dashed rule in the warning tone, so "this changes
   the hierarchy" never reads as "this just moves the row". */
.board-list-drop-indicator--unnest {
	height: 0;
	background: none;
	border-top: 3px dashed var(--kanso-warning-legible);
}

/* ── Nest drop affordance (#5885) ─────────────────────────────────────────────
 * The centre band of a row nests the dragged card under it; the edges still
 * reorder. Nesting therefore must NOT look like the 2px edge line — it tints
 * the whole row and grows an inset left bar in the same gutter position the
 * child rows use for their indent guide, previewing the indent the card is
 * about to take. */
.board-list-row-wrap--nest-target > .board-list-row {
	background: color-mix(in srgb, var(--color-primary-element) 14%, transparent);
	box-shadow: inset 20px 0 0 -18px var(--color-primary-element);
	outline: 2px dashed var(--color-primary-element);
	outline-offset: -2px;
	border-radius: var(--border-radius, 3px);
}

/* Inline "this becomes a sub-card" label, pinned to the row's right edge so it
   never reflows the row (the virtualizer assumes fixed row heights). */
.board-list-nest-hint {
	position: absolute;
	inset-inline-end: 8px;
	top: 50%;
	transform: translateY(-50%);
	z-index: 11;
	pointer-events: none;
	padding: 1px 6px;
	border-radius: var(--border-radius, 3px);
	border: 1px dashed var(--color-primary-element);
	background: var(--color-main-background);
	color: var(--color-primary-element);
	font-size: 0.68rem;
	font-weight: 600;
	white-space: nowrap;
}

/* The opposite move: this drop takes the dragged card OUT of its parent. Same
   pill, the warning tone of the un-nest rule — never the primary colour the
   nest affordance owns. */
.board-list-nest-hint--unnest {
	border-color: var(--kanso-warning-legible);
	color: var(--kanso-warning-legible);
}

/* ── Card rows ──────────────────────────────────────────────────────────────── */

.board-list-row {
	display: flex;
	align-items: center;
	gap: 10px;
	box-sizing: border-box;
	width: 100%;
	height: 100%;
	padding: 0 8px;
	background: transparent;
	border: none;
	border-bottom: 1px solid var(--color-border);
	border-radius: 0;
	text-align: start;
	cursor: pointer;
	color: var(--color-main-text);
}

.board-list-row:hover {
	background: var(--color-background-hover);
}

/* Show a grab cursor on draggable rows (manual sort only) to signal that the
   row can be picked up. The cursor changes to 'grabbing' automatically during
   the drag via Pragmatic's drag-preview behaviour. */
.board-list-row--draggable {
	cursor: grab;
}

.board-list-row--draggable:active {
	cursor: grabbing;
}

/* Child rows are indented to visually nest under their parent, with a faint
   vertical guide in the gutter so the hierarchy reads clearly. */
.board-list-row--child {
	padding-inline-start: 46px;
	position: relative;
}

.board-list-row--child::before {
	content: '';
	position: absolute;
	inset-inline-start: 20px;
	top: 0;
	bottom: 0;
	width: 2px;
	background: var(--color-border);
	border-radius: 1px;
}

/* Expand/collapse caret for parent cards that have children in the same group. */
.board-list-row__caret {
	flex: 0 0 auto;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 20px;
	height: 20px;
	border-radius: 4px;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	/* Reset inherited button styles that might leak from the parent <button>. */
	background: none;
	border: none;
	padding: 0;
}

.board-list-row__caret:hover {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.board-list-row__caret:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 1px;
}

.board-list-row__caret-icon {
	display: flex;
}

/* Invisible spacer so the status dot aligns with parent rows on cards
   that have no children (and are not children themselves). */
.board-list-row__caret-spacer {
	flex: 0 0 20px;
	height: 20px;
}

.board-list-row__status {
	flex: 0 0 auto;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	border: 1px solid var(--color-border-dark);
}

.board-list-row__status--not_started { background: transparent; }
.board-list-row__status--in_progress { background: var(--color-primary-element); border-color: var(--color-primary-element); }
.board-list-row__status--done { background: var(--kanso-success-legible); border-color: var(--kanso-success-legible); }

.board-list-row__id {
	flex: 0 0 auto;
	width: 70px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.72rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.board-list-row__labels {
	display: flex;
	gap: 3px;
	flex: 0 0 auto;
}

.board-list-row__title {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 0.875rem;
}

.board-list-row__title--done {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

.board-list-row__meta {
	display: flex;
	align-items: center;
	gap: 14px;
	flex: 0 0 auto;
	margin-inline-start: auto;
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
}

.board-list-row__label-dot {
	width: 8px;
	height: 8px;
	border-radius: 2px;
	flex: 0 0 auto;
}

.board-list-row__priority {
	font-size: 0.75rem;
	font-weight: 600;
	padding: 1px 6px;
	border-radius: 8px;
	background: var(--color-background-dark);
}

.board-list-row__priority--4 { color: var(--kanso-error-legible); }
.board-list-row__priority--3 { color: var(--kanso-warning-legible); }

.board-list-row__due,
.board-list-row__count {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	white-space: nowrap;
}

.board-list-row__due--overdue {
	color: var(--kanso-error-legible);
	font-weight: 600;
}

.board-list-row__review--approved { color: var(--kanso-success-legible); }
.board-list-row__review--changes { color: var(--kanso-error-legible); }

/* Timer running (#73) - pulsing green clock icon in the meta area. */
.board-list-row__timer-running {
	color: var(--kanso-success-legible);
	animation: kanso-list-timer-pulse 2s ease-in-out infinite;
}

@keyframes kanso-list-timer-pulse {
	0%, 100% { opacity: 1; }
	50% { opacity: 0.45; }
}

.board-list-row__assignees {
	display: inline-flex;
}

.board-list-row__assignees > * + * {
	margin-inline-start: -8px;
}
</style>
