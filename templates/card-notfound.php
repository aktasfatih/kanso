<?php
// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/** @var \OCP\IL10N $l */
/** @var array{appUrl: string} $_ */
?>
<div class="guest-box" style="max-width:480px;margin:64px auto;text-align:center;">
	<h2><?php p($l->t('Card not found')); ?></h2>
	<p><?php p($l->t('This card does not exist or you do not have access to it.')); ?></p>
	<p><a href="<?php p($_['appUrl']); ?>"><?php p($l->t('Open Kanso')); ?></a></p>
</div>
