<!--
  SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Kanso website

The project's public landing page. **One page**, deliberately:
`index.html` is hand-written, self-contained static HTML with inline CSS — no
build step, no framework, no bundler, no CDN, no JavaScript, no analytics.
(The one `<script>` tag is `application/ld+json` structured data for search
engines — it is never executed.)

This is *not* a documentation site. `docs/` stays a repo-only tree, so nothing
here adds a "keep the published docs fresh" obligation to the release bar. The
page's job is to look credible and route visitors to the
[App Store listing](https://apps.nextcloud.com/apps/kanso) and the repository.

```
website/
├── index.html        # the whole site
├── sitemap.xml       # one-URL sitemap, submitted to Search Console
├── screenshots/      # copies of docs/kanso-*.png
└── README.md         # this file
```

## Working on it

Open `website/index.html` in a browser. That's the whole loop — what you see
from disk is what gets deployed, because the deploy uploads this directory
verbatim.

Things to keep true if you edit it:

- **No version numbers on the page.** They rot between releases. Link to
  [Releases](https://github.com/aktasfatih/kanso/releases) or the App Store
  instead.
- **Every claim must be sourced** from `README.md`, `appinfo/info.xml` or the
  live App Store listing. Don't write marketing copy the app can't back up.
- **The sponsor tiers mirror GitHub Sponsors.** The prices and perks in
  `#support` are copied from <https://github.com/sponsors/aktasfatih>. When a
  tier is retired or added there, update the page in the same change.
- **No competitor names.** Functional references ("import from Deck") are fine;
  comparisons are not.
- **Don't copy the App Store long description** — link to it, or it goes stale
  in two places.
- Keep it responsive, keep the `alt` text real, and keep the
  `prefers-color-scheme` dark palette working. A Nextcloud audience notices.

`screenshots/` holds copies of the four PNGs in `docs/` that the README and
`appinfo/info.xml` already use. When those are refreshed, copy them over again:

```sh
cp docs/kanso-*.png website/screenshots/
```

Nothing in `website/` ships to users: `scripts/build-release.sh` stages the app
tarball from an explicit allowlist (`appinfo lib js img templates CHANGELOG.md
README.md`), so this directory is excluded from the App Store release by
construction.

## Deploying

`.github/workflows/pages.yml` publishes this directory to GitHub Pages. It runs
only on pushes to `main` that touch `website/**` or the workflow itself, plus a
manual **Run workflow** button — it cannot fire on unrelated pushes, and it does
not run on pull requests, so it never affects the CI merge gate.

### Enabling Pages (one-time, needs repo admin)

The workflow is inert until Pages is switched on. A repository administrator
has to do this by hand:

1. Go to **Settings → Pages** on <https://github.com/aktasfatih/kanso>.
2. Under **Build and deployment → Source**, select **GitHub Actions**
   (not "Deploy from a branch").
3. Merge this directory to `main` — or go to **Actions → Website → Run
   workflow** to deploy without a new commit.
4. The site appears at **<https://aktasfatih.com/kanso/>**. The first
   deploy can take a couple of minutes.

The custom domain comes from the account-level `aktasfatih.github.io` Pages
site, so `aktasfatih.github.io/kanso/` redirects to it; nothing in this
directory configures it. `index.html` declares that URL as canonical, and
`appinfo/info.xml` `<website>` (shown on the App Store listing) points at it.

### Search

`sitemap.xml` lists the page. Add `aktasfatih.com` as a property in Google
Search Console (DNS verification), then submit
`https://aktasfatih.com/kanso/sitemap.xml`.
