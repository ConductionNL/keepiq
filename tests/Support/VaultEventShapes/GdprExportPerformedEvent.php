<?php

/**
 * A GDPR export event whose first accessors return the wrong types, so AuditListener reads on to the next one.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Support\VaultEventShapes
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Support\VaultEventShapes;

use OCP\EventDispatcher\Event;


/**
 * Same short name as the real GdprExportPerformedEvent. getUserId() is not a
 * string and getUid() is null, so the actor comes from getActorId();
 * getMetadata() and getPayload() are not arrays, so the payload comes from
 * jsonSerialize().
 */
final class GdprExportPerformedEvent extends Event {

	/**
	 * Not a string.
	 *
	 * @return int
	 */
	public function getUserId(): int {
		return 7;
	}//end getUserId()

	/**
	 * Nothing.
	 *
	 * @return null
	 */
	public function getUid(): mixed {
		return null;
	}//end getUid()

	/**
	 * The actor.
	 *
	 * @return string
	 */
	public function getActorId(): string {
		return 'svc-export';
	}//end getActorId()

	/**
	 * Not an array.
	 *
	 * @return string
	 */
	public function getMetadata(): string {
		return 'not-an-array';
	}//end getMetadata()

	/**
	 * Nothing.
	 *
	 * @return null
	 */
	public function getPayload(): mixed {
		return null;
	}//end getPayload()

	/**
	 * The payload.
	 *
	 * @return array<string, string>
	 */
	public function jsonSerialize(): array {
		return ['scope' => 'all'];
	}//end jsonSerialize()
}//end class
