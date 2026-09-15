<?php
// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Shown when a public board link RESOLVED but has passed its expiry (#10466).
// Deliberately distinct from public-notfound.php: a visitor who cannot tell
// "expired" from "wrong address" goes back to the owner asking them to re-check
// the URL. The HTTP status is still 404 and the route is still throttled.

/** @var \OCP\IL10N $l */
?>
<div class="guest-box" style="max-width:480px;margin:64px auto;text-align:center;">
	<h2><?php p($l->t('Link expired')); ?></h2>
	<p><?php p($l->t('This public board link has expired. Ask whoever shared the board for a new link.')); ?></p>
</div>
