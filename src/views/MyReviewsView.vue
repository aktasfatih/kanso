<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="my-reviews-view">
		<div v-if="!embedded" class="my-reviews-view__header">
			<h1 class="my-reviews-view__title">{{ t('kanso', 'My Reviews') }}</h1>
		</div>

		<!-- Loading state -->
		<div v-if="isLoading" class="my-reviews-view__loading" aria-live="polite">
			<span class="my-reviews-view__spinner" aria-hidden="true" />
			<span>{{ t('kanso', 'Loading reviews…') }}</span>
		</div>

		<!-- Error state -->
		<div v-else-if="isError" class="my-reviews-view__error">
			{{ t('kanso', 'Failed to load reviews. Please try again.') }}
		</div>

		<!-- All-empty state -->
		<NcEmptyContent
			v-else-if="!hasPending && !hasChangesRequested && !hasApproved"
			:name="t('kanso', 'No reviews assigned to you')"
			:description="t('kanso', 'When someone requests your review on a card it will appear here.')">
			<template #icon>
				<CheckDecagramIcon :size="64" />
			</template>
		</NcEmptyContent>

		<!-- Grouped sections -->
		<template v-else>
			<!-- Needs your review (pending) -->
			<section v-if="hasPending" class="my-reviews-view__section">
				<h2 class="my-reviews-view__section-title">{{ t('kanso', 'Needs your review') }}</h2>
				<ul class="my-reviews-view__list">
					<li
						v-for="review in pendingReviews"
						:key="review.id"
						class="my-reviews-view__row my-reviews-view__row--pending">
						<ReviewRow
							:review="review"
							@open="openCard(review)"
							@approve="setVerdict(review, 'approved')"
							@request-changes="setVerdict(review, 'changes_requested')"
							:is-mutating="mutatingCardId === review.cardId" />
					</li>
				</ul>
			</section>

			<!-- Changes requested -->
			<section v-if="hasChangesRequested" class="my-reviews-view__section">
				<h2 class="my-reviews-view__section-title">{{ t('kanso', 'Changes requested') }}</h2>
				<ul class="my-reviews-view__list">
					<li
						v-for="review in changesRequestedReviews"
						:key="review.id"
						class="my-reviews-view__row my-reviews-view__row--changes-requested">
						<ReviewRow
							:review="review"
							@open="openCard(review)"
							@approve="setVerdict(review, 'approved')"
							@request-changes="setVerdict(review, 'changes_requested')"
							:is-mutating="mutatingCardId === review.cardId" />
					</li>
				</ul>
			</section>

			<!-- Approved -->
			<section v-if="hasApproved" class="my-reviews-view__section">
				<h2 class="my-reviews-view__section-title">{{ t('kanso', 'Approved') }}</h2>
				<ul class="my-reviews-view__list">
					<li
						v-for="review in approvedReviews"
						:key="review.id"
						class="my-reviews-view__row my-reviews-view__row--approved">
						<ReviewRow
							:review="review"
							@open="openCard(review)"
							:is-mutating="false" />
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import { useMyReviews } from '../composables/useMyReviews.js'
import ReviewRow from '../components/ReviewRow.vue'

const props = defineProps({
	embedded: { type: Boolean, default: false },
	boardFilter: { type: Number, default: null },
})

const router = useRouter()
const { data: reviews, isLoading, isError, setState } = useMyReviews()

// Track which card currently has an in-flight mutation so rows can disable
// their buttons individually without blocking the whole list.
const mutatingCardId = ref(null)

/** All reviews after applying the optional board filter from the hub. */
const filteredReviews = computed(() => {
	const all = reviews.value ?? []
	return props.boardFilter === null
		? all
		: all.filter((r) => r.boardId === props.boardFilter)
})

const pendingReviews = computed(() =>
	filteredReviews.value.filter((r) => r.state === 'pending'),
)
const changesRequestedReviews = computed(() =>
	filteredReviews.value.filter((r) => r.state === 'changes_requested'),
)
const approvedReviews = computed(() =>
	filteredReviews.value.filter((r) => r.state === 'approved'),
)

const hasPending = computed(() => pendingReviews.value.length > 0)
const hasChangesRequested = computed(() => changesRequestedReviews.value.length > 0)
const hasApproved = computed(() => approvedReviews.value.length > 0)

function openCard(review) {
	// Thread the origin so CardModal returns to My Work on close (see MyCardsView).
	const from = props.embedded ? 'my-work' : 'my-reviews'
	const query = props.embedded ? { from, tab: 'reviews' } : { from }
	router.push({ name: 'card-modal', params: { id: review.boardId, cardId: review.cardId }, query })
}

async function setVerdict(review, state) {
	mutatingCardId.value = review.cardId
	try {
		await setState.mutateAsync({ cardId: review.cardId, reviewId: review.id, boardId: review.boardId, state })
	} finally {
		mutatingCardId.value = null
	}
}
</script>

<style scoped>
.my-reviews-view {
	padding: 24px 32px;
	max-width: 860px;
}

.my-reviews-view__header {
	margin-bottom: 24px;
}

.my-reviews-view__title {
	font-size: 1.5rem;
	font-weight: 600;
	margin: 0;
}

.my-reviews-view__loading {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-text-maxcontrast);
	padding: 32px 0;
}

.my-reviews-view__spinner {
	display: inline-block;
	width: 20px;
	height: 20px;
	border: 2px solid var(--color-border);
	border-top-color: var(--color-primary-element);
	border-radius: 50%;
	animation: my-reviews-spin 0.7s linear infinite;
}

@keyframes my-reviews-spin {
	to { transform: rotate(360deg); }
}

.my-reviews-view__error {
	color: var(--color-error);
	padding: 16px 0;
}

.my-reviews-view__section {
	margin-bottom: 36px;
}

.my-reviews-view__section-title {
	font-size: 0.85rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	color: var(--color-text-maxcontrast);
	margin: 0 0 10px;
}

.my-reviews-view__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.my-reviews-view__row {
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
	transition: background 0.15s;
}

.my-reviews-view__row:hover {
	background: var(--color-border-dark);
}
</style>
