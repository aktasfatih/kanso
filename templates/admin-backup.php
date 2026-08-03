<?php
// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/** @var array{enabled: bool, path: string, retention: int, lastRunAt: int, lastRunStatus: string, lastRunMessage: string} $_ ['config'] */
/** @var \OCP\IL10N $l */

use OCP\Util;

Util::addScript('kanso', 'kanso-admin-backup');

$config = $_['config'];
$lastRunLabel = $config['lastRunAt'] > 0
	? date('Y-m-d H:i', $config['lastRunAt']) . ' UTC'
	: $l->t('never');
?>
<div class="section" id="kanso-backup-settings">
	<h2><?php p($l->t('Kanso board backups')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Automatically export every Kanso board to a versioned JSON file on a daily schedule, keeping the last few backups per board. Point this at a Nextcloud folder; mount that folder as an S3 External Storage to keep off-site copies. Kanso writes the files via Nextcloud and never holds S3 credentials.')); ?>
	</p>

	<p>
		<input type="checkbox" id="kanso-backup-enabled" class="checkbox"
			<?php if ($config['enabled']) {
				print_unescaped('checked');
			} ?> />
		<label for="kanso-backup-enabled"><?php p($l->t('Enable scheduled backups')); ?></label>
	</p>

	<p>
		<label for="kanso-backup-account"><?php p($l->t('Nextcloud account that owns the target folder')); ?></label><br />
		<input type="text" id="kanso-backup-account" placeholder="admin"
			value="<?php p($config['account']); ?>" style="width: 200px;" />
	</p>

	<p>
		<label for="kanso-backup-path"><?php p($l->t('Target folder (Nextcloud path under that account)')); ?></label><br />
		<input type="text" id="kanso-backup-path" placeholder="/kanso-backups"
			value="<?php p($config['path']); ?>" style="width: 360px;" />
	</p>

	<p>
		<label for="kanso-backup-retention"><?php p($l->t('Backups to keep per board')); ?></label><br />
		<input type="number" id="kanso-backup-retention" min="1" max="365"
			value="<?php p((string)$config['retention']); ?>" style="width: 100px;" />
	</p>

	<p>
		<button type="button" id="kanso-backup-save" class="primary"><?php p($l->t('Save')); ?></button>
		<button type="button" id="kanso-backup-run"><?php p($l->t('Run backup now')); ?></button>
		<span id="kanso-backup-feedback" class="kanso-backup-feedback"></span>
	</p>

	<p class="settings-hint" id="kanso-backup-lastrun"
		data-status="<?php p($config['lastRunStatus']); ?>">
		<?php p($l->t('Last run: %1$s', [$lastRunLabel])); ?>
		<?php if ($config['lastRunMessage'] !== '') { ?>
			&mdash; <span id="kanso-backup-lastmsg"><?php p($config['lastRunMessage']); ?></span>
		<?php } ?>
	</p>
</div>
