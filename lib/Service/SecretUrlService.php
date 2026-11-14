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
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretUrlMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCP\AppFramework\Db\DoesNotExistException;

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
	 * @param SecretUrlMapper    $mapper       The addresses table
	 * @param ShareRowJudge|null $rowJudge     Judged share rows: the recipient copies a list follows to
	 * @param SecretMapper|null  $secretMapper The secrets, to read a recipient copy before writing its list
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private SecretUrlMapper $mapper,
		private ?ShareRowJudge $rowJudge = null,
		private ?SecretMapper $secretMapper = null,
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
	 * Replace the extra addresses of the caller's own login, and of every
	 * recipient copy of it (vault-website-addresses D2). A received copy's
	 * list follows its owner, so its holder does not change it.
	 *
	 * @param Secret       $secret The login, as just saved
	 * @param string       $userId The caller
	 * @param list<string> $urls   The normalised list
	 *
	 * @return list<string> The list now stored
	 *
	 * @throws ForbiddenException When the login is not the caller's own, or is a received copy
	 *
	 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
	 */
	public function replace(Secret $secret, string $userId, array $urls): array {
		if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $userId) {
			throw new ForbiddenException(message: 'Only the owner of a login changes its addresses');
		}

		if ($this->rowJudge !== null && $this->rowJudge->forCopy(copy: $secret)->countsAsCopy() === true) {
			throw new ForbiddenException(message: 'The addresses of a shared login are changed by its owner');
		}

		$this->mapper->replaceForSecret(secretId: $secret->getId(), ownerId: $userId, urls: $urls);
		$this->replaceOnCopies(source: $secret, urls: $urls);

		return $urls;
	}//end replace()

	/**
	 * Give a new recipient copy the extra addresses of its source.
	 *
	 * @param Secret $source The owner's source secret
	 * @param Secret $copy   The recipient's copy, as just inserted
	 *
	 * @return list<string> The list the copy now carries
	 *
	 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
	 */
	public function copyOnto(Secret $source, Secret $copy): array {
		$urls = ($this->mapper->findUrlsBySecretIds(secretIds: [$source->getId()])[$source->getId()] ?? []);
		if ($urls !== []) {
			$this->mapper->replaceForSecret(secretId: $copy->getId(), ownerId: $copy->getOwnerId(), urls: $urls);
		}

		return $urls;
	}//end copyOnto()

	/**
	 * Write the owner's list onto every recipient copy whose share row the
	 * pending scan does not hold back and whose target holds the copy.
	 *
	 * @param Secret       $source The owner's login
	 * @param list<string> $urls   The owner's new list
	 *
	 * @return int The number of copies written
	 *
	 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
	 */
	private function replaceOnCopies(Secret $source, array $urls): int {
		if ($this->rowJudge === null || $this->secretMapper === null) {
			return 0;
		}

		$written = 0;
		foreach ($this->rowJudge->rowsOfSource(sourceId: $source->getId())->notHeldBack() as $row) {
			try {
				$copy = $this->secretMapper->findById((string)$row->getSecretId());
			} catch (DoesNotExistException) {
				continue;
			}

			if ($copy->getId() === $source->getId() || $row->isHeldByTarget(copy: $copy) === false) {
				continue;
			}

			$this->mapper->replaceForSecret(secretId: $copy->getId(), ownerId: $copy->getOwnerId(), urls: $urls);
			$written++;
		}

		return $written;
	}//end replaceOnCopies()

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
