<?php

/**
 * LeasePolicyService: the lease policy an application gets, from the
 * instance settings and its own override, and the floor on every TTL.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Keepiq\Db\ApplicationLeasePolicy;
use OCA\Keepiq\Db\ApplicationLeasePolicyMapper;
use OCA\Keepiq\Service\LeasePolicyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The lease policy.
 */
class LeasePolicyServiceTest extends TestCase {
	/**
	 * A service over the given instance settings and application override.
	 *
	 * @param array<string, int|bool> $settings The instance settings that differ from the defaults.
	 * @param ApplicationLeasePolicy|null $override The application's override, or none.
	 * @param ApplicationLeasePolicyMapper|null $mapper A mapper to use instead.
	 *
	 * @return LeasePolicyService
	 */
	private function service(
		array $settings=[],
		?ApplicationLeasePolicy $override=null,
		?ApplicationLeasePolicyMapper $mapper=null
	): LeasePolicyService {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default): int => (int)($settings[$key] ?? $default)
		);
		$config->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default): bool => (bool)($settings[$key] ?? $default)
		);
		if ($mapper === null) {
			$mapper = $this->createMock(originalClassName: ApplicationLeasePolicyMapper::class);
			$found  = $mapper->method('findByApplication');
			if ($override === null) {
				$found->willThrowException(new DoesNotExistException('none'));
			} else {
				$found->willReturn($override);
			}
		}

		return new LeasePolicyService(policyMapper: $mapper, appConfig: $config);
	}//end service()

	/**
	 * An override with the given values.
	 *
	 * @param int|null $default The default TTL.
	 * @param int|null $max The maximum TTL.
	 * @param bool|null $renewable Whether leases renew.
	 *
	 * @return ApplicationLeasePolicy
	 */
	private function override(?int $default, ?int $max, ?bool $renewable): ApplicationLeasePolicy {
		$policy = new ApplicationLeasePolicy();
		$policy->setDefaultTtlSeconds($default);
		$policy->setMaxTtlSeconds($max);
		$policy->setRenewable($renewable);
		return $policy;
	}//end override()

	/**
	 * Without settings or an override the defaults hold: 15 minutes, a day, renewable.
	 *
	 * @return void
	 */
	public function testTheDefaultsHold(): void {
		$this->assertSame(
			expected: ['defaultTtl' => 900, 'maxTtl' => 86400, 'renewable' => true, 'blockOnRevoke' => false],
			actual: $this->service()->effectivePolicy(applicationId: 'app-1')
		);
		$this->assertSame(expected: ['defaultTtl' => 900, 'maxTtl' => 86400, 'renewable' => true], actual: $this->service()->instancePolicy());
	}//end testTheDefaultsHold()

	/**
	 * An override replaces only the values it sets.
	 *
	 * @return void
	 */
	public function testAnOverrideReplacesWhatItSets(): void {
		$service = $this->service(
			settings: ['lease_default_ttl_seconds' => 600, 'lease_revocation_blocks_refetch' => true],
			override: $this->override(default: null, max: 3600, renewable: false)
		);
		$this->assertSame(
			expected: ['defaultTtl' => 600, 'maxTtl' => 3600, 'renewable' => false, 'blockOnRevoke' => true],
			actual: $service->effectivePolicy(applicationId: 'app-1')
		);
		$this->assertSame(expected: ['defaultTtl' => null, 'maxTtl' => 3600, 'renewable' => false], actual: $service->overrideFor(applicationId: 'app-1'));
	}//end testAnOverrideReplacesWhatItSets()

	/**
	 * Without an override the override reads as all null.
	 *
	 * @return void
	 */
	public function testNoOverrideReadsAsNull(): void {
		$override = $this->service()->overrideFor(applicationId: 'app-1');
		$this->assertSame(expected: ['defaultTtl' => null, 'maxTtl' => null, 'renewable' => null], actual: $override);
	}//end testNoOverrideReadsAsNull()

	/**
	 * No TTL is ever below a minute, however low a setting or override is.
	 *
	 * @return void
	 */
	public function testNoTtlIsBelowAMinute(): void {
		$low = $this->service(settings: ['lease_default_ttl_seconds' => 5, 'lease_max_ttl_seconds' => 0]);
		$this->assertSame(expected: [60, 60], actual: array_values(array_slice($low->instancePolicy(), 0, 2)));
		$policy = $this->service(override: $this->override(default: 10, max: 30, renewable: null))->effectivePolicy(applicationId: 'app-1');
		$this->assertSame(expected: [60, 60, true], actual: [$policy['defaultTtl'], $policy['maxTtl'], $policy['renewable']]);
	}//end testNoTtlIsBelowAMinute()

	/**
	 * An override below a minute is refused before anything is stored.
	 *
	 * @return void
	 */
	public function testAnOverrideBelowAMinuteIsRefused(): void {
		$mapper = $this->createMock(originalClassName: ApplicationLeasePolicyMapper::class);
		$mapper->expects($this->never())->method('upsert');
		$service = $this->service(mapper: $mapper);
		foreach ([[59, null], [null, 59], [0, 3600]] as [$default, $max]) {
			try {
				$service->setPolicyOverride(applicationId: 'app-1', defaultTtl: $default, maxTtl: $max, renewable: null);
				$this->fail(message: 'A TTL below a minute was stored');
			} catch (InvalidArgumentException $e) {
				$this->assertSame(expected: 'Lease TTLs must be at least 60 seconds', actual: $e->getMessage());
			}
		}
	}//end testAnOverrideBelowAMinuteIsRefused()

	/**
	 * A valid override is stored as given, nulls included.
	 *
	 * @return void
	 */
	public function testAValidOverrideIsStored(): void {
		$mapper = $this->createMock(originalClassName: ApplicationLeasePolicyMapper::class);
		$mapper->expects($this->once())->method('upsert')->with('app-1', 60, null, true);
		$this->service(mapper: $mapper)->setPolicyOverride(applicationId: 'app-1', defaultTtl: 60, maxTtl: null, renewable: true);
	}//end testAValidOverrideIsStored()
}//end class
