<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One record of a Deck board having been imported into Kanso (table
 * `kanso_deck_imports`), written inside the import's own transaction so the
 * record and the imported board are all-or-nothing together.
 *
 * Unique per (deckBoardId, importedBy) - per IMPORTING USER, never globally: a
 * board shared with two people is legitimately importable by each of them, and a
 * global key would silently refuse the second one mid-migration.
 *
 * `kansoBoardId` points at the most recent import of that pair; a confirmed
 * re-import replaces the row rather than adding a second one.
 *
 * @method int getDeckBoardId()
 * @method void setDeckBoardId(int $deckBoardId)
 * @method int getKansoBoardId()
 * @method void setKansoBoardId(int $kansoBoardId)
 * @method string getImportedBy()
 * @method void setImportedBy(string $importedBy)
 * @method int getImportedAt()
 * @method void setImportedAt(int $importedAt)
 */
class DeckImport extends Entity {
	protected ?int $deckBoardId = null;
	protected ?int $kansoBoardId = null;
	protected ?string $importedBy = null;
	protected ?int $importedAt = null;

	public function __construct() {
		$this->addType('deckBoardId', Types::INTEGER);
		$this->addType('kansoBoardId', Types::INTEGER);
		$this->addType('importedBy', Types::STRING);
		$this->addType('importedAt', Types::INTEGER);
	}
}
