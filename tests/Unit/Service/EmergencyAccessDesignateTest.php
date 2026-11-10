<?php

/**
 * EmergencyAccessService::designate, pinned: refusals, stored fields, log and audit.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\EmergencyContact;
use OCA\Keepiq\Db\EmergencyContactMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Service\EmergencyAccessAuditTrail;
use OCA\Keepiq\Service\EmergencyAccessService;
use OCA\Keepiq\Service\NotificationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every refusal and write of designating an emergency contact.
 */
class EmergencyAccessDesignateTest extends TestCase {

	/**
	 * The calls recorded so far.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * The owners that have an active suite.
	 *
	 * @var string[]
	 */
	private array $withSuite = ['alice', 'bob'];

	/**
	 * The contact already on record, if any.
	 *
	 * @var EmergencyContact|null
	 */
	private ?EmergencyContact $existing = null;

	/**
	 * The service over recording collaborators.
	 *
	 * @return EmergencyAccessService
	 */
	private function service(): EmergencyAccessService {
		$suites = $this->createMock(EncryptionSuiteMapper::class);
		$suites->method('findActiveByOwner')->willReturnCallback(function (string $ownerType, string $ownerId): EncryptionSuite {
			$this->calls[] = ['activeSuite', $ownerType, $ownerId];
			if (in_array($ownerId, $this->withSuite, true) === false) {
				throw new DoesNotExistException('');
			}

			$suite = new EncryptionSuite();
			$suite->setId('suite-' . $ownerId);
			$suite->setCertificate('CERT');
			return $suite;
		});

		$contacts = $this->createMock(EmergencyContactMapper::class);
		$contacts->method('findByGrantorAndGrantee')->willReturnCallback(function (string $grantorUserId, string $granteeUserId): EmergencyContact {
			$this->calls[] = ['findContact', $grantorUserId, $granteeUserId];
			if ($this->existing === null) {
				throw new DoesNotExistException('');
			}

			return $this->existing;
		});
		foreach (['insert', 'update'] as $method) {
			$contacts->method($method)->willReturnCallback(function (EmergencyContact $contact) use ($method): EmergencyContact {
				$this->calls[] = [$method, $this->fields(contact: $contact)];
				return $contact;
			});
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(function (string $message): void {
			$this->calls[] = ['debug', $message];
		});

		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(function (AuditEvent $event): void {
			$this->calls[] = ['audit', $event->getActorId(), $event->getEventType(), $event->getObjectId(), $event->getMetadata()];
		});

		return new EmergencyAccessService(
			mapper: $contacts,
			suiteMapper: $suites,
			notificationService: $this->createMock(NotificationService::class),
			auditTrail: new EmergencyAccessAuditTrail(eventDispatcher: $events),
			logger: $logger,
		);
	}//end service()

	/**
	 * The stored fields of a contact.
	 *
	 * @param EmergencyContact $contact The contact
	 *
	 * @return array<string,mixed>
	 */
	private function fields(EmergencyContact $contact): array {
		return [
			'id' => strlen((string)$contact->getId()),
			'grantor' => $contact->getGrantorUserId(),
			'grantee' => $contact->getGranteeUserId(),
			'createdAt' => $contact->getCreatedAt() !== null,
			'accessLevel' => $contact->getAccessLevel(),
			'waitPeriodDays' => $contact->getWaitPeriodDays(),
			'state' => $contact->getState(),
			'requestedAt' => $contact->getRequestedAt(),
			'envelope' => $contact->getRecoveryEnvelope(),
			'grantorSuite' => $contact->getGrantorSuiteId(),
			'granteeSuite' => $contact->getGranteeSuiteId(),
			'invalidatedReason' => $contact->getInvalidatedReason(),
			'sameStamp' => $contact->getUpdatedAt() !== null && ($contact->getCreatedAt() === null || $contact->getCreatedAt() === $contact->getUpdatedAt() || $contact->getId() === 'rel-1'),
		];
	}//end fields()

	/**
	 * Designate and answer the trace.
	 *
	 * @param array<int,mixed> $args grantor, grantee, wait days, level, envelope
	 *
	 * @return array<int,mixed>
	 */
	private function designate(array $args): array {
		$this->calls = [];
		try {
			$this->service()->designate(...$args);
			$this->calls[] = ['returned'];
		} catch (Throwable $e) {
			$this->calls[] = ['threw', get_class($e), $e->getMessage()];
		}

		return $this->calls;
	}//end designate()

	/**
	 * Each validation refusal, in check order, before any lookup.
	 *
	 * @return void
	 */
	public function testRefusalsComeFirstAndInOrder(): void {
		$cases = [
			[['alice', 'alice', 5, 'takeover', ''], 'v1 emergency access supports the "view" access level only'],
			[['alice', 'alice', 5, 'view', ''], 'Unsupported wait period'],
			[['alice', 'alice', 7, 'view', ''], 'Cannot designate yourself as an emergency contact'],
			[['alice', '', 7, 'view', ''], 'Cannot designate yourself as an emergency contact'],
			[['alice', 'bob', 7, 'view', "  \n"], 'A recovery envelope is required'],
		];
		foreach ($cases as [$args, $message]) {
			$this->assertSame([['threw', InvalidArgumentException::class, $message]], $this->designate(args: $args));
		}
	}//end testRefusalsComeFirstAndInOrder()

	/**
	 * Without an active suite on either side the designation is refused.
	 *
	 * @return void
	 */
	public function testMissingSuitesAreRefused(): void {
		$this->withSuite = ['alice'];
		$this->assertSame(
			[['activeSuite', 'user', 'bob'], ['threw', InvalidArgumentException::class, 'The grantee has no active encryption suite']],
			$this->designate(args: ['alice', 'bob', 7, 'view', 'ENV'])
		);

		$this->withSuite = ['bob'];
		$this->assertSame(
			[['activeSuite', 'user', 'bob'], ['activeSuite', 'user', 'alice'], ['threw', InvalidArgumentException::class, 'You have no active encryption suite']],
			$this->designate(args: ['alice', 'bob', 7, 'view', 'ENV'])
		);
	}//end testMissingSuitesAreRefused()

	/**
	 * A new contact is inserted, logged and audited.
	 *
	 * @return void
	 */
	public function testNewContactIsInserted(): void {
		$calls = $this->designate(args: ['alice', 'bob', 30, 'view', 'ENV']);

		$this->assertSame(['activeSuite', 'user', 'bob'], $calls[0]);
		$this->assertSame(['activeSuite', 'user', 'alice'], $calls[1]);
		$this->assertSame(['findContact', 'alice', 'bob'], $calls[2]);
		$this->assertSame(
			['insert', ['id' => 36, 'grantor' => 'alice', 'grantee' => 'bob', 'createdAt' => true, 'accessLevel' => 'view', 'waitPeriodDays' => 30, 'state' => EmergencyContact::STATE_GRANTED, 'requestedAt' => null, 'envelope' => 'ENV', 'grantorSuite' => 'suite-alice', 'granteeSuite' => 'suite-bob', 'invalidatedReason' => null, 'sameStamp' => true]],
			$calls[3]
		);
		$this->assertSame(['debug', 'Keepiq: emergency contact designated for grantee bob'], $calls[4]);
		$this->assertSame('alice', $calls[5][1]);
		$this->assertSame(['returned'], $calls[6]);
		$this->assertCount(7, $calls);
	}//end testNewContactIsInserted()

	/**
	 * An existing contact is re-granted in place and updated.
	 *
	 * @return void
	 */
	public function testExistingContactIsRegranted(): void {
		$contact = new EmergencyContact();
		$contact->setId('rel-1');
		$contact->setGrantorUserId('alice');
		$contact->setGranteeUserId('bob');
		$contact->setState(EmergencyContact::STATE_REQUESTED);
		$contact->setRequestedAt(new DateTime('-1 day'));
		$contact->setInvalidatedReason('suite_rotated');
		$this->existing = $contact;

		$calls = $this->designate(args: ['alice', 'bob', 1, 'view', 'ENV2']);

		$this->assertSame(
			['update', ['id' => 5, 'grantor' => 'alice', 'grantee' => 'bob', 'createdAt' => false, 'accessLevel' => 'view', 'waitPeriodDays' => 1, 'state' => EmergencyContact::STATE_GRANTED, 'requestedAt' => null, 'envelope' => 'ENV2', 'grantorSuite' => 'suite-alice', 'granteeSuite' => 'suite-bob', 'invalidatedReason' => null, 'sameStamp' => true]],
			$calls[3]
		);
		$this->assertSame('rel-1', $calls[5][3]);
	}//end testExistingContactIsRegranted()
}//end class
