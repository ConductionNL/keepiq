<?php

/**
 * Unit tests for EncryptionSuiteRevokedListener.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Listener
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

namespace OCA\Keepiq\Tests\Unit\Listener;

use OCA\Keepiq\Db\SecretDelegation;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Event\EncryptionSuiteRevokedEvent;
use OCA\Keepiq\Listener\EncryptionSuiteRevokedListener;
use OCA\Keepiq\Service\DelegationAuthorizer;
use OCA\Keepiq\Service\DelegationService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for EncryptionSuiteRevokedListener.
 */
class EncryptionSuiteRevokedListenerTest extends TestCase {
	/**
	 * Test the listener sweeps share targets and promotes delegations
	 * for a user-owned suite.
	 *
	 * @return void
	 */
	public function testHandleSweepsAndPromotesForUserSuite(): void {
		$mapper = $this->createMock(ShareTargetMapper::class);
		$service = $this->createMock(DelegationService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$listener = new EncryptionSuiteRevokedListener(
			shareTargetMapper: $mapper,
			delegationService: $service,
			logger: $logger
		);

		$event = new EncryptionSuiteRevokedEvent(
			suiteId: 'suite-1',
			ownerType: 'user',
			ownerId: 'alice',
			revokedBy: 'admin'
		);

		$mapper->expects($this->once())
			->method('deleteByTargetUserAndSuite')
			->with('alice', 'suite-1');
		$service->expects($this->once())
			->method('makePermanent')
			->with('alice')
			->willReturn(2);

		$listener->handle($event);
	}//end testHandleSweepsAndPromotesForUserSuite()

	/**
	 * Test the listener skips application suites.
	 *
	 * @return void
	 */
	public function testHandleSkipsApplicationSuites(): void {
		$mapper = $this->createMock(ShareTargetMapper::class);
		$service = $this->createMock(DelegationService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$listener = new EncryptionSuiteRevokedListener(
			shareTargetMapper: $mapper,
			delegationService: $service,
			logger: $logger
		);

		$event = new EncryptionSuiteRevokedEvent(
			suiteId: 'suite-1',
			ownerType: 'application',
			ownerId: 'app-1',
			revokedBy: 'admin'
		);

		$mapper->expects($this->never())->method('deleteByTargetUserAndSuite');
		$service->expects($this->never())->method('makePermanent');

		$listener->handle($event);
	}//end testHandleSkipsApplicationSuites()

	/**
	 * Test the listener no-ops on unrelated events.
	 *
	 * @return void
	 */
	public function testHandleIgnoresUnrelatedEvents(): void {
		$mapper = $this->createMock(ShareTargetMapper::class);
		$service = $this->createMock(DelegationService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$listener = new EncryptionSuiteRevokedListener(
			shareTargetMapper: $mapper,
			delegationService: $service,
			logger: $logger
		);

		$mapper->expects($this->never())->method('deleteByTargetUserAndSuite');

		$listener->handle($this->createMock(Event::class));
	}//end testHandleIgnoresUnrelatedEvents()

	/**
	 * A compromise force-revoke deletes the revoked user's temporary
	 * delegations instead of promoting them (keepiq#817). Runs the REAL
	 * DelegationService against the mapper, so the old code (which called
	 * makePermanentByOriginalOwner on every revoke) fails here.
	 *
	 * @return void
	 */
	public function testCompromiseRevokeRevokesTemporaryDelegationsInsteadOfPromoting(): void {
		$shareTargets = $this->createMock(ShareTargetMapper::class);
		$delegations = $this->createMock(SecretDelegationMapper::class);
		$service = new DelegationService(
			mapper: $delegations,
			authorizer: new DelegationAuthorizer(secretMapper: $this->createMock(SecretMapper::class)),
		);
		$listener = new EncryptionSuiteRevokedListener(
			shareTargetMapper: $shareTargets,
			delegationService: $service,
			logger: $this->createMock(LoggerInterface::class)
		);

		$first = new SecretDelegation();
		$first->setSecretId('sec-1');
		$first->setDelegatedTo('mallory');
		$second = new SecretDelegation();
		$second->setSecretId('sec-2');
		$second->setDelegatedTo('bob');

		$delegations->expects($this->once())
			->method('findTemporaryByOriginalOwner')
			->with('alice')
			->willReturn([$first, $second]);
		$deleted = [];
		$delegations->expects($this->exactly(2))
			->method('delete')
			->willReturnCallback(function (SecretDelegation $entity) use (&$deleted): SecretDelegation {
				$deleted[] = $entity->getDelegatedTo();
				return $entity;
			});
		$delegations->expects($this->never())->method('makePermanentByOriginalOwner');
		$shareTargets->expects($this->once())
			->method('deleteByTargetUserAndSuite')
			->with('alice', 'suite-1');

		$listener->handle(
			new EncryptionSuiteRevokedEvent(
				suiteId: 'suite-1',
				ownerType: 'user',
				ownerId: 'alice',
				revokedBy: 'admin',
				compromised: true
			)
		);

		$this->assertSame(['mallory', 'bob'], $deleted);
	}//end testCompromiseRevokeRevokesTemporaryDelegationsInsteadOfPromoting()

	/**
	 * A revoke that is not marked compromised still promotes (unchanged).
	 *
	 * @return void
	 */
	public function testNonCompromiseRevokeStillPromotes(): void {
		$service = $this->createMock(DelegationService::class);
		$listener = new EncryptionSuiteRevokedListener(
			shareTargetMapper: $this->createMock(ShareTargetMapper::class),
			delegationService: $service,
			logger: $this->createMock(LoggerInterface::class)
		);

		$service->expects($this->once())->method('makePermanent')->with('alice')->willReturn(1);
		$service->expects($this->never())->method('revokeTemporary');

		$listener->handle(
			new EncryptionSuiteRevokedEvent(
				suiteId: 'suite-1',
				ownerType: 'user',
				ownerId: 'alice',
				revokedBy: 'admin',
				compromised: false
			)
		);
	}//end testNonCompromiseRevokeStillPromotes()
}//end class
