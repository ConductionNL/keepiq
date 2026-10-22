<?php

/**
 * Keepiq View-Only Copy Guard
 *
 * Refuses a change of the value of a received copy whose holder may not
 * write its source. Such a change would reach that copy alone and split it
 * from the source and every other copy (keepiq#1287).
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

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\ViewOnlyCopyException;
use OCA\Keepiq\Service\ShareSync\ShareWriteContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

/**
 * A copy is view-only for its holder unless they write its source for every
 * copy, as ShareWriteContext::standingOn() decides it: as its owner or a
 * delegate, on a team-folder write or manage grade, or as a member of a group
 * it is shared with in full. This is the rule a sync is authorized on.
 */
class ViewOnlyCopyGuard {
	/**
	 * The fields that hold a secret's value.
	 *
	 * @var string[]
	 */
	public const VALUE_FIELDS = ['key', 'login', 'additionalFields'];

	/**
	 * Constructor.
	 *
	 * @param ShareTargetMapper $targets The share targets (copy to source)
	 * @param SecretMapper $secrets The secrets
	 * @param ShareWriteContext $contexts The holder's standing on the source
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private ShareTargetMapper $targets,
		private SecretMapper $secrets,
		private ShareWriteContext $contexts,
	) {
	}//end __construct()

	/**
	 * Refuse a change of the value of a copy its holder may not write.
	 * A change that leaves the value alone, and a row that is not a copy,
	 * pass.
	 *
	 * @param Secret $row The holder's row, already checked to be theirs
	 * @param string[] $fields The fields the change writes
	 * @param string $userId The holder
	 *
	 * @return void
	 *
	 * @throws NotFoundException When the copy's share row or source cannot be resolved
	 * @throws ViewOnlyCopyException When the holder may not write the copy's source
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-view-only-copies
	 */
	public function assertMayChangeValue(Secret $row, array $fields, string $userId): void {
		if (array_intersect(self::VALUE_FIELDS, $fields) === []) {
			return;
		}

		try {
			$target = $this->targets->findByRecipientSecret(recipientSecretId: $row->getId());
		} catch (DoesNotExistException) {
			return;
		} catch (MultipleObjectsReturnedException) {
			throw new NotFoundException(message: 'Secret not found');
		}

		try {
			$source = $this->secrets->findById($target->getSourceSecretId());
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			throw new NotFoundException(message: 'Secret not found');
		}

		// Not through resolveSyncWriter(): the services this guards must not
		// depend on the sync.
		$standing = $this->contexts->standingOn(source: $source, userId: $userId);
		if ($this->contexts->writesForEveryone(standing: $standing) === false) {
			throw new ViewOnlyCopyException();
		}
	}//end assertMayChangeValue()
}//end class
