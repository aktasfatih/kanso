<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One card/contact link (table `kanso_card_contacts`).
 *
 * A plain join row - (card_id, contact_uri) is unique. A READ-ONLY reference
 * to a Nextcloud Contacts entry for non-user stakeholders (Deck parity #3530):
 * no editing, no sync. The `display_name` is a DENORMALIZED snapshot taken at
 * link time - the card payload never re-resolves it against Contacts.
 *
 * Mirrors {@see CardAssignee}: links are never serialized as entities; the
 * board summary and card detail payloads carry a flat `contacts` array of
 * {contactUri, displayName} objects instead (batched, see {@see CardContactMapper}).
 *
 * @method int getCardId()
 * @method void setCardId(int $cardId)
 * @method string getContactUri()
 * @method void setContactUri(string $contactUri)
 * @method string getDisplayName()
 * @method void setDisplayName(string $displayName)
 */
class CardContact extends Entity {
	// Properties default to null (not to the column defaults): Entity::setter()
	// skips values equal to the current one, so a non-null default would keep
	// explicit sets of that same value out of INSERT statements.
	protected ?int $cardId = null;
	protected ?string $contactUri = null;
	protected ?string $displayName = null;

	public function __construct() {
		$this->addType('cardId', Types::INTEGER);
		$this->addType('contactUri', Types::STRING);
		$this->addType('displayName', Types::STRING);
	}
}
