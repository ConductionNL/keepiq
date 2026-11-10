<?php

/**
 * Keepiq Honey Access
 *
 * One observed access to a possibly honey-flagged secret.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use DateTime;
use OCA\Keepiq\Db\HoneyAlert;
use OCA\Keepiq\Db\HoneyFlag;
use Ramsey\Uuid\Uuid;

/**
 * The accessed secret, who accessed it through which channel, and the
 * transport metadata when available.
 *
 * @spec openspec/changes/honey-credentials/specs/honey-credentials/spec.md
 */
final class HoneyAccess {
	/**
	 * Constructor for HoneyAccess.
	 *
	 * @param string $secretId The accessed secret UUID
	 * @param string $accessorType user|application|link_visitor|system
	 * @param string|null $accessorId The accessor id (null = anonymous)
	 * @param string $channel ui|machine_api|link|share
	 * @param string|null $remoteIp Remote address when available
	 * @param string|null $userAgent User agent when available
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $secretId,
		public readonly string $accessorType,
		public readonly ?string $accessorId,
		public readonly string $channel,
		public readonly ?string $remoteIp = null,
		public readonly ?string $userAgent = null,
	) {
	}//end __construct()

	/**
	 * How a page names the accessor.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/honey-credentials/specs/honey-credentials/spec.md
	 */
	public function accessorLabel(): string {
		return ($this->accessorId ?? 'anonymous');
	}//end accessorLabel()

	/**
	 * A new alert for this access to a flagged secret, counted once.
	 *
	 * @param HoneyFlag $flag The honey flag of the accessed secret
	 * @param DateTime $now The access moment
	 *
	 * @return HoneyAlert
	 *
	 * @spec openspec/changes/honey-credentials/specs/honey-credentials/spec.md
	 */
	public function alertFor(HoneyFlag $flag, DateTime $now): HoneyAlert {
		$alert = new HoneyAlert();
		$alert->setId(Uuid::uuid4()->toString());
		$alert->setHoneyFlagId($flag->getId());
		$alert->setSecretId($this->secretId);
		$alert->setAccessorType($this->accessorType);
		$alert->setAccessorId($this->accessorId);
		$alert->setChannel($this->channel);
		$alert->setIp($this->remoteIp);
		$alert->setUserAgent($this->userAgent);
		$alert->setAccessCount(1);
		$alert->setAccessedAt($now);

		return $alert;
	}//end alertFor()
}//end class
