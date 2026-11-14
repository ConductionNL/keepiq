<?php

/**
 * Keepiq Secret Url Service
 *
 * The extra website addresses of a login (vault-website-addresses D1): up to
 * twenty, in plain text like the main address, so search and the
 * extension's match read them without the master password.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use InvalidArgumentException;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretUrlMapper;
use OCA\Keepiq\Exception\ForbiddenException;

/**
 * Checks, stores and attaches a login's extra addresses.
 *
 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
 */
class SecretUrlService {
	/**
	 * The most extra addresses one login carries.
	 */
	public const MAX_URLS = 20;

	/**
	 * The longest address stored.
	 */
	public const MAX_LENGTH = 512;

	/**
	 * Constructor.
	 *
	 * @param SecretUrlMapper $mapper The addresses table
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private SecretUrlMapper $mapper,
	) {
	}//end __construct()

	/**
	 * The list as stored: trimmed, without empty entries or repeats (ignoring
	 * case), in the order given. Refuses more than twenty addresses, an
	 * entry that is not text and an address longer than 512 characters.
	 *
	 * @param mixed $urls The `additionalUrls` of a request
	 *
	 * @return list<string>
	 *
	 * @throws InvalidArgumentException When the list cannot be stored
	 *
	 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
	 */
	public function normalise(mixed $urls): array {
		if ($urls === null || $urls === '') {
			return [];
		}

		if (is_array($urls) === false) {
			throw new InvalidArgumentException('additionalUrls must be a list of addresses');
		}

		$list = [];
		$seen = [];
		foreach ($urls as $url) {
			if (is_string($url) === false) {
				throw new InvalidArgumentException('additionalUrls must be a list of addresses');
			}

			$url = trim($url);
			$key = strtolower($url);
			if ($url === '' || isset($seen[$key]) === true) {
				continue;
			}

			if (mb_strlen($url) > self::MAX_LENGTH) {
				throw new InvalidArgumentException('An extra address may be at most ' . self::MAX_LENGTH . ' characters long');
			}

			$seen[$key] = true;
			$list[] = $url;
		}

		if (count($list) > self::MAX_URLS) {
			throw new InvalidArgumentException('A login carries at most ' . self::MAX_URLS . ' extra addresses');
		}

		return $list;
	}//end normalise()

	/**
	 * Replace the extra addresses of the caller's own login.
	 *
	 * @param Secret       $secret The login, as just saved
	 * @param string       $userId The caller
	 * @param list<string> $urls   The normalised list
	 *
	 * @return list<string> The list now stored
	 *
	 * @throws ForbiddenException When the login is not the caller's own
	 *
	 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
	 */
	public function replace(Secret $secret, string $userId, array $urls): array {
		if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $userId) {
			throw new ForbiddenException(message: 'Only the owner of a login changes its addresses');
		}

		$this->mapper->replaceForSecret(secretId: $secret->getId(), ownerId: $userId, urls: $urls);

		return $urls;
	}//end replace()

	/**
	 * Serialised secrets with their `additionalUrls`, an empty list for one
	 * without extra addresses.
	 *
	 * @param list<array<string,mixed>> $items The serialised secrets, each with an `id`
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
	 */
	public function withUrls(array $items): array {
		$ids = array_values(array_filter(array_map(static fn (array $item): string => (string)($item['id'] ?? ''), $items)));
		$urls = [];
		if ($ids !== []) {
			$urls = $this->mapper->findUrlsBySecretIds(secretIds: $ids);
		}

		foreach ($items as $index => $item) {
			$items[$index]['additionalUrls'] = ($urls[(string)($item['id'] ?? '')] ?? []);
		}

		return $items;
	}//end withUrls()
}//end class
