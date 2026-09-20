<?php
// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/** @var array $_ */
// The token is echoed into a data-attribute only (never into a script context)
// and is passed through p() so it is HTML-escaped. It is an opaque 64-char
// alnum string; the public JS reads it back and fetches the stripped payload.

// The board layout CSS is inlined here on purpose: the build bundles every JS
// entry's CSS into the authenticated MAIN bundle, which the public page never
// loads (it only pulls kanso-public via addScript). Without this, the scoped
// styles in PublicBoard.vue never reach the page and it renders as plain text.
?>
<style>
/* The public page mounts into #kanso-public inside NC's body; give it a real
   height so the board below can size itself off a definite one. */
html, body { height: 100%; }
/* #kanso-public is a flex ITEM of NC's #content (display: flex). Without an
   explicit flex/width it falls back to `flex: 0 1 auto` and shrink-wraps to
   max-content, so a two-column board drew as a narrow strip with most of the
   window dead (#117). flex + width make it fill the row; min-width:0 lets the
   stack row inside it scroll instead of forcing the item wider. */
#kanso-public { flex: 1 1 auto; width: 100%; min-width: 0; height: 100%; overflow-y: auto; box-sizing: border-box; }
/* The board is the page shell — a full-height column where only the middle
   (the stack row) scrolls, exactly like the authenticated .board-view. Height
   is INHERITED, never computed from the viewport: the real scroll box is the
   window minus NC's header and the board's own chrome, so any
   `calc(100vh - <magic>)` here is off by a constant at every viewport size,
   and 100vh is the *large* viewport on mobile (collapsing URL bar) on top. */
.public-board { height: 100%; display: flex; flex-direction: column; overflow: hidden; padding: 24px 16px 16px; box-sizing: border-box; }
.public-board__header { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; flex: 0 0 auto; }
.public-board__dot { width: 16px; height: 16px; border-radius: 50%; flex: 0 0 auto; }
.public-board__title { font-size: 24px; font-weight: 700; margin: 0; }
.public-board__badge { font-size: 12px; padding: 2px 10px; border-radius: 12px; background: var(--color-background-dark, #ededed); color: var(--color-text-maxcontrast, #666); }
.public-board__state { color: var(--color-text-maxcontrast, #666); padding: 32px 0; }
.public-board__state--error { color: var(--color-error-text, #c33); }
/* The one scrolling region: sideways through the stacks, and (via the card
   list below) down through a stack's cards. */
.public-board__columns { display: flex; gap: 16px; align-items: stretch; flex: 1; min-height: 0; overflow-x: auto; overflow-y: hidden; padding-bottom: 8px; }
/* Fluid column, mirroring .stack-column on the authenticated board: soak up
   spare width, never narrower than 280px, never wider than 420px. Its height
   comes from the row stretching it, and min-height:0 is what lets the card
   list actually scroll rather than pushing the column taller. */
.public-col { flex: 1 1 280px; min-width: 280px; max-width: 420px; min-height: 0; display: flex; flex-direction: column; background: var(--color-background-hover, #f5f5f5); border-radius: 10px; padding: 10px; box-sizing: border-box; }
.public-col__title { display: flex; align-items: center; justify-content: space-between; font-size: 15px; font-weight: 600; margin: 4px 4px 12px; padding-bottom: 6px; border-bottom: 2px solid var(--color-border, #ddd); }
.public-col__count { font-size: 12px; color: var(--color-text-maxcontrast, #888); font-weight: 500; }
.public-col__cards { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; flex: 1; min-height: 0; overflow-y: auto; }
.public-card { background: var(--color-main-background, #fff); border: 1px solid var(--color-border, #e0e0e0); border-radius: 8px; padding: 10px; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05); cursor: pointer; }
.public-card:hover { border-color: var(--color-primary-element, #0082c9); }
.public-card:focus-visible { outline: 2px solid var(--color-primary-element, #0082c9); outline-offset: 1px; }
.public-card--done { opacity: 0.6; }
.public-card__labels { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 6px; }
.public-card__label { font-size: 11px; padding: 1px 8px; border-radius: 10px; color: #fff; text-shadow: 0 0 2px rgba(0, 0, 0, 0.4); }
.public-card__row { display: flex; gap: 6px; align-items: baseline; }
.public-card__id { font-size: 11px; color: var(--color-text-maxcontrast, #888); font-weight: 600; flex: 0 0 auto; }
.public-card__title { font-weight: 500; word-break: break-word; }
.public-card__desc { margin: 6px 0 0; font-size: 13px; color: var(--color-text-maxcontrast, #666); white-space: pre-wrap; word-break: break-word; }
.public-card__meta { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; font-size: 12px; color: var(--color-text-maxcontrast, #888); }
.public-card__prio { color: var(--color-error-text, #c33); font-weight: 600; }
/* Core's `#body-public footer` rule already pins this to the viewport bottom
   (computed `position: fixed`), so it is out of flow and the flex shell above
   neither sizes it nor has to reserve room for it — but declare flex:0 0 auto
   anyway so it lands under the stack row, not on top of it, if that rule goes. */
.public-board__footer { flex: 0 0 auto; margin-top: 16px; text-align: center; font-size: 12px; color: var(--color-text-maxcontrast, #999); }

/* Phone viewports — same treatment as the authenticated board in
   src/styles/mobile.css (which the public bundle never loads: src/public.js
   imports no CSS, so this inline sheet has to carry it). One column at a time
   with a peek of the next, snapped so a swipe lands cleanly. */
@media (max-width: 680px) {
	.public-board { padding: 12px 12px calc(12px + env(safe-area-inset-bottom, 0px)); }
	.public-board__header { gap: 8px; margin-bottom: 16px; }
	.public-board__title { font-size: 20px; }
	.public-board__columns { gap: 12px; scroll-snap-type: x mandatory; scroll-padding-left: 12px; -webkit-overflow-scrolling: touch; }
	.public-col { flex: 0 0 88vw; min-width: 0; max-width: 88vw; scroll-snap-align: start; }
}

/* Read-only card detail modal (#3945). Self-contained; no edit affordances. */
.public-detail__backdrop { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.45); display: flex; align-items: flex-start; justify-content: center; padding: 48px 16px; overflow-y: auto; z-index: 10000; }
.public-detail { background: var(--color-main-background, #fff); color: var(--color-main-text, #222); border-radius: 12px; width: 100%; max-width: 640px; padding: 24px; box-shadow: 0 8px 30px rgba(0, 0, 0, 0.25); box-sizing: border-box; }
.public-detail__top { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 12px; }
.public-detail__id { font-size: 13px; color: var(--color-text-maxcontrast, #888); font-weight: 600; flex: 0 0 auto; margin-top: 2px; }
.public-detail__title { font-size: 20px; font-weight: 700; margin: 0; flex: 1 1 auto; word-break: break-word; }
.public-detail__close { flex: 0 0 auto; background: transparent; border: none; font-size: 22px; line-height: 1; cursor: pointer; color: var(--color-text-maxcontrast, #888); padding: 0 4px; }
.public-detail__labels { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
.public-detail__label { font-size: 12px; padding: 2px 10px; border-radius: 10px; color: #fff; text-shadow: 0 0 2px rgba(0, 0, 0, 0.4); }
.public-detail__cover { height: 8px; border-radius: 6px; margin: -8px 0 14px; }
.public-detail__meta { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 12px; font-size: 13px; color: var(--color-text-maxcontrast, #666); }
.public-detail__prio { color: var(--color-error-text, #c33); font-weight: 600; }
.public-detail__desc { font-size: 14px; line-height: 1.5; word-break: break-word; color: var(--color-main-text, #222); }
.public-detail__desc--empty { color: var(--color-text-maxcontrast, #888); font-style: italic; }
/* Rendered-markdown description: keep block spacing sane inside the modal. */
.public-detail__desc p { margin: 0 0 10px; }
.public-detail__desc p:last-child { margin-bottom: 0; }
/* list-style is re-declared here because core/css/server.css resets `ul, ol, li`
   to no margin/padding and `ul` to `list-style: none` (#139). The public bundle
   (src/public.js) imports no CSS, so it cannot use src/styles/markdown.css —
   this inline sheet is the public share's only styling surface. Logical
   properties so markers stay inside the modal in RTL. */
.public-detail__desc ul, .public-detail__desc ol { margin: 0 0 10px; padding-inline-start: 22px; }
.public-detail__desc ul { list-style-type: disc; }
.public-detail__desc ol { list-style-type: decimal; }
.public-detail__desc ul ul { list-style-type: circle; }
.public-detail__desc ul ul ul { list-style-type: square; }
.public-detail__desc li { margin: 2px 0; }
.public-detail__desc h1, .public-detail__desc h2, .public-detail__desc h3,
.public-detail__desc h4, .public-detail__desc h5, .public-detail__desc h6 { margin: 12px 0 6px; line-height: 1.3; }
.public-detail__desc pre { background: var(--color-background-dark, #f0f0f0); padding: 8px 10px; border-radius: 6px; overflow-x: auto; }
.public-detail__desc code { background: var(--color-background-dark, #f0f0f0); padding: 1px 4px; border-radius: 4px; font-size: 0.92em; }
.public-detail__desc pre code { background: none; padding: 0; }
.public-detail__desc blockquote { margin: 0 0 10px; padding-left: 12px; border-left: 3px solid var(--color-border, #ddd); color: var(--color-text-maxcontrast, #666); }
.public-detail__desc a { color: var(--color-primary-element, #0082c9); }
.public-detail__desc img { max-width: 100%; height: auto; border-radius: 6px; }
/* Read-only checklist steps and sub-card references (#135). `list-style: none`
   is deliberate here (unlike the markdown lists above): these are UI lists with
   their own tick / chip, not prose. */
.public-checklist, .public-subcards { margin-top: 18px; border-top: 1px solid var(--color-border, #ddd); padding-top: 14px; }
.public-checklist__title, .public-subcards__title { margin: 0 0 10px; font-size: 14px; font-weight: 600; color: var(--color-main-text, #222); }
.public-checklist__list, .public-subcards__list { list-style: none; margin: 0; padding: 0; }
.public-checklist__item { display: flex; align-items: flex-start; gap: 8px; font-size: 14px; line-height: 1.5; margin: 4px 0; color: var(--color-main-text, #222); }
.public-checklist__item--done .public-checklist__label { text-decoration: line-through; color: var(--color-text-maxcontrast, #888); }
.public-checklist__box { flex: 0 0 auto; width: 14px; height: 14px; margin-top: 4px; border: 2px solid var(--color-border-dark, #aaa); border-radius: 3px; box-sizing: border-box; }
.public-checklist__box--done { background: var(--color-success-text, #2d7b2d); border-color: var(--color-success-text, #2d7b2d); }
.public-checklist__label { word-break: break-word; }
.public-subcard { display: flex; gap: 6px; align-items: baseline; padding: 6px 8px; margin: 4px 0; border: 1px solid var(--color-border, #e0e0e0); border-radius: 6px; cursor: pointer; }
.public-subcard:hover { border-color: var(--color-primary-element, #0082c9); }
.public-subcard:focus-visible { outline: 2px solid var(--color-primary-element, #0082c9); outline-offset: 1px; }
.public-subcard--done { opacity: 0.6; }
.public-subcard__id { font-size: 11px; color: var(--color-text-maxcontrast, #888); font-weight: 600; flex: 0 0 auto; }
.public-subcard__title { font-size: 13px; word-break: break-word; }
/* Read-only comments (#3949): shown only when the owner opted in. */
.public-comments { margin-top: 18px; border-top: 1px solid var(--color-border, #ddd); padding-top: 14px; }
.public-comments__title { margin: 0 0 10px; font-size: 14px; font-weight: 600; color: var(--color-main-text, #222); }
.public-comments__empty { margin: 0; font-size: 13px; font-style: italic; color: var(--color-text-maxcontrast, #888); }
.public-comments__list, .public-comment__replies { list-style: none; margin: 0; padding: 0; }
.public-comment { margin-bottom: 14px; }
.public-comment__replies { margin-top: 12px; padding-left: 20px; border-left: 2px solid var(--color-border, #eee); }
.public-comment__head { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
.public-comment__avatar { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 50%; background: var(--color-primary-element, #0082c9); color: #fff; font-size: 11px; font-weight: 600; flex: 0 0 auto; }
.public-comment__author { font-size: 13px; font-weight: 600; color: var(--color-main-text, #222); }
.public-comment__date { font-size: 12px; color: var(--color-text-maxcontrast, #888); }
.public-comment__body { font-size: 14px; line-height: 1.5; word-break: break-word; color: var(--color-main-text, #222); }
.public-comment__body p { margin: 0 0 8px; }
.public-comment__body p:last-child { margin-bottom: 0; }
.public-comment__body a { color: var(--color-primary-element, #0082c9); }
.public-comment__body code { background: var(--color-background-dark, #f0f0f0); padding: 1px 4px; border-radius: 4px; font-size: 0.92em; }
/* Same core-reset problem as the description above (#139): without these a
   markdown list in a comment loses its markers and its indent. Scoped to
   __body so the comment/reply <ul> scaffolding above keeps `list-style: none`. */
.public-comment__body ul, .public-comment__body ol { margin: 0 0 8px; padding-inline-start: 22px; }
.public-comment__body ul { list-style-type: disc; }
.public-comment__body ol { list-style-type: decimal; }
.public-comment__body ul ul { list-style-type: circle; }
.public-comment__body li { margin: 2px 0; }
.public-comment__body blockquote { margin: 0 0 8px; padding-inline-start: 12px; border-inline-start: 3px solid var(--color-border, #ddd); color: var(--color-text-maxcontrast, #666); }
/* #147: the description above already clamped images; a comment body did not,
   so a wide one overflowed the detail pane. max-width (not width) leaves a
   small image at its natural size. */
.public-comment__body img { max-width: 100%; height: auto; border-radius: 6px; }
</style>
<div id="kanso-public" data-token="<?php p($_['token'] ?? ''); ?>"></div>
