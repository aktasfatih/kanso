// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Entry point for the UNAUTHENTICATED read-only public board share (#3531).
// Deliberately tiny: it mounts a single self-contained view that fetches the
// STRIPPED public payload and renders the board read-only. It pulls in none of
// the authenticated app (no router, no query client, no board mutation code).

import { createApp } from 'vue'
import PublicBoard from './views/PublicBoard.vue'

const mount = document.getElementById('kanso-public')
const token = mount?.dataset?.token || ''

createApp(PublicBoard, { token }).mount(mount)
