<?php

/**
 * Unit tests for how AuditListener reads a vault event that carries no actor or payload, or the wrong types.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Listener;

use OCA\Keepiq\Db\AuditEntry;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Listener\AuditListener;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Tests\Support\VaultEventShapes\AccountDataDeletedEvent;
use OCA\Keepiq\Tests\Support\VaultEventShapes\GdprExportPerformedEvent;
use OCA\Keepiq\Tests\Support\VaultEventShapes\SecretExportedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The actor and payload fall back in order, and to nothing.
 *
 * @covers \OCA\Keepiq\Listener\AuditListener
 * @uses   \OCA\Keepiq\Event\Audit\AuditEvent
 * @uses   \OCA\Keepiq\Event\Audit\AuditEventFactory
 * @uses   \OCA\Keepiq\Db\AuditEntry
 */
class AuditListenerEventShapesTest extends TestCase {

	/**
	 * The events recorded, in order.
	 *
	 * @var list<AuditEvent>
	 */
	private array $recorded = [];

	/**
	 * A listener over an audit service that keeps what it records.
	 *
	 * @param int $anonymised How often anonymizeUser() must be called.
	 *
	 * @return AuditListener
	 */
	private function listener(int $anonymised = 0): AuditListener {
		$audit = $this->createMock(AuditService::class);
		$audit->method('record')->willReturnCallback(
			function (AuditEvent $event): AuditEntry {
				$this->recorded[] = $event;

				return new AuditEntry();
			}
		);
		$audit->expects(self::exactly($anonymised))->method('anonymizeUser');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('error');

		return new AuditListener($audit, $logger);
	}//end listener()

	/**
	 * An export event without an actor is recorded under the fallback actor
	 * with no object and an empty payload.
	 *
	 * @return void
	 */
	public function testAnExportWithoutActorOrPayloadIsRecordedWithTheFallbacks(): void {
		$this->listener()->handle(new SecretExportedEvent());

		self::assertCount(1, $this->recorded);
		$event = $this->recorded[0];
		self::assertSame(
			[AuditEventTypes::VAULT_ACCOUNT_DELETED, AuditEventTypes::VAULT_EXPORTED, 'vault', null, []],
			[$event->getActorId(), $event->getEventType(), $event->getObjectType(), $event->getObjectId(), $event->getMetadata()]
		);
	}//end testAnExportWithoutActorOrPayloadIsRecordedWithTheFallbacks()

	/**
	 * A non-string actor and a non-array payload are passed over for the next
	 * accessor that answers in the right type.
	 *
	 * @return void
	 */
	public function testWrongTypesArePassedOverForTheNextAccessor(): void {
		$this->listener()->handle(new GdprExportPerformedEvent());

		$event = $this->recorded[0];
		self::assertSame(
			['svc-export', AuditEventTypes::VAULT_GDPR_EXPORTED, 'svc-export', ['scope' => 'all']],
			[$event->getActorId(), $event->getEventType(), $event->getObjectId(), $event->getMetadata()]
		);
	}//end testWrongTypesArePassedOverForTheNextAccessor()

	/**
	 * An account deletion without an actor is recorded and anonymises nobody.
	 *
	 * @return void
	 */
	public function testADeletionWithoutActorAnonymisesNobody(): void {
		$this->listener(anonymised: 0)->handle(new AccountDataDeletedEvent());

		self::assertSame([AuditEventTypes::VAULT_ACCOUNT_DELETED], array_map(static fn (AuditEvent $event): string => $event->getEventType(), $this->recorded));
	}//end testADeletionWithoutActorAnonymisesNobody()
}//end class
