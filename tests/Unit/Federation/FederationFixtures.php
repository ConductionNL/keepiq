<?php

/**
 * Shared doubles for the federation tests: a partner table in memory, a
 * cloud id manager for one local instance, and a two-certificate CA.
 *
 * @category Tests
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

use InvalidArgumentException;
use OCA\Keepiq\Db\CACertificate;
use OCA\Keepiq\Db\CACertificateMapper;
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Db\FederationPartnerMapper;
use OCA\Keepiq\Service\FederationRootService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Federation\ICloudId;
use OCP\Federation\ICloudIdManager;
use OCP\OCM\Events\OCMEndpointRequestEvent;
use PHPUnit\Framework\TestCase;

trait FederationFixtures {
	/** This instance's host. */
	private string $localHost = 'cloud.here.example';

	/**
	 * Skip on a Nextcloud that cannot federate.
	 *
	 * The OCM APIs these tests use arrived in Nextcloud 33; Keepiq supports 32,
	 * where FederationRootService::isSupported() is false and federation stays
	 * off. The same check decides both.
	 *
	 * @return void
	 */
	private function skipWithoutFederation(): void {
		if (class_exists(OCMEndpointRequestEvent::class) === false) {
			$this->markTestSkipped('Federation needs the OCM APIs of Nextcloud 33 or later.');
		}
	}//end skipWithoutFederation()

	/**
	 * A partner mapper over an in-memory list.
	 *
	 * @param array<int,FederationPartner> $partners The rows
	 *
	 * @return FederationPartnerMapper
	 */
	private function partnerMapper(array $partners): FederationPartnerMapper {
		/** @var TestCase $this */
		$mapper = $this->createMock(FederationPartnerMapper::class);
		$mapper->method('findAllPartners')->willReturn($partners);
		$mapper->method('findByHost')->willReturnCallback(
			static function (string $host) use ($partners): FederationPartner {
				foreach ($partners as $partner) {
					if ($partner->getHost() === $host) {
						return $partner;
					}
				}
				throw new DoesNotExistException('none');
			}
		);
		$mapper->method('insert')->willReturnArgument(0);

		return $mapper;
	}

	/**
	 * A partner row.
	 *
	 * @param string $host The partner host
	 * @param bool $outbound Outbound allowed
	 * @param bool $inbound Inbound allowed
	 *
	 * @return FederationPartner
	 */
	private function partner(string $host, bool $outbound, bool $inbound): FederationPartner {
		$partner = new FederationPartner();
		$partner->setId('p-' . $host);
		$partner->setBaseUrl('https://' . $host);
		$partner->setHost($host);
		$partner->setRootFingerprint(str_repeat('ab', 32));
		$partner->setAllowOutbound($outbound);
		$partner->setAllowInbound($inbound);
		$partner->setAddedBy('admin');

		return $partner;
	}

	/**
	 * A cloud id manager that knows `user@host` ids and this instance's host.
	 *
	 * @return ICloudIdManager
	 */
	private function cloudIdManager(): ICloudIdManager {
		/** @var TestCase $this */
		$manager = $this->createMock(ICloudIdManager::class);
		$make = function (string $user, string $remote): ICloudId {
			$cloudId = $this->createMock(ICloudId::class);
			$cloudId->method('getUser')->willReturn($user);
			$cloudId->method('getRemote')->willReturn($remote);
			$cloudId->method('getId')->willReturn($user . '@' . $remote);
			return $cloudId;
		};
		$manager->method('isValidCloudId')->willReturnCallback(
			static fn (string $id): bool => substr_count($id, '@') === 1 && str_starts_with($id, '@') === false
		);
		$manager->method('resolveCloudId')->willReturnCallback(
			static function (string $id) use ($make): ICloudId {
				if (substr_count($id, '@') !== 1) {
					throw new InvalidArgumentException('invalid cloud id');
				}
				[$user, $remote] = explode('@', $id);
				return $make($user, $remote);
			}
		);
		$manager->method('getCloudId')->willReturnCallback(
			fn (string $user, ?string $remote): ICloudId => $make($user, $remote ?? $this->localHost)
		);

		return $manager;
	}

	/**
	 * A CA mapper with a root and an intermediate, or none.
	 *
	 * @param bool $present Whether the CA exists
	 *
	 * @return CACertificateMapper
	 */
	private function caMapper(bool $present = true): CACertificateMapper {
		/** @var TestCase $this */
		$mapper = $this->createMock(CACertificateMapper::class);
		if ($present === false) {
			$mapper->method('findRoot')->willThrowException(new DoesNotExistException('none'));
			$mapper->method('findActiveIntermediate')->willThrowException(new DoesNotExistException('none'));
			return $mapper;
		}

		$root = new CACertificate();
		$root->setCertificate(self::ROOT_PEM);
		$intermediate = new CACertificate();
		$intermediate->setCertificate(self::INTERMEDIATE_PEM);
		$mapper->method('findRoot')->willReturn($root);
		$mapper->method('findActiveIntermediate')->willReturn($intermediate);

		return $mapper;
	}

	/**
	 * The local root service over a CA mapper, with a clock at `$now`: by default five days after the renewal of
	 * renewedCaMapper(), so that renewal is still announced.
	 *
	 * @param CACertificateMapper $caMapper The CA mapper
	 * @param string $now The clock's time
	 *
	 * @return FederationRootService
	 */
	private function rootService(CACertificateMapper $caMapper, string $now = '2026-11-25T10:00:00Z'): FederationRootService {
		/** @var TestCase $this */
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime($now));

		return new FederationRootService($caMapper, $time);
	}

	/**
	 * A CA mapper whose root was renewed on 2026-11-20 10:00 UTC: it replaced PREVIOUS_ROOT_PEM, which signed it.
	 *
	 * @return CACertificateMapper
	 */
	private function renewedCaMapper(): CACertificateMapper {
		/** @var TestCase $this */
		$mapper = $this->createMock(CACertificateMapper::class);
		$root = new CACertificate();
		$root->setId('root-new');
		$root->setCertificate(self::ROOT_PEM);
		$root->setCreatedAt(new \DateTime('2026-11-20 11:00:00', new \DateTimeZone('Europe/Amsterdam')));
		$root->setPredecessorSignature('c2lnbmF0dXJl');
		$previous = new CACertificate();
		$previous->setId('root-old');
		$previous->setCertificate(self::PREVIOUS_ROOT_PEM);
		$previous->setSuccessorId('root-new');
		$intermediate = new CACertificate();
		$intermediate->setCertificate(self::INTERMEDIATE_PEM);
		$mapper->method('findRoot')->willReturn($root);
		$mapper->method('findActiveIntermediate')->willReturn($intermediate);
		$mapper->method('findPredecessorOf')->willReturnCallback(
			static fn (string $id): ?CACertificate => ($id === 'root-new') ? $previous : null
		);

		return $mapper;
	}

	/** The announcement renewedCaMapper() publishes. */
	private const RENEWAL = [
		'previousRoot' => self::PREVIOUS_ROOT_PEM,
		'newRoot' => self::ROOT_PEM,
		'renewedAt' => '2026-11-20T10:00:00Z',
		'signature' => 'c2lnbmF0dXJl',
	];

	/** The root renewedCaMapper()'s root replaced, DER "previous-root-der". */
	private const PREVIOUS_ROOT_PEM = "-----BEGIN CERTIFICATE-----\ncHJldmlvdXMtcm9vdC1kZXI=\n-----END CERTIFICATE-----\n";

	/** A real self-signed certificate, since the local root's fingerprint is only taken from exactly one certificate. */
	private const ROOT_PEM = "-----BEGIN CERTIFICATE-----\n"
		. "MIIB4zCCAYmgAwIBAgIBATAKBggqhkjOPQQDAjBgMRkwFwYDVQQDDBBLZWVwaXEg\n"
		. "VGVzdCBSb290MQswCQYDVQQGEwJBVTETMBEGA1UECAwKU29tZS1TdGF0ZTEhMB8G\n"
		. "A1UECgwYSW50ZXJuZXQgV2lkZ2l0cyBQdHkgTHRkMCAXDTI2MTEyNTEwNDcyM1oY\n"
		. "DzIxMjYxMTAxMTA0NzIzWjBgMRkwFwYDVQQDDBBLZWVwaXEgVGVzdCBSb290MQsw\n"
		. "CQYDVQQGEwJBVTETMBEGA1UECAwKU29tZS1TdGF0ZTEhMB8GA1UECgwYSW50ZXJu\n"
		. "ZXQgV2lkZ2l0cyBQdHkgTHRkMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEtsmw\n"
		. "RFJxq/UmJ0aD6p8ypWXOkCnyJM/rK42TZ6oXaFxBnOcp3Q3qfLOkuvBV9956427M\n"
		. "rA08VZjcvQ9irc9dQKMyMDAwHQYDVR0OBBYEFOeouuINcdA/C8SOVO4SaEFh8iOW\n"
		. "MA8GA1UdEwEB/wQFMAMBAf8wCgYIKoZIzj0EAwIDSAAwRQIgTPTIKoxADcydg+IQ\n"
		. "f6Y0UoGZ7J5f3jT6doS4u6056hACIQCqbLhHGR6dmNXMXgFx6/BkR5aNt4rHWN6X\n"
		. "UJTPNwbPAQ==\n"
		. "-----END CERTIFICATE-----\n";

	/** The lowercase hex SHA-256 over ROOT_PEM's DER. */
	private const ROOT_FINGERPRINT = 'a7c33dc3c0a19e25b60422e2b350cc3fc6ef34f434d3bcee718f09ad3a1120f2';

	/** The intermediate, DER "intermediate-der". */
	private const INTERMEDIATE_PEM = "-----BEGIN CERTIFICATE-----\naW50ZXJtZWRpYXRlLWRlcg==\n-----END CERTIFICATE-----\n";
}
