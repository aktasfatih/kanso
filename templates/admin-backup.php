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
	BackupService::DEST_APPDATA => $l->t('Inside Kanso (recommended)'),
	BackupService::DEST_FILES => $l->t('In a Files folder'),
];
$usesAppData = $config['destination'] === BackupService::DEST_APPDATA;
$hideIf = static function (bool $hidden): void {
	if ($hidden) {
		print_unescaped(' style="display: none;"');
	}
};
?>
<div class="section" id="kanso-backup-settings">
	<h2><?php p($l->t('Kanso board backups')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Once a day, Kanso saves a copy of every board as a zip file.')); ?>
	</p>

	<p>
		<input type="checkbox" id="kanso-backup-enabled" class="checkbox"
			<?php if ($config['enabled']) {
				print_unescaped('checked');
			} ?> />
		<label for="kanso-backup-enabled"><?php p($l->t('Back up boards automatically')); ?></label>
	</p>

	<p>
		<label for="kanso-backup-destination"><?php p($l->t('Where to keep the backups')); ?></label><br />
		<select id="kanso-backup-destination" style="width: 300px;">
			<?php foreach ($destinationLabels as $value => $label) { ?>
				<option value="<?php p($value); ?>"
					<?php if ($config['destination'] === $value) {
						print_unescaped('selected');
					} ?>><?php p($label); ?></option>
			<?php } ?>
		</select>
	</p>
	<?php /* Only the selected destination's explanation is on screen; the other
		   one would describe a store this instance is not using. The server
		   renders the right one so a reload never flashes the wrong text, and
		   admin-backup.js keeps them in step from then on. */ ?>
	<p class="settings-hint" id="kanso-backup-destination-hint-appdata"<?php $hideIf(!$usesAppData); ?>>
		<?php p($l->t('Kanso keeps the files itself. Nothing goes into anyone\'s Files folder.')); ?>
		<?php p($l->t('No activity entries and no quota use, but you cannot sync the files or store them elsewhere.')); ?>
		<?php p($l->t('The only way to get one back is the list at the bottom of this page.')); ?>
	</p>
	<p class="settings-hint" id="kanso-backup-destination-hint-files"<?php $hideIf($usesAppData); ?>>
		<?php p($l->t('Kanso writes the files into a Files folder you can open and sync.')); ?>
		<?php p($l->t('You can also point that folder at another server with external storage.')); ?>
		<?php p($l->t('The files use that account\'s quota, and each write shows up in its activity.')); ?>
	</p>

	<div id="kanso-backup-files-config"<?php $hideIf($usesAppData); ?>>
		<p>
			<label for="kanso-backup-account"><?php p($l->t('Account that owns the folder')); ?></label><br />
			<input type="text" id="kanso-backup-account" placeholder="admin"
				value="<?php p($config['account']); ?>" style="width: 200px;" />
		</p>
		<p class="settings-hint" id="kanso-backup-account-hint">
			<?php p($l->t('Each run adds two entries per board to this account\'s activity.')); ?>
			<?php p($l->t('Kanso cannot switch them off, and the notification setting below does not remove them.')); ?>
			<?php p($l->t('Point this at a separate account nobody signs in to, and they land in its feed instead of yours.')); ?>
			<?php p($l->t('The backups then live in that account\'s Files.')); ?>
		</p>

		<p>
			<label for="kanso-backup-path"><?php p($l->t('Folder in that account')); ?></label><br />
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
		<label for="kanso-backup-notify"><?php p($l->t('Notify administrators')); ?></label><br />
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
		<?php p($l->t('Everyone in the administrators group gets the message.')); ?>
		<?php p($l->t('A new one replaces the last, so a backup that keeps failing does not pile up.')); ?>
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
			<?php p($l->t('Newest first.')); ?>
			<?php p($l->t('Each file holds one whole board, with every card and attachment on it, even private ones.')); ?>
			<?php p($l->t('Only administrators can download them, and they can never be shared by a link.')); ?>
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
