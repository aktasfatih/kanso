// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
	main: 'src/main.js',
	// The unauthenticated read-only public-share app (#3531) - a separate, small
	// entry so the public page ships none of the authenticated app.
	public: 'src/public.js',
	// Kanso's admin settings panel (#3615) - a tiny vanilla entry, no Vue, that
	// only wires up the backup-config form. Kept separate so the admin page
	// ships none of the board app.
	'admin-backup': 'src/admin-backup.js',
	// "Share from Files" (#3645) - the Files-app integration: registers the
	// "Add to Kanso…" file action + a small board+card picker dialog. Loaded only
	// on the Files app (via OCA\Files::loadAdditionalScripts), so it ships none of
	// the board app.
	files: 'src/files.js',
}, {
	// Styles must ship inside the JS bundle: the page only loads the script
	// via Util::addScript, and the alternative is a hash-named CSS chunk
	// that PHP cannot reference.
	inlineCSS: true,
})
