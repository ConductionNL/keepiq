<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins what RotationPolicyService::upsertPolicy() stores and audits.
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Service
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\ExpiryPolicy;
use OCA\Keepiq\Db\ExpiryPolicyMapper;
use OCA\Keepiq\Db\RotationFlagMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\RotationFlagService;
use OCA\Keepiq\Service\RotationPolicyService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Expiry policy upsert outcomes.
 */
class ExpiryPolicyUpsertTest extends TestCase {

	/**
	 * The policy mapper.
	 *
	 * @var ExpiryPolicyMapper&MockObject
	 */
	private ExpiryPolicyMapper $policyMapper;

	/**
	 * The audit events dispatched.
	 *
	 * @var list<AuditEvent>
	 */
	private array $events = [];

	/**
	 * The service under test.
	 *
	 * @var RotationPolicyService
	 */
	private RotationPolicyService $service;

	/**
	 * Wire the service with a recording dispatcher.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->policyMapper = $this->createMock(ExpiryPolicyMapper::class);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			if ($event instanceof AuditEvent) {
				$this->events[] = $event;
			}
		});
		$this->service = new RotationPolicyService(
			policyMapper: $this->policyMapper,
			flagService: new RotationFlagService(
				flagMapper: $this->createMock(RotationFlagMapper::class),
				secretMapper: $this->createMock(SecretMapper::class),
				eventDispatcher: null,
			),
			appConfig: $this->createMock(IAppConfig::class),
			eventDispatcher: $dispatcher,
		);
	}//end setUp()

	/**
	 * A first policy for a scope is inserted with its owner, scope and
	 * normalised reminders, and audited once.
	 *
	 * @return void
	 */
	public function testAFirstPolicyIsInsertedAndAudited(): void {
		$this->policyMapper->method('findByOwner')->willReturn([]);
		$this->policyMapper->expects($this->never())->method('update');
		$this->policyMapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$policy = $this->service->upsertPolicy(userId: 'alice', scope: 'type', scopeId: 't-1', maxAgeDays: 90, reminderDays: ['7', 0, -3, 30]);

		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $policy->getId());
		$this->assertSame(
			['alice', 'type', 't-1', 'alice', 90, '[7,30]'],
			[$policy->getOwnerId(), $policy->getScope(), $policy->getScopeId(), $policy->getCreatedBy(), $policy->getMaxAgeDays(), $policy->getReminderDays()]
		);
		$this->assertNotNull($policy->getCreatedAt());
		$this->assertNotNull($policy->getUpdatedAt());
		$this->assertCount(1, $this->events);
		$this->assertSame(AuditEventTypes::POLICY_EXPIRY_CHANGED, $this->events[0]->getEventType());
		$this->assertSame('alice', $this->events[0]->getActorId());
		$this->assertSame($policy->getId(), $this->events[0]->getObjectId());
		$this->assertSame(['scope' => 'type', 'scopeId' => 't-1'], $this->events[0]->getMetadata());
	}//end testAFirstPolicyIsInsertedAndAudited()

	/**
	 * An existing policy for the same scope is updated in place: its id and
	 * creation stay, its limit and reminders change.
	 *
	 * @return void
	 */
	public function testAnExistingPolicyIsUpdatedInPlace(): void {
		$other = new ExpiryPolicy();
		$other->setId('p-other');
		$other->setScope('type');
		$other->setScopeId('t-2');
		$existing = new ExpiryPolicy();
		$existing->setId('p-1');
		$existing->setScope('type');
		$existing->setScopeId('t-1');
		$existing->setCreatedBy('alice');
		$existing->setCreatedAt(new DateTime('2026-01-01T00:00:00+00:00'));
		$existing->setMaxAgeDays(30);
		$existing->setReminderDays('[1]');
		$this->policyMapper->method('findByOwner')->with('alice')->willReturn([$other, $existing]);
		$this->policyMapper->expects($this->never())->method('insert');
		$this->policyMapper->expects($this->once())->method('update')->with($existing)->willReturnArgument(0);

		$policy = $this->service->upsertPolicy(userId: 'alice', scope: 'type', scopeId: 't-1', maxAgeDays: null);

		$this->assertSame($existing, $policy);
		$this->assertSame(['p-1', null, null], [$policy->getId(), $policy->getMaxAgeDays(), $policy->getReminderDays()]);
		$this->assertSame('2026-01-01T00:00:00+00:00', $policy->getCreatedAt()->format(DATE_ATOM));
		$this->assertSame(['scope' => 'type', 'scopeId' => 't-1'], $this->events[0]->getMetadata());
		$this->assertSame('p-1', $this->events[0]->getObjectId());
	}//end testAnExistingPolicyIsUpdatedInPlace()

	/**
	 * Invalid input is refused before any read, in check order.
	 *
	 * @return void
	 */
	public function testInvalidInputIsRefusedBeforeAnyRead(): void {
		$this->policyMapper->expects($this->never())->method('findByOwner');
		$cases = [
			['vault', '', 0, 'scope must be type or folder'],
			['folder', '', 0, 'scopeId is required'],
			['folder', 'f-1', 0, 'maxAgeDays must be positive or null'],
		];
		foreach ($cases as [$scope, $scopeId, $maxAge, $message]) {
			try {
				$this->service->upsertPolicy(userId: 'alice', scope: $scope, scopeId: $scopeId, maxAgeDays: $maxAge);
				$this->fail('accepted ' . $scope);
			} catch (InvalidArgumentException $exception) {
				$this->assertSame($message, $exception->getMessage());
			}
		}

		$this->assertSame([], $this->events);
	}//end testInvalidInputIsRefusedBeforeAnyRead()
}//end class
