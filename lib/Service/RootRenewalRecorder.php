<?php

/**
 * Keepiq Root Renewal Recorder
 *
 * What a root renewal adds for partners (partner-root-renewal-announcement D1, D2): the previous root's key vouches
 * for the new root, the renewal is stored in one transaction, and once that commits RootRenewedEvent queues the
 * notices to partners.
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

use Exception;
use OCA\Keepiq\Event\RootRenewedEvent;
use OCP\AppFramework\Db\TTransactional;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OpenSSLAsymmetricKey;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Signs, stores and announces a renewed root.
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-a-renewed-root-is-announced-to-partners
 */
class RootRenewalRecorder {
	use TTransactional;

	/**
	 * Constructor for RootRenewalRecorder.
	 *
	 * @param IDBConnection $db Stores a renewed root in one transaction
	 * @param IEventDispatcher $dispatcher Announces a renewed root once it is stored
	 * @param RootRenewalSignature $signatures Signs a renewed root with the root it replaces
	 * @param LoggerInterface $logger Warns when the previous root's key cannot be read
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private IDBConnection $db,
		private IEventDispatcher $dispatcher,
		private RootRenewalSignature $signatures,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The previous root's private key, or null with a warning when it cannot be read, for example after the instance
	 * secret changed. The root is then renewed unannounced.
	 *
	 * @param callable():string $decrypt Decrypts the previous root's stored key
	 *
	 * @return OpenSSLAsymmetricKey|null
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#scenario-a-root-whose-key-cannot-be-read-is-renewed-unannounced
	 */
	public function previousKey(callable $decrypt): ?OpenSSLAsymmetricKey {
		try {
			$key = openssl_pkey_get_private(private_key: $decrypt());
		} catch (Exception) {
			$key = false;
		}

		if ($key === false) {
			$this->logger->warning(
				'Keepiq: the current root CA private key cannot be read; the root is renewed without announcing it, so every partner has to re-pin it by hand'
			);
			return null;
		}

		return $key;
	}//end previousKey()

	/**
	 * The previous root's signature over the new root.
	 *
	 * @param string $newRootPem The new root certificate
	 * @param OpenSSLAsymmetricKey $previousKey The previous root's private key
	 *
	 * @return string The base64 signature
	 *
	 * @throws RuntimeException When signing fails
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-a-renewed-root-is-announced-to-partners
	 */
	public function sign(string $newRootPem, OpenSSLAsymmetricKey $previousKey): string {
		$signature = $this->signatures->sign(newRootPem: $newRootPem, previousKey: $previousKey);
		if ($signature === null) {
			throw new RuntimeException('Failed to sign the new root CA with the current root: ' . openssl_error_string());
		}

		return $signature;
	}//end sign()

	/**
	 * Run the store in one transaction, then announce the renewal. Nothing is announced when the store fails.
	 *
	 * @param callable():void $store Stores the new root and links the old rows to it
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-a-renewed-root-is-announced-to-partners
	 */
	public function record(callable $store): void {
		$this->recordUnannounced(store: $store);
		$this->dispatcher->dispatchTyped(new RootRenewedEvent());
	}//end record()

	/**
	 * Run the store in one transaction and tell no partner: for a renewal the previous root could not sign.
	 *
	 * @param callable():void $store Stores the new root and links the old rows to it
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#scenario-a-root-whose-key-cannot-be-read-is-renewed-unannounced
	 */
	public function recordUnannounced(callable $store): void {
		$this->atomic(fn: $store, db: $this->db);
	}//end recordUnannounced()
}//end class
