<?php

/**
 * Keepiq Device Approval Request
 *
 * What a locked device asks for when it requests approval.
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

use DateInterval;
use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\DeviceApproval;
use Ramsey\Uuid\Uuid;

/**
 * The signed-in user, the device's one-time public key, its kind and label,
 * and the caller's address and user agent: each one recorded fact of the
 * request.
 *
 * @spec openspec/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
 */
final class DeviceApprovalRequest {
	/**
	 * Constructor for DeviceApprovalRequest.
	 *
	 * @param string $userId     The signed-in user
	 * @param string $publicKey  The one-time X25519 public key (base64, 32 bytes)
	 * @param string $clientKind `web` or `extension`
	 * @param string $label      The device label
	 * @param string $address    The caller's IP address
	 * @param string $agent      The caller's user agent
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $userId,
		public readonly string $publicKey,
		public readonly string $clientKind,
		public readonly string $label,
		public readonly string $address,
		public readonly string $agent,
	) {
	}//end __construct()

	/**
	 * Refuse a public key that is not a raw 32-byte X25519 key, and a client
	 * kind other than web or extension.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the input is malformed
	 *
	 * @spec openspec/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
	 */
	public function assertValid(): void {
		$raw = base64_decode($this->publicKey, true);
		if ($raw === false || strlen($raw) !== 32) {
			throw new InvalidArgumentException(message: 'publicKey must be a raw X25519 public key');
		}

		if (in_array($this->clientKind, DeviceApprovalService::CLIENT_KINDS, true) === false) {
			throw new InvalidArgumentException(message: 'clientKind must be web or extension');
		}
	}//end assertValid()

	/**
	 * The pending request row, with the label, address and agent cut to
	 * their columns, expiring after the request lifetime.
	 *
	 * @param string $secretHash The SHA-256 of the request secret
	 * @param DateTime $now The moment of the request
	 *
	 * @return DeviceApproval
	 *
	 * @spec openspec/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
	 */
	public function toRow(string $secretHash, DateTime $now): DeviceApproval {
		$request = new DeviceApproval();
		$request->setId(Uuid::uuid4()->toString());
		$request->setUserId($this->userId);
		$request->setClientKind($this->clientKind);
		$request->setDeviceLabel(mb_substr(trim($this->label), 0, 255));
		$request->setRequesterIp(mb_substr($this->address, 0, 64));
		$request->setRequesterAgent(mb_substr($this->agent, 0, 512));
		$request->setRequestPublicKey($this->publicKey);
		$request->setRequestSecretHash($secretHash);
		$request->setStatus(DeviceApproval::STATUS_PENDING);
		$request->setCreatedAt($now);
		$request->setExpiresAt((clone $now)->add(new DateInterval('PT' . DeviceApprovalService::TTL_SECONDS . 'S')));

		return $request;
	}//end toRow()
}//end class
