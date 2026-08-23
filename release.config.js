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
				// Comment posted on each released issue/PR. Kept close to the
				// plugin default, plus one soft sponsor nudge — this fires at
				// the moment a reporter learns their fix shipped, the highest-
				// goodwill point we get with a user.
				// NOTE: single-quoted so ${...} reaches the plugin as a Lodash
				// template (evaluated at release time), not interpolated by this
				// JS module at load time — a backtick literal would crash here.
				successComment:
					':tada: This ${issue.pull_request ? "pull request is included" : "issue is fixed"} ' +
					'in version ${nextRelease.version} :tada:\n\n' +
					'The release notes are on [GitHub](${releases.filter(release => !!release.name)[0].url}).\n\n' +
					'If Kanso saves you time, consider [sponsoring its development]' +
					'(https://github.com/sponsors/aktasfatih) — it’s an AGPL solo project ' +
					'and sponsorships keep it maintained. 💜\n\n' +
					'Your semantic-release bot :package::rocket:',
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
