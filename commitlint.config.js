// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Enforces Conventional Commits so semantic-release can derive the version and
// changelog from the history. A commit whose message is not `type(scope):
// subject` is rejected by the husky commit-msg hook (.husky/commit-msg).
export default {
	extends: ['@commitlint/config-conventional'],
}
