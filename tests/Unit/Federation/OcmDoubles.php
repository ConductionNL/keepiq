<?php

/**
 * In-memory OCM share and notification, shaped like Nextcloud 35's private
 * OC\Federation\CloudFederationShare and CloudFederationNotification, which
 * a unit test cannot load. Only the public interface is implemented, and
 * getShareSecret() reads the protocol the way the server does.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Federation
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

namespace OCA\Keepiq\Tests\Unit\Federation;

use OCP\Federation\ICloudFederationNotification;
use OCP\Federation\ICloudFederationShare;

/**
 * An OCM share as the server builds and receives it.
 */
class FakeCloudFederationShare implements ICloudFederationShare {
	/** @var array<string,mixed> */
	public array $share = [
		'shareWith' => '',
		'shareType' => '',
		'name' => '',
		'resourceType' => '',
		'description' => '',
		'providerId' => '',
		'owner' => '',
		'ownerDisplayName' => '',
		'sharedBy' => '',
		'sharedByDisplayName' => '',
		'protocol' => [],
	];

	public function setShareWith($user) {
		$this->share['shareWith'] = $user;
	}

	public function setResourceName($name) {
		$this->share['name'] = $name;
	}

	public function setResourceType($resourceType) {
		$this->share['resourceType'] = $resourceType;
	}

	public function setDescription($description) {
		$this->share['description'] = $description;
	}

	public function setProviderId($providerId) {
		$this->share['providerId'] = (string)$providerId;
	}

	public function setOwner($owner) {
		$this->share['owner'] = $owner;
	}

	public function setOwnerDisplayName($ownerDisplayName) {
		$this->share['ownerDisplayName'] = $ownerDisplayName;
	}

	public function setSharedBy($sharedBy) {
		$this->share['sharedBy'] = $sharedBy;
	}

	public function setSharedByDisplayName($sharedByDisplayName) {
		$this->share['sharedByDisplayName'] = $sharedByDisplayName;
	}

	public function setProtocol(array $protocol) {
		$this->share['protocol'] = $protocol;
	}

	public function setShareType($shareType) {
		$this->share['shareType'] = $shareType;
	}

	public function getShare() {
		return $this->share;
	}

	public function getShareWith() {
		return $this->share['shareWith'];
	}

	public function getResourceName() {
		return $this->share['name'];
	}

	public function getResourceType() {
		return $this->share['resourceType'];
	}

	public function getDescription() {
		return $this->share['description'];
	}

	public function getProviderId() {
		return $this->share['providerId'];
	}

	public function getOwner() {
		return $this->share['owner'];
	}

	public function getOwnerDisplayName() {
		return $this->share['ownerDisplayName'];
	}

	public function getSharedBy() {
		return $this->share['sharedBy'];
	}

	public function getSharedByDisplayName() {
		return $this->share['sharedByDisplayName'];
	}

	public function getShareType() {
		return $this->share['shareType'];
	}

	public function getShareSecret() {
		$protocol = $this->share['protocol'];
		if (isset($protocol['options']['sharedSecret'])) {
			return $protocol['options']['sharedSecret'];
		}

		$name = $protocol['name'] ?? null;
		if (is_string($name) && isset($protocol[$name]['sharedSecret'])) {
			return $protocol[$name]['sharedSecret'];
		}

		return '';
	}

	public function getProtocol() {
		return $this->share['protocol'];
	}
}

/**
 * An OCM notification as the server builds it.
 */
class FakeCloudFederationNotification implements ICloudFederationNotification {
	/** @var array<string,mixed> */
	public array $message = [];

	public function setMessage($notificationType, $resourceType, $providerId, array $notification) {
		$this->message = [
			'notificationType' => $notificationType,
			'resourceType' => $resourceType,
			'providerId' => (string)$providerId,
			'notification' => $notification,
		];
	}

	public function getMessage() {
		return $this->message;
	}
}
