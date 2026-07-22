// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
	main: 'src/main.js',
}, {
	// Styles must ship inside the JS bundle: the page only loads the script
	// via Util::addScript, and the alternative is a hash-named CSS chunk
	// that PHP cannot reference.
	inlineCSS: true,
})
