// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
	main: 'src/main.js',
	// The unauthenticated read-only public-share app (#3531) - a separate, small
	// entry so the public page ships none of the authenticated app.
	public: 'src/public.js',
}, {
	// Styles must ship inside the JS bundle: the page only loads the script
	// via Util::addScript, and the alternative is a hash-named CSS chunk
	// that PHP cannot reference.
	inlineCSS: true,
})
