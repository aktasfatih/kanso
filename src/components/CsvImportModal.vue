<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal size="normal" @close="$emit('close')">
		<div class="csv-import" data-test="csv-import">
			<h2 class="csv-import__title">{{ t('kanso', 'Import cards from CSV') }}</h2>

			<!-- Step 1: pick a source (upload or paste). -->
			<template v-if="step === 'source'">
				<p class="csv-import__hint">
					{{ t('kanso', 'Upload or paste a CSV of tasks. The rows are added as cards to a board and column you choose. The first row is treated as headers.') }}
				</p>

				<div class="csv-import__source">
					<NcButton @click="fileInput?.click()">
						<template #icon>
							<UploadIcon :size="20" />
						</template>
						{{ t('kanso', 'Choose a CSV file') }}
					</NcButton>
					<span class="csv-import__or">{{ t('kanso', 'or paste below') }}</span>
				</div>
				<input
					ref="fileInput"
					type="file"
					accept="text/csv,.csv"
					class="csv-import__file"
					data-test="csv-import-file"
					@change="onFileChange">

				<textarea
					v-model="rawText"
					class="csv-import__paste"
					rows="6"
					data-test="csv-import-paste"
					:placeholder="t('kanso', 'title,description,due date,labels,assignees\nDesign login,Wireframe the flow,2026-02-01,ux,alice')" />

				<p v-if="parseError" class="csv-import__error" data-test="csv-import-error">{{ parseError }}</p>

				<div class="csv-import__actions">
					<NcButton type="primary" :disabled="!rawText.trim()" data-test="csv-import-next" @click="toMapping">
						{{ t('kanso', 'Next') }}
					</NcButton>
				</div>
			</template>

			<!-- Step 2: choose board + stack, map columns, confirm. -->
			<template v-else>
				<div class="csv-import__grid">
					<label class="csv-import__field">
						<span>{{ t('kanso', 'Board') }}</span>
						<select v-model="targetBoardId" data-test="csv-import-board" @change="onBoardChange">
							<option :value="null" disabled>{{ t('kanso', 'Choose a board…') }}</option>
							<option v-for="b in editableBoards" :key="b.id" :value="b.id">{{ b.title }}</option>
						</select>
					</label>
					<label class="csv-import__field">
						<span>{{ t('kanso', 'Column (stack)') }}</span>
						<select v-model="targetStackId" data-test="csv-import-stack" :disabled="stacksLoading || stacks.length === 0">
							<option :value="null" disabled>{{ stacksLoading ? t('kanso', 'Loading…') : t('kanso', 'Choose a column…') }}</option>
							<option v-for="s in stacks" :key="s.id" :value="s.id">{{ s.title }}</option>
						</select>
					</label>
				</div>

				<h3 class="csv-import__subtitle">{{ t('kanso', 'Map columns') }}</h3>
				<div class="csv-import__grid">
					<label v-for="field in fields" :key="field.key" class="csv-import__field">
						<span>{{ field.label }}<em v-if="field.required"> *</em></span>
						<select v-model="mapping[field.key]" :data-test="'csv-map-' + field.key">
							<option :value="null">{{ field.required ? t('kanso', 'Choose a column…') : t('kanso', '— none —') }}</option>
							<option v-for="(h, i) in headers" :key="i" :value="i">{{ h || t('kanso', 'Column {n}', { n: i + 1 }) }}</option>
						</select>
					</label>
				</div>

				<p class="csv-import__count">
					{{ n('kanso', '%n data row detected', '%n data rows detected', dataRowCount) }}
				</p>
				<p v-if="parseError" class="csv-import__error" data-test="csv-import-error">{{ parseError }}</p>

				<div class="csv-import__actions">
					<NcButton :disabled="importing" @click="step = 'source'">{{ t('kanso', 'Back') }}</NcButton>
					<NcButton
						type="primary"
						:disabled="!canImport"
						data-test="csv-import-submit"
						@click="doImport">
						{{ importing ? t('kanso', 'Importing…') : t('kanso', 'Import cards') }}
					</NcButton>
				</div>
			</template>
		</div>
	</NcModal>
</template>

<script setup>
import { ref, computed } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import UploadIcon from 'vue-material-design-icons/Upload.vue'
import { fetchBoards, fetchBoard, importCsvCards } from '../services/api.js'

const emit = defineEmits(['close', 'imported'])

const step = ref('source')
const rawText = ref('')
const parseError = ref('')
const fileInput = ref(null)

// Parsed CSV state.
const headers = ref([])
const dataRowCount = ref(0)

// Target + mapping.
const boards = ref([])
const targetBoardId = ref(null)
const stacks = ref([])
const stacksLoading = ref(false)
const targetStackId = ref(null)
const importing = ref(false)

const mapping = ref({
	title: null,
	description: null,
	duedate: null,
	labels: null,
	assignees: null,
})

const fields = [
	{ key: 'title', label: t('kanso', 'Title'), required: true },
	{ key: 'description', label: t('kanso', 'Description'), required: false },
	{ key: 'duedate', label: t('kanso', 'End date'), required: false },
	{ key: 'labels', label: t('kanso', 'Labels (comma-separated)'), required: false },
	{ key: 'assignees', label: t('kanso', 'Assignees (uids, comma-separated)'), required: false },
]

// The boards-list payload is summary-only (no per-board permission bits), so we
// list every non-archived board; EDIT is authoritatively enforced server-side
// and a READ-only choice is surfaced as a clear error on import.
const editableBoards = computed(() =>
	boards.value.filter((b) => !b.archived),
)

const canImport = computed(() =>
	!importing.value
	&& targetBoardId.value !== null
	&& targetStackId.value !== null
	&& mapping.value.title !== null
	&& dataRowCount.value > 0,
)

// A minimal CSV lexer good enough to preview headers + count rows in the browser
// (quoted fields, escaped quotes, embedded newlines). The authoritative parse
// happens server-side; this is only for the mapping UI.
function parseCsv(text) {
	const rows = []
	let row = []
	let field = ''
	let inQuotes = false
	for (let i = 0; i < text.length; i++) {
		const c = text[i]
		if (inQuotes) {
			if (c === '"') {
				if (text[i + 1] === '"') { field += '"'; i++ } else { inQuotes = false }
			} else { field += c }
		} else if (c === '"') {
			inQuotes = true
		} else if (c === ',') {
			row.push(field); field = ''
		} else if (c === '\n' || c === '\r') {
			if (c === '\r' && text[i + 1] === '\n') i++
			row.push(field); field = ''
			rows.push(row); row = []
		} else {
			field += c
		}
	}
	if (field !== '' || row.length > 0) { row.push(field); rows.push(row) }
	// Drop trailing fully-empty rows.
	return rows.filter((r) => !(r.length === 1 && r[0].trim() === ''))
}

function autoDetect() {
	// Header-name → field key auto-detection (case-insensitive contains).
	const norm = headers.value.map((h) => (h || '').toLowerCase().trim())
	const pick = (matchers) => {
		for (let i = 0; i < norm.length; i++) {
			if (matchers.some((m) => norm[i] === m || norm[i].includes(m))) return i
		}
		return null
	}
	mapping.value.title = pick(['title', 'name', 'task', 'summary']) ?? 0
	mapping.value.description = pick(['description', 'desc', 'notes', 'body'])
	mapping.value.duedate = pick(['due date', 'due', 'deadline', 'duedate'])
	mapping.value.labels = pick(['labels', 'label', 'tags', 'tag'])
	mapping.value.assignees = pick(['assignees', 'assignee', 'owner', 'assigned to'])
}

async function onFileChange(event) {
	const file = event.target.files?.[0]
	event.target.value = ''
	if (!file) return
	rawText.value = await file.text()
	toMapping()
}

function toMapping() {
	parseError.value = ''
	const rows = parseCsv(rawText.value)
	if (rows.length < 2) {
		parseError.value = t('kanso', 'The CSV needs a header row and at least one data row.')
		return
	}
	headers.value = rows[0]
	dataRowCount.value = rows.length - 1
	autoDetect()
	if (boards.value.length === 0) loadBoards()
	step.value = 'mapping'
}

async function loadBoards() {
	try {
		boards.value = await fetchBoards()
	} catch {
		parseError.value = t('kanso', 'Could not load your boards.')
	}
}

async function onBoardChange() {
	targetStackId.value = null
	stacks.value = []
	if (targetBoardId.value === null) return
	stacksLoading.value = true
	try {
		const board = await fetchBoard(targetBoardId.value)
		stacks.value = (board.stacks ?? []).filter((s) => !s.archived)
	} catch {
		parseError.value = t('kanso', 'Could not load that board’s columns.')
	} finally {
		stacksLoading.value = false
	}
}

async function doImport() {
	if (!canImport.value) return
	parseError.value = ''
	importing.value = true
	// Only send the fields the user actually mapped (title is always present).
	const sent = { title: mapping.value.title }
	for (const key of ['description', 'duedate', 'labels', 'assignees']) {
		if (mapping.value[key] !== null) sent[key] = mapping.value[key]
	}
	try {
		const res = await importCsvCards(targetBoardId.value, targetStackId.value, rawText.value, sent, true)
		emit('imported', { boardId: targetBoardId.value, ...res })
	} catch (err) {
		parseError.value = err?.response?.data?.error || t('kanso', 'Could not import that CSV.')
	} finally {
		importing.value = false
	}
}
</script>

<style scoped>
.csv-import {
	padding: 24px;
	display: flex;
	flex-direction: column;
	gap: 14px;
}

.csv-import__title {
	font-size: 1.25rem;
	font-weight: 700;
	margin: 0;
}

.csv-import__subtitle {
	font-size: 1rem;
	font-weight: 600;
	margin: 4px 0 0;
}

.csv-import__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.csv-import__source {
	display: flex;
	align-items: center;
	gap: 12px;
}

.csv-import__or {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.csv-import__file {
	display: none;
}

.csv-import__paste {
	width: 100%;
	box-sizing: border-box;
	font-family: monospace;
	font-size: 0.85rem;
	resize: vertical;
}

.csv-import__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 10px 16px;
}

.csv-import__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 0.85rem;
}

.csv-import__field span {
	color: var(--color-text-maxcontrast);
}

.csv-import__field em {
	color: var(--color-error);
	font-style: normal;
}

.csv-import__field select {
	height: 34px;
}

.csv-import__count {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	margin: 0;
}

.csv-import__error {
	color: var(--color-error);
	font-size: 0.85rem;
	margin: 0;
}

.csv-import__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 4px;
}
</style>
