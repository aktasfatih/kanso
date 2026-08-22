<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
MarkdownEditor — inline WYSIWYG markdown editor for card descriptions and comments.

The editor stores and emits plain Markdown (v-model). Internally it uses Tiptap
(ProseMirror) for live WYSIWYG rendering, with tiptap-markdown handling the
serialisation / deserialisation. On the wire the server always receives and returns
raw markdown; the read-view (renderMarkdown + DOMPurify in services/markdown.js)
is completely unchanged.

Props:
  modelValue    — markdown string (v-model)
  placeholder   — placeholder string shown in empty editor
  disabled      — disabled / read-only state
  autofocus     — focus editor on mount
  minHeight     — CSS min-height for the editor content area (default: '120px')
  participants  — Array<{uid: string, displayName: string}> for @-mention autocomplete
  uploadImage   — async (file: File) => {id: number, filename: string}  (image paste upload)
  inlineUrl     — (attachmentId: number) => string  (attachment inline URL builder)

Emits:
  update:modelValue  — on every change (markdown string)
  submit             — on Ctrl/Cmd+Enter
  escape             — on Escape (when mention dropdown is NOT open)
-->
<template>
	<div
		class="kanso-md-editor"
		:class="{ 'kanso-md-editor--disabled': disabled, 'kanso-md-editor--focused': isFocused }"
		:style="editorStyle">
		<!-- @-mention suggestion dropdown — rendered as a floating list above the editor -->
		<Teleport to="body">
			<ul
				v-if="mentionOpen && mentionItems.length > 0"
				class="kanso-md-editor__mention-dropdown"
				:style="mentionDropdownStyle"
				@mousedown.prevent>
				<li
					v-for="(item, idx) in mentionItems"
					:key="item.uid"
					class="kanso-md-editor__mention-item"
					:class="{ 'kanso-md-editor__mention-item--highlighted': idx === mentionHighlighted }"
					@mousedown.prevent="selectMention(idx)">
					<NcAvatar
						:user="item.uid"
						:display-name="item.displayName"
						:size="22"
						:hide-status="true"
						:disable-tooltip="true" />
					<span class="kanso-md-editor__mention-name">{{ item.displayName }}</span>
				</li>
			</ul>
		</Teleport>
		<!-- Tiptap editor container -->
		<EditorContent
			class="kanso-md-editor__content"
			:editor="editor" />
	</div>
</template>

<script setup>
import { ref, watch, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import { Markdown } from 'tiptap-markdown'
import BaseMention from '@tiptap/extension-mention'
import Placeholder from '@tiptap/extension-placeholder'

// tiptap-markdown has no built-in serializer for the mention node. With
// html:false its default (HTMLNode) fallback would emit the literal string
// "[mention]" instead of the handle. Give the node an explicit markdown
// serialize spec so it round-trips to plain `@username` — the exact form the
// server parses for notifications/subscriptions (lib/Service/MentionService.php)
// and the read view renders as a chip (services/markdown.js). renderText only
// affects ProseMirror's getText/copy, NOT the markdown serializer, so it is not
// enough on its own.
const Mention = BaseMention.extend({
	addStorage() {
		return {
			...(this.parent?.() ?? {}),
			markdown: {
				serialize(state, node) {
					state.write(`@${node.attrs.id}`)
				},
				parse: {},
			},
		}
	},
})
import { Extension } from '@tiptap/core'
import { Plugin, PluginKey } from '@tiptap/pm/state'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'

// ── Props / emits ─────────────────────────────────────────────────────────────
const props = defineProps({
	modelValue: {
		type: String,
		default: '',
	},
	placeholder: {
		type: String,
		default: '',
	},
	disabled: {
		type: Boolean,
		default: false,
	},
	autofocus: {
		type: Boolean,
		default: false,
	},
	minHeight: {
		type: String,
		default: '80px',
	},
	/** Array<{uid: string, displayName: string}> */
	participants: {
		type: Array,
		default: () => [],
	},
	/** async (file: File) => {id: number, filename: string} */
	uploadImage: {
		type: Function,
		default: null,
	},
	/** (attachmentId: number) => string */
	inlineUrl: {
		type: Function,
		default: null,
	},
})

const emit = defineEmits(['update:modelValue', 'submit', 'escape', 'imageError'])

// ── Editor style ──────────────────────────────────────────────────────────────
const editorStyle = computed(() => ({ '--kanso-editor-min-height': props.minHeight }))
const isFocused = ref(false)

// ── @-mention suggestion state ────────────────────────────────────────────────
const mentionOpen = ref(false)
const mentionItems = ref([])
const mentionHighlighted = ref(0)
const mentionDropdownStyle = ref({})
// The command provided by the Tiptap suggestion plugin to insert a chosen item
let mentionCommand = null
// Callback set by the Tiptap suggestion plugin to update the client rect
let mentionGetClientRect = null

function getFilteredParticipants(query) {
	const q = (query || '').toLowerCase()
	const list = props.participants ?? []
	return list
		.filter(
			(p) =>
				p.uid.toLowerCase().includes(q)
				|| (p.displayName && p.displayName.toLowerCase().includes(q)),
		)
		.sort((a, b) => (a.displayName || a.uid).localeCompare(b.displayName || b.uid))
		.slice(0, 6)
}

function positionMentionDropdown() {
	if (!mentionGetClientRect) return
	const rect = mentionGetClientRect()
	if (!rect) return
	// Place the list just BELOW the caret line (like a normal autocomplete), so it
	// tracks the cursor. Flip ABOVE only when there isn't room below in the
	// viewport but there is above. `rect` is viewport-relative (getClientRect), so
	// we position the body-teleported list with `fixed` — no scroll-offset math,
	// no dependence on an offset parent.
	const dropdownHeight = 260
	const spaceBelow = window.innerHeight - rect.bottom
	const placeAbove = spaceBelow < dropdownHeight && rect.top > dropdownHeight
	const top = placeAbove ? rect.top - dropdownHeight - 4 : rect.bottom + 4
	mentionDropdownStyle.value = {
		position: 'fixed',
		top: `${top}px`,
		left: `${rect.left}px`,
		zIndex: '10000',
	}
}

function selectMention(idx) {
	const item = mentionItems.value[idx]
	if (!item || !mentionCommand) return
	mentionCommand({ id: item.uid, label: item.uid })
}

// Tiptap Mention suggestion options
const mentionSuggestion = {
	char: '@',
	allowSpaces: false,
	// accepted chars: a-zA-Z0-9_.- (server side parsing charset)
	allowedPrefixes: null,
	startOfLine: false,
	render() {
		return {
			onStart(props_) {
				mentionOpen.value = true
				mentionHighlighted.value = 0
				mentionItems.value = getFilteredParticipants(props_.query)
				mentionCommand = props_.command
				mentionGetClientRect = props_.clientRect
				nextTick(positionMentionDropdown)
			},
			onUpdate(props_) {
				mentionItems.value = getFilteredParticipants(props_.query)
				mentionHighlighted.value = 0
				mentionCommand = props_.command
				mentionGetClientRect = props_.clientRect
				nextTick(positionMentionDropdown)
			},
			onKeyDown(props_) {
				const { event } = props_
				if (!mentionOpen.value || mentionItems.value.length === 0) return false
				if (event.key === 'ArrowDown') {
					event.preventDefault()
					mentionHighlighted.value = (mentionHighlighted.value + 1) % mentionItems.value.length
					return true
				}
				if (event.key === 'ArrowUp') {
					event.preventDefault()
					mentionHighlighted.value = (mentionHighlighted.value - 1 + mentionItems.value.length) % mentionItems.value.length
					return true
				}
				if (event.key === 'Enter' || event.key === 'Tab') {
					event.preventDefault()
					selectMention(mentionHighlighted.value)
					return true
				}
				if (event.key === 'Escape') {
					// Close popup only — do NOT close the modal
					event.preventDefault()
					event.stopPropagation()
					mentionOpen.value = false
					return true
				}
				return false
			},
			onExit() {
				mentionOpen.value = false
				mentionCommand = null
				mentionGetClientRect = null
			},
		}
	},
}

// ── Image-paste extension ─────────────────────────────────────────────────────
const IMAGE_MIME_RE = /^image\//i

function buildImagePastePlugin() {
	return new Plugin({
		key: new PluginKey('kansoPasteImage'),
		props: {
			handlePaste(view, event) {
				if (!props.uploadImage || !props.inlineUrl) return false
				const items = event.clipboardData?.items
				if (!items) return false
				let file = null
				for (const item of items) {
					if (item.kind === 'file' && IMAGE_MIME_RE.test(item.type || '')) {
						file = item.getAsFile()
						break
					}
				}
				if (!file) return false
				// We own this paste
				event.preventDefault()

				const label = file.name && file.name !== 'image.png'
					? file.name
					: `pasted-image-${Date.now()}.${(file.type.split('/')[1] || 'png').replace(/[^a-z0-9]/gi, '') || 'png'}`
				const token = `uploading-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
				const placeholder = `![${label}](${token})`

				// Insert placeholder as markdown text
				view.dispatch(view.state.tr.insertText(placeholder))

				// Upload and replace placeholder
				;(async () => {
					try {
						const attachment = await props.uploadImage(file)
						const src = props.inlineUrl(attachment.id)
						const finalLabel = attachment.filename || label
						const finalMd = `![${finalLabel}](${src})`
						// Find and replace placeholder in the current markdown
						const currentMd = view.state.doc.textBetween(0, view.state.doc.content.size, '\n')
						const idx = currentMd.indexOf(placeholder)
						if (idx !== -1) {
							// Replace via text command on the editor
							const editor_ = view.state.doc
							// We need the editor reference; use the plugin state approach
							// Fall back: re-set the whole content with the replacement
							const rawMd = editor.value?.storage?.markdown?.getMarkdown?.() ?? ''
							const newMd = rawMd.replace(placeholder, finalMd)
							if (newMd !== rawMd) {
								// Use setContent so the update flows through the editor
								editor.value?.commands?.setContent(newMd, true)
							}
						}
					} catch (e) {
						// Remove placeholder
						const rawMd = editor.value?.storage?.markdown?.getMarkdown?.() ?? ''
						const newMd = rawMd.replace(placeholder, '')
						editor.value?.commands?.setContent(newMd, true)
						emit('imageError', e?.response?.data?.error || null)
					}
				})()

				return true
			},
		},
	})
}

// Custom extension to intercept Ctrl/Cmd+Enter and Escape
const KansoKeymap = Extension.create({
	name: 'kansoKeymap',
	addKeyboardShortcuts() {
		return {
			'Mod-Enter': () => {
				emit('submit')
				return true
			},
			'Escape': () => {
				if (mentionOpen.value) {
					// already handled by mention's onKeyDown
					return false
				}
				emit('escape')
				return true
			},
		}
	},
})

// ── Tiptap editor setup ───────────────────────────────────────────────────────
// Track whether we are internally updating so we don't create update loops
let _internalUpdate = false

const editor = useEditor({
	content: props.modelValue || '',
	editable: !props.disabled,
	extensions: [
		StarterKit.configure({
			// Disable hardBreak default Shift+Enter so our Escape handler
			// doesn't conflict; keep standard Shift+Enter for soft breaks
		}),
		Markdown.configure({
			html: false, // never emit HTML into markdown output
			tightLists: true,
			bulletListMarker: '-',
			linkify: false,
			breaks: true,
			transformPastedText: true,
			transformCopiedText: false,
		}),
		Placeholder.configure({
			placeholder: props.placeholder,
			emptyEditorClass: 'kanso-md-editor__placeholder',
		}),
		Mention.configure({
			HTMLAttributes: {
				class: 'kanso-md-mention',
			},
			// Render mention as plain @username text in the editor view
			renderText({ node }) {
				return `@${node.attrs.id}`
			},
			// Render in editor display: styled span but text-only content
			renderHTML({ node }) {
				return ['span', { class: 'kanso-md-mention', 'data-id': node.attrs.id }, `@${node.attrs.id}`]
			},
			suggestion: mentionSuggestion,
			deleteTriggerWithBackspace: false,
		}),
		KansoKeymap,
		Extension.create({
			name: 'kansoPasteImage',
			addProseMirrorPlugins() {
				return [buildImagePastePlugin()]
			},
		}),
	],
	onUpdate({ editor: ed }) {
		if (_internalUpdate) return
		const md = ed.storage?.markdown?.getMarkdown?.() ?? ''
		emit('update:modelValue', md)
	},
	onFocus() {
		isFocused.value = true
	},
	onBlur() {
		isFocused.value = false
	},
})

// ── Sync modelValue → editor when parent changes the value ────────────────────
watch(
	() => props.modelValue,
	(newVal) => {
		if (!editor.value) return
		const currentMd = editor.value.storage?.markdown?.getMarkdown?.() ?? ''
		// Only update if the markdown actually differs (avoids cursor-reset on every keystroke)
		if (newVal !== currentMd) {
			_internalUpdate = true
			editor.value.commands.setContent(newVal || '', false)
			_internalUpdate = false
		}
	},
)

// ── Sync disabled prop ────────────────────────────────────────────────────────
watch(
	() => props.disabled,
	(val) => {
		editor.value?.setEditable(!val)
	},
)

// ── Sync placeholder prop ─────────────────────────────────────────────────────
watch(
	() => props.placeholder,
	(val) => {
		// Placeholder extension doesn't expose a live update API; update class attribute
		// which the CSS uses. We just set the editor's placeholder option via extension storage.
		// In practice placeholder rarely changes dynamically in this app.
	},
)

// ── Autofocus ─────────────────────────────────────────────────────────────────
onMounted(() => {
	if (props.autofocus) {
		nextTick(() => editor.value?.commands?.focus('end'))
	}
})

// ── Cleanup ───────────────────────────────────────────────────────────────────
onBeforeUnmount(() => {
	editor.value?.destroy()
})

// ── Expose focus for parent refs ──────────────────────────────────────────────
defineExpose({
	focus(position) {
		editor.value?.commands?.focus(position ?? 'end')
	},
	/** Set content and move caret to end */
	setContent(md) {
		_internalUpdate = true
		editor.value?.commands?.setContent(md || '', false)
		_internalUpdate = false
		nextTick(() => editor.value?.commands?.focus('end'))
	},
})
</script>

<style scoped>
/* ── Container ─────────────────────────────────────────────────────────────── */
.kanso-md-editor {
	position: relative;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background: var(--color-main-background);
	transition: border-color 0.15s ease;
}

.kanso-md-editor--focused {
	border-color: var(--color-primary-element);
	outline: none;
}

.kanso-md-editor--disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

/* ── ProseMirror content area ──────────────────────────────────────────────── */
/* The EditorContent wrapper + the contenteditable must fill the container width;
   without this the ProseMirror node shrink-wraps to its content (e.g. the
   placeholder), leaving a tiny editable strip. */
.kanso-md-editor__content {
	width: 100%;
}
.kanso-md-editor__content :deep(.ProseMirror) {
	width: 100%;
	box-sizing: border-box;
	min-height: var(--kanso-editor-min-height, 80px);
	padding: 10px 12px;
	font-size: 0.9rem;
	line-height: 1.6;
	color: var(--color-main-text);
	background: transparent;
	outline: none;
	word-break: break-word;
	overflow-wrap: break-word;
}

/* Placeholder */
.kanso-md-editor__content :deep(.kanso-md-editor__placeholder::before),
.kanso-md-editor__content :deep(.ProseMirror p.is-editor-empty:first-child::before) {
	content: attr(data-placeholder);
	float: left;
	color: var(--color-text-maxcontrast, #767676);
	pointer-events: none;
	height: 0;
}

/* ── WYSIWYG inline styles ──────────────────────────────────────────────────── */
.kanso-md-editor__content :deep(.ProseMirror) {
	/* Paragraphs */
	& p {
		margin: 0 0 0.5em;
	}
	& p:last-child {
		margin-bottom: 0;
	}

	/* Headings */
	& h1, & h2, & h3, & h4, & h5, & h6 {
		font-weight: 700;
		line-height: 1.25;
		margin: 0.75em 0 0.25em;
		color: var(--color-main-text);
	}
	& h1 { font-size: 1.4em; }
	& h2 { font-size: 1.2em; }
	& h3 { font-size: 1.05em; }

	/* Lists */
	& ul, & ol {
		margin: 0.25em 0 0.5em 1.5em;
		padding: 0;
	}
	& ul { list-style: disc; }
	& ol { list-style: decimal; }
	& li { margin: 0.1em 0; }

	/* Task list */
	& ul[data-type="taskList"] {
		list-style: none;
		margin-left: 0.5em;
	}
	& ul[data-type="taskList"] li {
		display: flex;
		align-items: flex-start;
		gap: 0.4em;
	}
	& ul[data-type="taskList"] li input {
		margin-top: 0.15em;
	}

	/* Blockquote */
	& blockquote {
		border-left: 3px solid var(--color-primary-element, #0082c9);
		margin: 0.25em 0 0.5em;
		padding: 0.1em 0.75em;
		color: var(--color-text-maxcontrast, #767676);
		font-style: italic;
	}

	/* Inline code */
	& code {
		font-family: var(--font-face-monospace, 'Lucida Console', Monaco, monospace);
		font-size: 0.85em;
		background: var(--color-background-dark, rgba(0,0,0,0.06));
		border-radius: 4px;
		padding: 0.1em 0.35em;
		color: var(--color-main-text);
	}

	/* Code block */
	& pre {
		background: var(--color-background-dark, rgba(0,0,0,0.06));
		border-radius: 8px;
		padding: 0.75em 1em;
		overflow-x: auto;
		margin: 0.5em 0;
	}
	& pre code {
		background: none;
		padding: 0;
		font-size: 0.85em;
	}

	/* Horizontal rule */
	& hr {
		border: none;
		border-top: 1px solid var(--color-border);
		margin: 1em 0;
	}

	/* Links */
	& a {
		color: var(--color-primary-element);
		text-decoration: underline;
	}

	/* Bold / italic / strikethrough */
	& strong { font-weight: 700; }
	& em { font-style: italic; }
	& s { text-decoration: line-through; }

	/* Mention chip */
	& .kanso-md-mention {
		display: inline-block;
		background: var(--color-primary-element-light, rgba(0,130,201,0.12));
		color: var(--color-primary-element, #0082c9);
		border-radius: 4px;
		padding: 0 0.25em;
		font-weight: 500;
	}
}

/* ── Mention dropdown (rendered via Teleport to body) ──────────────────────── */
</style>

<!-- Mention dropdown styles must NOT be scoped — they live in the body -->
<style>
.kanso-md-editor__mention-dropdown {
	list-style: none;
	margin: 0;
	padding: 6px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 10px;
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
	min-width: 200px;
	max-height: 260px;
	overflow-y: auto;
}

.kanso-md-editor__mention-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 5px 8px;
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.875rem;
	color: var(--color-main-text);
}

.kanso-md-editor__mention-item--highlighted,
.kanso-md-editor__mention-item:hover {
	background: var(--color-background-hover);
}

.kanso-md-editor__mention-name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>
