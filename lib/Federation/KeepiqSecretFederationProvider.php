<?php

/**
 * Keepiq Secret Federation Provider
 *
 * The OCM provider for resource type `keepiq-secret`
 * (sharing-federated-recipients D4). Nextcloud's cloud_federation_api hands
 * it every incoming share and notification of that type; the checks live in
 * the services it delegates to.
 *
 * @category Federation
 * @package  OCA\Keepiq\Federation
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

namespace OCA\Keepiq\Federation;

use OCA\Keepiq\Service\FederatedShareReceiver;
use OCA\Keepiq\Service\FederatedShareService;
use OCP\Federation\Exceptions\ActionNotSupportedException;
use OCP\Federation\ICloudFederationProvider;
use OCP\Federation\ICloudFederationShare;

/**
 * Receives Keepiq secrets shared from partner instances.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
class KeepiqSecretFederationProvider implements ICloudFederationProvider {
	/**
	 * Constructor for KeepiqSecretFederationProvider.
	 *
	 * @param FederatedShareReceiver $receiver Takes incoming shares in
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederatedShareReceiver $receiver,
	) {
	}//end __construct()

	/**
	 * The resource type this provider handles.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function getShareType() {
		return FederatedShareService::RESOURCE_TYPE;
	}//end getShareType()

	/**
	 * Store an incoming share as pending, or refuse it.
	 *
	 * @param ICloudFederationShare $share The share
	 *
	 * @return string The local inbound share id
	 *
	 * @throws \OCP\Federation\Exceptions\ProviderCouldNotAddShareException When refused
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-a-non-partner-cannot-deliver
	 */
	public function shareReceived(ICloudFederationShare $share) {
		return $this->receiver->receive(share: $share);
	}//end shareReceived()

	/**
	 * Handle a notification about a share.
	 *
	 * @param string $notificationType The notification type
	 * @param string $providerId The share id on the sender
	 * @param array<array-key,mixed> $notification The payload
	 *
	 * @return array<string,mixed>
	 *
	 * @throws ActionNotSupportedException For every type until updates and revocation arrive (task 4.1, 4.2)
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by ICloudFederationProvider.
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	public function notificationReceived(string $notificationType, string $providerId, array $notification) {
		throw new ActionNotSupportedException($notificationType);
	}//end notificationReceived()

	/**
	 * Keepiq shares go to users only.
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function getSupportedShareTypes() {
		return ['user'];
	}//end getSupportedShareTypes()
}//end class
