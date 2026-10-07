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
use OCP\AppFramework\Db\DoesNotExistException;
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

	/** A syntactically valid PEM; its DER is the bytes "root-der". */
	private const ROOT_PEM = "-----BEGIN CERTIFICATE-----\ncm9vdC1kZXI=\n-----END CERTIFICATE-----\n";

	/** The intermediate, DER "intermediate-der". */
	private const INTERMEDIATE_PEM = "-----BEGIN CERTIFICATE-----\naW50ZXJtZWRpYXRlLWRlcg==\n-----END CERTIFICATE-----\n";
}
