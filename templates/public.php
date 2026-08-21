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
   height so the columns/cards below the fold become reachable by scrolling. */
html, body { height: 100%; }
#kanso-public { height: 100%; overflow-y: auto; box-sizing: border-box; }
.public-board { max-width: 1400px; margin: 0 auto; padding: 24px 16px 48px; box-sizing: border-box; }
.public-board__header { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
.public-board__dot { width: 16px; height: 16px; border-radius: 50%; flex: 0 0 auto; }
.public-board__title { font-size: 24px; font-weight: 700; margin: 0; }
.public-board__badge { font-size: 12px; padding: 2px 10px; border-radius: 12px; background: var(--color-background-dark, #ededed); color: var(--color-text-maxcontrast, #666); }
.public-board__state { color: var(--color-text-maxcontrast, #666); padding: 32px 0; }
.public-board__state--error { color: var(--color-error, #c33); }
.public-board__columns { display: flex; gap: 16px; align-items: flex-start; overflow-x: auto; padding-bottom: 8px; }
.public-col { flex: 0 0 300px; max-width: 300px; max-height: calc(100vh - 180px); display: flex; flex-direction: column; background: var(--color-background-hover, #f5f5f5); border-radius: 10px; padding: 10px; box-sizing: border-box; }
.public-col__title { display: flex; align-items: center; justify-content: space-between; font-size: 15px; font-weight: 600; margin: 4px 4px 12px; padding-bottom: 6px; border-bottom: 2px solid var(--color-border, #ddd); }
.public-col__count { font-size: 12px; color: var(--color-text-maxcontrast, #888); font-weight: 500; }
.public-col__cards { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; overflow-y: auto; }
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
.public-card__prio { color: var(--color-error, #c33); font-weight: 600; }
.public-board__footer { margin-top: 32px; text-align: center; font-size: 12px; color: var(--color-text-maxcontrast, #999); }

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
.public-detail__prio { color: var(--color-error, #c33); font-weight: 600; }
.public-detail__desc { font-size: 14px; line-height: 1.5; word-break: break-word; color: var(--color-main-text, #222); }
.public-detail__desc--empty { color: var(--color-text-maxcontrast, #888); font-style: italic; }
/* Rendered-markdown description: keep block spacing sane inside the modal. */
.public-detail__desc p { margin: 0 0 10px; }
.public-detail__desc p:last-child { margin-bottom: 0; }
.public-detail__desc ul, .public-detail__desc ol { margin: 0 0 10px; padding-left: 22px; }
.public-detail__desc h1, .public-detail__desc h2, .public-detail__desc h3,
.public-detail__desc h4, .public-detail__desc h5, .public-detail__desc h6 { margin: 12px 0 6px; line-height: 1.3; }
.public-detail__desc pre { background: var(--color-background-dark, #f0f0f0); padding: 8px 10px; border-radius: 6px; overflow-x: auto; }
.public-detail__desc code { background: var(--color-background-dark, #f0f0f0); padding: 1px 4px; border-radius: 4px; font-size: 0.92em; }
.public-detail__desc pre code { background: none; padding: 0; }
.public-detail__desc blockquote { margin: 0 0 10px; padding-left: 12px; border-left: 3px solid var(--color-border, #ddd); color: var(--color-text-maxcontrast, #666); }
.public-detail__desc a { color: var(--color-primary-element, #0082c9); }
.public-detail__desc img { max-width: 100%; height: auto; border-radius: 6px; }
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
</style>
<div id="kanso-public" data-token="<?php p($_['token'] ?? ''); ?>"></div>
