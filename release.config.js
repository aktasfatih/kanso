// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// semantic-release configuration. The release is derived entirely from the
// Conventional-Commit history since the last git tag:
//   fix:  → patch (0.9.37 → 0.9.38)   feat: → minor (0.9.37 → 0.10.0)
//   commit body/footer "BREAKING CHANGE:" → major.
// chore/test/refactor/ci/docs/style produce no release. See docs/RELEASING.md.

// Preserved verbatim at the top of CHANGELOG.md; new version sections are
// inserted immediately below it, newest first.
const changelogTitle = `<!--
  - SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Changelog

All notable changes to Kanso are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
This file is generated from Conventional Commits by semantic-release — do not edit by hand.`

export default {
	branches: ['main'],
	plugins: [
		'@semantic-release/commit-analyzer',
		'@semantic-release/release-notes-generator',
		[
			'@semantic-release/changelog',
			{ changelogFile: 'CHANGELOG.md', changelogTitle },
		],
		[
			'@semantic-release/exec',
			{
				// prepare: stamp the computed version into info.xml/package.json,
				// then build + sign the release tarball (SKIP_BUILD=1 reuses the
				// js/ bundle the workflow already built).
				prepareCmd:
					'bash scripts/apply-version.sh ${nextRelease.version} && bash scripts/build-release.sh',
				// success: runs AFTER the GitHub release exists, so the store can
				// fetch the release asset. No-op until NC_APPSTORE_TOKEN is set.
				successCmd: 'bash scripts/publish-appstore.sh ${nextRelease.version}',
			},
		],
		[
			'@semantic-release/github',
			{
				assets: [
					{ path: 'build/kanso.tar.gz', label: 'kanso.tar.gz' },
					{ path: 'build/kanso.tar.gz.sig.base64', label: 'kanso.tar.gz.sig.base64' },
				],
			},
		],
		[
			'@semantic-release/git',
			{
				assets: ['CHANGELOG.md', 'appinfo/info.xml', 'package.json'],
				message: 'chore(release): ${nextRelease.version} [skip ci]\n\n${nextRelease.notes}',
			},
		],
	],
}
