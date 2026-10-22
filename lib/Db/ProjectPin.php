<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One user's pin on one project (table `kanso_project_pins`); pinned projects
 * are listed in the sidebar. Per user rather than a project flag, so a pin
 * stays personal once projects can be shared.
 *
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method int getProjectId()
 * @method void setProjectId(int $projectId)
 */
class ProjectPin extends Entity {
	protected ?string $uid = null;
	protected ?int $projectId = null;

	public function __construct() {
		$this->addType('uid', Types::STRING);
		$this->addType('projectId', Types::INTEGER);
	}
}
