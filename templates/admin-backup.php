<?php
// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/** @var array{enabled: bool, destination: string, path: string, account: string, retention: int, notify: string, lastRunAt: int, lastRunStatus: string, lastRunMessage: string} $_ ['config'] */
/** @var \OCP\IL10N $l */

use OCA\Kanso\Service\BackupService;
use OCP\Util;

Util::addScript('kanso', 'kanso-admin-backup');

$config = $_['config'];
$lastRunLabel = $config['lastRunAt'] > 0
	? date('Y-m-d H:i', $config['lastRunAt']) . ' UTC'
	: $l->t('never');
$notifyLabels = [
	BackupService::NOTIFY_NEVER => $l->t('Never'),
	BackupService::NOTIFY_FAILURE => $l->t('Only when a backup fails'),
	BackupService::NOTIFY_ALWAYS => $l->t('After every backup'),
];
$destinationLabels = [
	BackupService::DEST_APPDATA => $l->t('Kanso app data (recommended)'),
	BackupService::DEST_FILES => $l->t('A folder in Files'),
];
$usesAppData = $config['destination'] === BackupService::DEST_APPDATA;
?>
<div class="section" id="kanso-backup-settings">
	<h2><?php p($l->t('Kanso board backups')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Automatically export every Kanso board to a versioned archive on a daily schedule, keeping the last few backups per board. Choose where those archives are written: inside Kanso\'s own app data, or in a Nextcloud folder you can browse and mount elsewhere.')); ?>
	</p>

	<p>
		<input type="checkbox" id="kanso-backup-enabled" class="checkbox"
			<?php if ($config['enabled']) {
				print_unescaped('checked');
			} ?> />
		<label for="kanso-backup-enabled"><?php p($l->t('Enable scheduled backups')); ?></label>
	</p>

	<p>
		<label for="kanso-backup-destination"><?php p($l->t('Where backups are written')); ?></label><br />
		<select id="kanso-backup-destination" style="width: 300px;">
			<?php foreach ($destinationLabels as $value => $label) { ?>
				<option value="<?php p($value); ?>"
					<?php if ($config['destination'] === $value) {
						print_unescaped('selected');
					} ?>><?php p($label); ?></option>
			<?php } ?>
		</select>
	</p>
	<p class="settings-hint" id="kanso-backup-destination-hint-appdata">
		<?php p($l->t('Kanso app data keeps each archive inside the app\'s own storage, next to card attachments. Nothing is written into anyone\'s Files, so a run adds no Files activity entries and the archives are outside every account\'s quota. What you give up: they are not browsable, syncable or mountable anywhere — the list at the bottom of this page is the only way to get one back — and a backup that ages out of retention is deleted outright instead of going to a trashbin.')); ?>
	</p>
	<p class="settings-hint" id="kanso-backup-destination-hint-files">
		<?php p($l->t('A folder in Files is the option to pick when you want the archives off this server or simply want to see them: it is the only one you can browse, sync or back with an S3 External Storage mount (Kanso writes the files through Nextcloud and never holds S3 credentials). It costs what any file write costs — the entries described below, the folder account\'s quota, and pruned backups landing in that account\'s trashbin.')); ?>
	</p>

	<div id="kanso-backup-files-config"<?php if ($usesAppData) {
		print_unescaped(' style="display: none;"');
	} ?>>
		<p>
			<label for="kanso-backup-account"><?php p($l->t('Nextcloud account that owns the target folder')); ?></label><br />
			<input type="text" id="kanso-backup-account" placeholder="admin"
				value="<?php p($config['account']); ?>" style="width: 200px;" />
		</p>
		<p class="settings-hint" id="kanso-backup-account-hint">
			<?php p($l->t('Every run writes one backup file per board and deletes the ones that fall outside retention, so this account\'s Files activity gains two entries per board per run. Nextcloud records those entries for whichever account owns the folder, and no app can switch them off for a single write — the notification setting below only decides whether Kanso tells you about a run, it does not remove these entries. Point this at a dedicated service account to keep them out of your own activity feed. The tradeoff: the backups then live in that account\'s Files rather than yours, and sharing the folder back to yourself brings the activity entries along.')); ?>
		</p>

		<p>
			<label for="kanso-backup-path"><?php p($l->t('Target folder (Nextcloud path under that account)')); ?></label><br />
			<input type="text" id="kanso-backup-path" placeholder="/kanso-backups"
				value="<?php p($config['path']); ?>" style="width: 360px;" />
		</p>
	</div>

	<p>
		<label for="kanso-backup-retention"><?php p($l->t('Backups to keep per board')); ?></label><br />
		<input type="number" id="kanso-backup-retention" min="1" max="365"
			value="<?php p((string)$config['retention']); ?>" style="width: 100px;" />
	</p>

	<p>
		<label for="kanso-backup-notify"><?php p($l->t('Notify administrators about a run')); ?></label><br />
		<select id="kanso-backup-notify" style="width: 240px;">
			<?php foreach ($notifyLabels as $value => $label) { ?>
				<option value="<?php p($value); ?>"
					<?php if ($config['notify'] === $value) {
						print_unescaped('selected');
					} ?>><?php p($label); ?></option>
			<?php } ?>
		</select>
	</p>
	<p class="settings-hint" id="kanso-backup-notify-hint">
		<?php p($l->t('Everyone in the administrators group gets the notification. Each run replaces the previous one, so a backup that keeps failing leaves a single unread entry rather than one per night.')); ?>
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

	<div id="kanso-backup-stored">
		<h3><?php p($l->t('Stored backups')); ?></h3>
		<p class="settings-hint" id="kanso-backup-stored-hint">
			<?php p($l->t('The archives currently kept in the destination above, newest first. Each one is a full export of a single board — every card and every attachment on it, including ones you cannot normally see — so downloading it is an administrator-only action and the file is never shareable by link.')); ?>
		</p>
		<table id="kanso-backup-file-list" class="grid" style="max-width: 720px;">
			<thead>
				<tr>
					<th><?php p($l->t('Backup')); ?></th>
					<th><?php p($l->t('Board')); ?></th>
					<th><?php p($l->t('Size')); ?></th>
					<th><?php p($l->t('Written')); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody id="kanso-backup-file-rows">
			</tbody>
		</table>
		<p class="settings-hint" id="kanso-backup-file-empty"><?php p($l->t('No backups stored yet.')); ?></p>
	</div>
</div>
