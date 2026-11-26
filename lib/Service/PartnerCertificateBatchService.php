<?php

/**
 * Keepiq Partner Certificate Batch Service
 *
 * Looks up the partner certificates of the caller's own active partner shares in one request, so a share-save
 * no longer spends one rate-limited call per partner share (keepiq#1256). Each distinct recipient is asked once,
 * through the same signed, allowlisted lookup as the single route; the browser still verifies every answer
 * against the pinned partner root.
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
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Kernel\Contracts\FederatedCertificateServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use RuntimeException;
use Throwable;

/**
 * Batch certificate lookup over the caller's own active partner shares.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-the-owner-looks-up-their-partner-shares-certificates-in-one-request
 */
class PartnerCertificateBatchService {

	/**
	 * At most this many partner shares per request, the bound of a share-save chunk.
	 *
	 * @var int
	 */
	public const MAX_SHARES = 100;

	/**
	 * The refusal for a share id that is unknown, not the caller's or not active: one reason for all three, so
	 * the answer says nothing about another user's shares.
	 *
	 * @var string
	 */
	public const UNKNOWN_SHARE = 'unknown_share';

	/**
	 * Constructor for PartnerCertificateBatchService.
	 *
	 * @param FederatedShareMapper $shares The partner share rows
	 * @param FederatedCertificateServiceInterface $certificates The signed single lookup
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederatedShareMapper $shares,
		private FederatedCertificateServiceInterface $certificates,
	) {
	}//end __construct()

	/**
	 * The HTTP status of a refused lookup, the same for the single and the batch route: `not_a_partner` and
	 * `outbound_disabled` 403, any other invalid lookup 404, `federation_unavailable` 409, anything else at the
	 * partner 502.
	 *
	 * @param Throwable $exception What the lookup threw
	 *
	 * @return int
	 *
	 * @psalm-return 403|404|409|502
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
	 */
	public static function refusalStatus(Throwable $exception): int {
		$statuses = [
			'not_a_partner'          => Http::STATUS_FORBIDDEN,
			'outbound_disabled'      => Http::STATUS_FORBIDDEN,
			'federation_unavailable' => Http::STATUS_CONFLICT,
		];
		$status   = ($statuses[$exception->getMessage()] ?? null);
		if ($exception instanceof InvalidArgumentException) {
			if ($status === Http::STATUS_FORBIDDEN) {
				return $status;
			}

			return Http::STATUS_NOT_FOUND;
		}

		if ($status === Http::STATUS_CONFLICT) {
			return $status;
		}

		return Http::STATUS_BAD_GATEWAY;
	}//end refusalStatus()

	/**
	 * Look up the certificate of each named partner share the user owns and that is active. A share id that is
	 * unknown, someone else's or not active is refused as `unknown_share` (404); a lookup the partner refuses is
	 * refused per share with the single route's status and reason. Each distinct recipient is asked once.
	 *
	 * @param list<string> $shareIds The partner shares, at most MAX_SHARES distinct ids
	 * @param string $userId The owner asking
	 *
	 * @return array{certificates:array<string,array<string,mixed>>,refused:array<string,array{status:int,message:string}>}
	 *
	 * @throws InvalidArgumentException `too_many_shares` above MAX_SHARES distinct ids
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-the-owner-looks-up-their-partner-shares-certificates-in-one-request
	 */
	public function lookup(array $shareIds, string $userId): array {
		$shareIds = array_values(array_unique(array_filter($shareIds, 'is_string')));
		if (count($shareIds) > self::MAX_SHARES) {
			throw new InvalidArgumentException('too_many_shares');
		}

		$result  = ['certificates' => [], 'refused' => []];
		$answers = [];
		foreach ($shareIds as $shareId) {
			$share = $this->ownActiveShare(shareId: $shareId, userId: $userId);
			if ($share === null) {
				$result['refused'][$shareId] = ['status' => Http::STATUS_NOT_FOUND, 'message' => self::UNKNOWN_SHARE];
				continue;
			}

			$cloudId = $share->getRecipientCloudId();
			if (array_key_exists($cloudId, $answers) === false) {
				$answers[$cloudId] = $this->answerFor(cloudId: $cloudId, userId: $userId);
			}

			$answer = $answers[$cloudId];
			if (isset($answer['refused']) === true) {
				$result['refused'][$shareId] = $answer['refused'];
				continue;
			}

			$result['certificates'][$shareId] = $answer['certificate'];
		}//end foreach

		return $result;
	}//end lookup()

	/**
	 * The share when it exists, belongs to the user and is active; null otherwise.
	 *
	 * @param string $shareId The partner share
	 * @param string $userId The owner asking
	 *
	 * @return FederatedShare|null
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-another-users-partner-share-is-not-looked-up
	 */
	private function ownActiveShare(string $shareId, string $userId): ?FederatedShare {
		try {
			$share = $this->shares->findById(id: $shareId);
		} catch (DoesNotExistException) {
			return null;
		}

		if ($share->getOwnerId() !== $userId || $share->getStatus() !== FederatedShare::STATUS_ACTIVE) {
			return null;
		}

		return $share;
	}//end ownActiveShare()

	/**
	 * One recipient's answer: the certificate, or the refusal with its status and reason.
	 *
	 * @param string $cloudId The recipient's cloud id
	 * @param string $userId The owner asking
	 *
	 * @return array{certificate?:array<string,mixed>,refused?:array{status:int,message:string}}
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-one-unreachable-partner-does-not-fail-the-others
	 */
	private function answerFor(string $cloudId, string $userId): array {
		try {
			return ['certificate' => $this->certificates->lookup(cloudId: $cloudId, userId: $userId)];
		} catch (InvalidArgumentException | RuntimeException $exception) {
			return [
				'refused' => [
					'status'  => self::refusalStatus(exception: $exception),
					'message' => $exception->getMessage(),
				],
			];
		}
	}//end answerFor()
}//end class
