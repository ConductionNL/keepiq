<?php

/**
 * Contract of SecretOrganisationService
 *
 * The public surface of SecretOrganisationService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Keepiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/keepiq
 *
 * @spec openspec/specs/app-shell/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Kernel\Contracts;

use InvalidArgumentException;
use OCA\Keepiq\Exception\NotFoundException;

/**
 * Contract of SecretOrganisationService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SecretOrganisationServiceInterface {

	/**
	 * The holder's tags with how many live secrets carry each.
	 *
	 * @param string $userId The holder
	 *
	 * @return list<array{tag: string, count: int}>
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
	 */
	public function listTags(string $userId): array;

	/**
	 * Record that the holder used the row now (a fill from the extension).
	 *
	 * @param string $id     The row
	 * @param string $userId The holder
	 *
	 * @return void
	 *
	 * @throws NotFoundException When the user does not hold the row
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-sort-by-date-last-used
	 */
	public function markUsed(string $id, string $userId): void;

	/**
	 * Star or unstar the holder's row.
	 *
	 * @param string $id        The row
	 * @param string $userId    The holder
	 * @param bool   $favourite The new star
	 *
	 * @return array{id: string, favourite: bool}
	 *
	 * @throws NotFoundException When the user does not hold the row
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-favourite-items-per-holder
	 */
	public function setFavourite(string $id, string $userId, bool $favourite): array;

	/**
	 * Replace the tags on the holder's row.
	 *
	 * @param string       $id     The row
	 * @param string       $userId The holder
	 * @param array<mixed> $tags   The tags as sent
	 *
	 * @return list<string> The stored tags
	 *
	 * @throws NotFoundException When the user does not hold the row
	 * @throws InvalidArgumentException When a tag is too long or there are too many
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
	 */
	public function setTags(string $id, string $userId, array $tags): array;
}//end interface
