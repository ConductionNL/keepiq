<?php

/**
 * Keepiq BrokerMintRecorder
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

use DateTime;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Keepiq\Db\BrokerMint;
use OCA\Keepiq\Db\BrokerMintMapper;
use OCA\Keepiq\Db\Secret;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * What the credential broker tells keepiq when it mints a secret for an
 * application, beyond the ciphertext: the certificate's `notAfter`, stored as
 * the secret's `expires_at` without decrypting anything, and the organisation
 * and consuming app it was minted for, which the expiry answer to integriq is
 * gated on (connection-certificate-expiry-for-integriq tasks 1.1 and 2.1).
 *
 * @spec openspec/specs/certificate-lifecycle/spec.md#requirement-a-certificate-minted-for-a-connection-carries-its-expiry
 */
class BrokerMintRecorder {
	/**
	 * The data key carrying the certificate's expiry (ISO 8601).
	 *
	 * @var string
	 */
	public const NOT_AFTER = 'notAfter';

	/**
	 * The data key carrying the organisation the credential belongs to.
	 *
	 * @var string
	 */
	public const ORGANISATION = 'organisation';

	/**
	 * The data key carrying the app the credential was minted for.
	 *
	 * @var string
	 */
	public const CONSUMER = 'consumer';

	/**
	 * Constructor.
	 *
	 * @param BrokerMintMapper $mints The mint records
	 */
	public function __construct(
		private BrokerMintMapper $mints,
	) {
	}//end __construct()

	/**
	 * Set the secret's `expires_at` from a sent `notAfter`; the ciphertext is not touched.
	 * Nothing happens when `notAfter` is not sent; a sent null clears the expiry.
	 *
	 * @param Secret               $secret The secret being written
	 * @param array<string, mixed> $data   The application's write
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When `notAfter` is not a date
	 *
	 * @spec openspec/specs/certificate-lifecycle/spec.md#requirement-a-certificate-minted-for-a-connection-carries-its-expiry
	 */
	public function applyExpiry(Secret $secret, array $data): void {
		if (array_key_exists(self::NOT_AFTER, $data) === false) {
			return;
		}

		$secret->setExpiresAt(self::parseNotAfter(value: $data[self::NOT_AFTER]));
	}//end applyExpiry()

	/**
	 * Record the organisation and consumer a sent write names for the secret,
	 * replacing an earlier record. Nothing happens when neither is sent.
	 *
	 * @param Secret               $secret The stored secret
	 * @param array<string, mixed> $data   The application's write
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-boundary/spec.md#requirement-integriq-may-learn-when-its-own-connection-certificate-expires
	 */
	public function record(Secret $secret, array $data): void {
		if (array_key_exists(self::ORGANISATION, $data) === false && array_key_exists(self::CONSUMER, $data) === false) {
			return;
		}

		$mint = new BrokerMint();
		$mint->setId(Uuid::uuid4()->toString());
		$mint->setSecretId($secret->getId());
		$mint->setOrganisationId(self::text(value: $data[self::ORGANISATION] ?? null));
		$mint->setConsumer(self::text(value: $data[self::CONSUMER] ?? null));
		$mint->setCreatedAt(new DateTime());

		$this->mints->deleteBySecret(secretId: $secret->getId());
		$this->mints->insert($mint);
	}//end record()

	/**
	 * A `notAfter` as a UTC date, null for an empty one.
	 *
	 * @param mixed $value The sent value
	 *
	 * @return DateTime|null
	 *
	 * @throws InvalidArgumentException When it is not a date
	 */
	private static function parseNotAfter(mixed $value): ?DateTime {
		if ($value === null || $value === '') {
			return null;
		}

		if (is_string($value) === false) {
			throw new InvalidArgumentException('notAfter must be an ISO 8601 date');
		}

		try {
			$date = new DateTime($value);
		} catch (Throwable) {
			throw new InvalidArgumentException('notAfter must be an ISO 8601 date');
		}

		return $date->setTimezone(new DateTimeZone('UTC'));
	}//end parseNotAfter()

	/**
	 * A trimmed string, null for an empty or non-string value.
	 *
	 * @param mixed $value The sent value
	 *
	 * @return string|null
	 */
	private static function text(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end text()
}//end class
