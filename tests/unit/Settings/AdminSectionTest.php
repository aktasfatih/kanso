<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Settings;

use OCA\Kanso\Settings\AdminSection;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the admin settings section icon (issue #162).
 *
 * Nextcloud renders the settings-navigation icon with
 * `filter: var(--background-invert-if-dark)` — `no` on light themes,
 * `invert(100%)` on dark ones (see apps/theming DefaultTheme/DarkTheme). The
 * asset therefore has to be DARK, like every other app's `*-dark.svg` section
 * icon. Pointing this at the white app-menu icon made the entry invisible on
 * light themes and inconsistent everywhere else.
 */
class AdminSectionTest extends TestCase {
	private IL10N&MockObject $l10n;
	private IURLGenerator&MockObject $urlGenerator;
	private AdminSection $section;

	protected function setUp(): void {
		parent::setUp();
		$this->l10n = $this->createMock(IL10N::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->section = new AdminSection($this->l10n, $this->urlGenerator);
	}

	public function testSectionIconUsesTheDarkVariantSoItAdaptsToTheTheme(): void {
		$this->urlGenerator->expects($this->once())
			->method('imagePath')
			->with('kanso', 'app-dark.svg')
			->willReturn('/custom_apps/kanso/img/app-dark.svg');

		$this->assertSame('/custom_apps/kanso/img/app-dark.svg', $this->section->getIcon());
	}

	public function testDarkSectionIconAssetExistsAndIsNotWhite(): void {
		$path = dirname(__DIR__, 3) . '/img/app-dark.svg';
		$this->assertFileExists($path, 'AdminSection::getIcon() points at img/app-dark.svg; imagePath() throws when it is missing');

		$svg = (string)file_get_contents($path);
		$this->assertStringStartsWith('<svg', ltrim($svg), 'a leading comment breaks Imagick/theming icon generation');
		$this->assertDoesNotMatchRegularExpression(
			'/fill\s*[:=]\s*["\']?\s*(#fff(fff)?\b|white\b)/i',
			$svg,
			'img/app-dark.svg must stay dark — a white fill is invisible on light themes (issue #162)'
		);
	}

	public function testAppMenuIconStaysWhite(): void {
		// The counterpart guard: img/app.svg is the app-menu / notification icon
		// and Nextcloud renders it white there (core apps ship `fill="#fff"`
		// too). Fixing #162 by darkening this file would just move the bug.
		$svg = (string)file_get_contents(dirname(__DIR__, 3) . '/img/app.svg');

		$this->assertMatchesRegularExpression(
			'/fill\s*[:=]\s*["\']?\s*(#fff(fff)?\b|white\b)/i',
			$svg,
			'img/app.svg is the app-menu icon and must stay white'
		);
	}
}
