<?php
// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/** @var array $_ */
// The token is echoed into a data-attribute only (never into a script context)
// and is passed through p() so it is HTML-escaped. It is an opaque 64-char
// alnum string; the public JS reads it back and fetches the stripped payload.
?>
<div id="kanso-public" data-token="<?php p($_['token'] ?? ''); ?>"></div>
