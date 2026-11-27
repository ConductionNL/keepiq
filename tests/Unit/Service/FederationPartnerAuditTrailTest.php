<?php

/**
 * Unit tests for FederationPartnerAuditTrail.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Event\Audit\AuditMetadataPolicy;
use OCA\Keepiq\Service\FederationPartnerAuditTrail;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the federation partner audit events.
 *
 * @covers \OCA\Keepiq\Service\FederationPartnerAuditTrail
 * @uses   \OCA\Keepiq\Event\Audit\AuditEvent
 * @uses   \OCA\Keepiq\Event\Audit\AuditEventFactory
 */
class FederationPartnerAuditTrailTest extends TestCase {
	public function testARepinIsActoredByTheAdminWithThePartnerAndBothFingerprints(): void {
		$partner = new FederationPartner();
		$partner->setId('p-1');
		$partner->setHost('cloud.partner.example');
		$partner->setRootFingerprint(str_repeat('cd', 32));

		$captured = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())
			->method('dispatchTyped')
			->willReturnCallback(static function (AuditEvent $event) use (&$captured): void {
				$captured[] = $event;
			});

		(new FederationPartnerAuditTrail($dispatcher))->repinned($partner, str_repeat('ab', 32), 'admin');

		$event = $captured[0];
		$this->assertSame(AuditEventTypes::FEDERATION_PARTNER_REPINNED, $event->getEventType());
		$this->assertSame(AuditEvent::ACTOR_USER, $event->getActorType());
		$this->assertSame('admin', $event->getActorId());
		$this->assertSame('federation_partner', $event->getObjectType());
		$this->assertSame('p-1', $event->getObjectId());
		$this->assertSame('cloud.partner.example', $event->getObjectName());
		$this->assertSame(
			['partnerId' => 'p-1', 'oldFingerprint' => str_repeat('ab', 32), 'newFingerprint' => str_repeat('cd', 32)],
			$event->getMetadata()
		);
		$this->assertSame(
			array_keys($event->getMetadata()),
			AuditMetadataPolicy::WHITELIST[AuditEventTypes::FEDERATION_PARTNER_REPINNED]
		);
	}

	public function testWithoutADispatcherNothingIsRecorded(): void {
		$partner = new FederationPartner();
		$partner->setId('p-1');
		$partner->setHost('cloud.partner.example');
		$partner->setRootFingerprint(str_repeat('cd', 32));

		(new FederationPartnerAuditTrail())->repinned($partner, str_repeat('ab', 32), 'admin');

		$this->addToAssertionCount(1);
	}
}
