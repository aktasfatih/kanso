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
.public-board { max-width: 1400px; margin: 0 auto; padding: 24px 16px 48px; box-sizing: border-box; }
.public-board__header { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
.public-board__dot { width: 16px; height: 16px; border-radius: 50%; flex: 0 0 auto; }
.public-board__title { font-size: 24px; font-weight: 700; margin: 0; }
.public-board__badge { font-size: 12px; padding: 2px 10px; border-radius: 12px; background: var(--color-background-dark, #ededed); color: var(--color-text-maxcontrast, #666); }
.public-board__state { color: var(--color-text-maxcontrast, #666); padding: 32px 0; }
.public-board__state--error { color: var(--color-error, #c33); }
.public-board__columns { display: flex; gap: 16px; align-items: flex-start; overflow-x: auto; padding-bottom: 8px; }
.public-col { flex: 0 0 300px; max-width: 300px; background: var(--color-background-hover, #f5f5f5); border-radius: 10px; padding: 10px; box-sizing: border-box; }
.public-col__title { display: flex; align-items: center; justify-content: space-between; font-size: 15px; font-weight: 600; margin: 4px 4px 12px; padding-bottom: 6px; border-bottom: 2px solid var(--color-border, #ddd); }
.public-col__count { font-size: 12px; color: var(--color-text-maxcontrast, #888); font-weight: 500; }
.public-col__cards { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.public-card { background: var(--color-main-background, #fff); border: 1px solid var(--color-border, #e0e0e0); border-radius: 8px; padding: 10px; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05); }
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
</style>
<div id="kanso-public" data-token="<?php p($_['token'] ?? ''); ?>"></div>
