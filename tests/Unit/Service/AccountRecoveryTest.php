<?php

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use OCA\Keepiq\Controller\RecoveryOfficerController;
use OCA\Keepiq\Controller\RecoveryUserController;
use OCA\Keepiq\Db\CACertificateMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\RecoveryApproval;
use OCA\Keepiq\Db\RecoveryApprovalMapper;
use OCA\Keepiq\Db\RecoveryEnrolment;
use OCA\Keepiq\Db\RecoveryEnrolmentMapper;
use OCA\Keepiq\Db\RecoveryKey;
use OCA\Keepiq\Db\RecoveryKeyMapper;
use OCA\Keepiq\Db\RecoveryOfficer;
use OCA\Keepiq\Db\RecoveryOfficerMapper;
use OCA\Keepiq\Db\RecoveryRequest;
use OCA\Keepiq\Db\RecoveryRequestMapper;
use OCA\Keepiq\Event\EncryptionSuiteRevokedEvent;
use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;
use OCA\Keepiq\Listener\RecoverySuiteListener;
use OCA\Keepiq\Service\CertificateIssuanceService;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RecoveryAudit;
use OCA\Keepiq\Service\RecoveryEnrolmentService;
use OCA\Keepiq\Service\RecoveryKeyService;
use OCA\Keepiq\Service\RecoveryPolicyService;
use OCA\Keepiq\Service\RecoveryRequestService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Organisation account recovery, end to end over in-memory tables
 * (crypto-organisation-account-recovery tasks 1.2 to 4.5, 5.1, 5.2).
 * Officers olga and omar, threshold 2; bob is an ordinary user; mallory
 * holds nothing.
 *
 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
 */
class AccountRecoveryTest extends TestCase {

	/** @var array<string,array<string,object>> In-memory tables. */
	private array $rows = [];

	/** @var array<string,string> App config. */
	private array $config = [];

	/** @var array<int,object> Audit events. */
	private array $audits = [];

	private RecoveryPolicyService $policy;

	private RecoveryKeyService $keys;

	private RecoveryEnrolmentService $enrolments;

	private RecoveryRequestService $requests;

	protected function setUp(): void {
		$this->config = [];
		$this->rows = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn ($app, $key, $default = '') => $this->config[$key] ?? $default);
		$appConfig->method('getValueInt')->willReturnCallback(fn ($app, $key, $default = 0) => (int)($this->config[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(function ($app, $key, $value) {
			$this->config[$key] = $value;
			return true;
		});
		$appConfig->method('setValueInt')->willReturnCallback(function ($app, $key, $value) {
			$this->config[$key] = (string)$value;
			return true;
		});

		$suites = $this->createMock(EncryptionSuiteMapper::class);
		$suites->method('findActiveByOwner')->willReturnCallback(
			static function (string $type, string $uid): EncryptionSuite {
				if ($uid === 'nosuite') {
					throw new DoesNotExistException('');
				}
				$suite = new EncryptionSuite();
				$suite->setId('suite-' . $uid);
				return $suite;
			}
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function ($event): void {
			$this->audits[] = $event;
		});
		$audit = new RecoveryAudit($dispatcher);
		$notifications = $this->createMock(NotificationService::class);

		$officerMapper = $this->store(RecoveryOfficerMapper::class, 'officers', [
			'findByKey' => fn ($keyId) => $this->where('officers', 'getRecoveryKeyId', $keyId),
			'findForKeyAndOfficer' => fn ($keyId, $uid) => $this->one(array_filter(
				$this->where('officers', 'getRecoveryKeyId', $keyId),
				static fn ($r) => $r->getOfficerUid() === $uid
			)),
			'findByOfficer' => fn ($uid) => $this->where('officers', 'getOfficerUid', $uid),
			'deleteByOfficer' => function ($uid): void {
				foreach ($this->where('officers', 'getOfficerUid', $uid) as $row) {
					unset($this->rows['officers'][$row->getId()]);
				}
			},
		]);
		$keyMapper = $this->store(RecoveryKeyMapper::class, 'keys', [
			'findByStatus' => fn ($status) => $this->where('keys', 'getStatus', $status),
		]);
		$enrolmentMapper = $this->store(RecoveryEnrolmentMapper::class, 'enrolments', [
			'findByUser' => fn ($uid) => $this->where('enrolments', 'getUserId', $uid),
			'findBySuite' => fn ($suiteId) => $this->where('enrolments', 'getSuiteId', $suiteId),
			'findByKey' => fn ($keyId) => $this->where('enrolments', 'getRecoveryKeyId', $keyId),
		]);
		$requestMapper = $this->store(RecoveryRequestMapper::class, 'requests', [
			'findByStatuses' => fn ($statuses) => array_values(array_filter(
				$this->rows['requests'] ?? [],
				static fn ($r) => in_array($r->getStatus(), $statuses, true)
			)),
			'findByUser' => fn ($uid) => array_reverse($this->where('requests', 'getUserId', $uid)),
			'findBySuite' => fn ($suiteId) => $this->where('requests', 'getSuiteId', $suiteId),
			'findLapsed' => fn ($statuses, $now) => array_values(array_filter(
				$this->rows['requests'] ?? [],
				static fn ($r) => in_array($r->getStatus(), $statuses, true) && $r->getExpiresAt() <= $now
			)),
		]);
		$approvalMapper = $this->store(RecoveryApprovalMapper::class, 'approvals', [
			'findByRequest' => fn ($id) => $this->where('approvals', 'getRequestId', $id),
		]);

		$issuance = $this->createMock(CertificateIssuanceService::class);
		$issuance->method('signPublicKey')->willReturn("-----BEGIN CERTIFICATE-----\nQUJD\n-----END CERTIFICATE-----");

		$this->policy = new RecoveryPolicyService($appConfig, $suites, $officerMapper, $notifications);
		$this->keys = new RecoveryKeyService($keyMapper, $officerMapper, $this->policy, $suites, $issuance, $this->createMock(CACertificateMapper::class), $audit);
		$this->enrolments = new RecoveryEnrolmentService($enrolmentMapper, $this->policy, $this->keys, $suites, $audit);
		$this->requests = new RecoveryRequestService(
			$requestMapper,
			$approvalMapper,
			$enrolmentMapper,
			$this->enrolments,
			$this->keys,
			$this->policy,
			$notifications,
			$audit
		);
	}

	/**
	 * A mapper double backed by $this->rows[$table].
	 *
	 * @param class-string         $class   The mapper class
	 * @param string               $table   The table key
	 * @param array<string,callable> $finders Extra finder callbacks
	 *
	 * @return object
	 */
	private function store(string $class, string $table, array $finders): object {
		$mock = $this->createMock($class);
		$this->rows[$table] = [];
		$mock->method('insert')->willReturnCallback(function ($row) use ($table) {
			$this->rows[$table][$row->getId()] = $row;
			return $row;
		});
		$mock->method('update')->willReturnCallback(function ($row) use ($table) {
			$this->rows[$table][$row->getId()] = $row;
			return $row;
		});
		$mock->method('delete')->willReturnCallback(function ($row) use ($table) {
			unset($this->rows[$table][$row->getId()]);
			return $row;
		});
		$mock->method('findById')->willReturnCallback(
			fn ($id) => $this->rows[$table][$id] ?? throw new DoesNotExistException('')
		);
		foreach ($finders as $name => $callback) {
			$mock->method($name)->willReturnCallback($callback);
		}

		return $mock;
	}

	/**
	 * @return array<int,object>
	 */
	private function where(string $table, string $getter, string $value): array {
		return array_values(array_filter($this->rows[$table] ?? [], static fn ($r) => $r->$getter() === $value));
	}

	/**
	 * @param array<int,object> $rows
	 */
	private function one(array $rows): object {
		return array_values($rows)[0] ?? throw new DoesNotExistException('');
	}

	/**
	 * Officers olga and omar, threshold 2, optional; a key; bob enrolled.
	 *
	 * @return RecoveryRequest Bob's pending request
	 */
	private function bobAsks(): RecoveryRequest {
		$this->policy->update('optional', ['olga', 'omar'], 2);
		$key = $this->keys->createKey('olga', 'PUBLIC', ['olga' => 'WRAPPED-O', 'omar' => 'WRAPPED-M']);
		$this->enrolments->enrol('bob', $key->getId(), json_encode(['v' => 1, 'encKey' => 'K', 'iv' => 'I', 'ct' => 'BOB-ENVELOPE']));
		return $this->requests->create('bob', base64_encode(str_repeat('p', 32)));
	}

	private function session(string $uid): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}

	private function officerController(string $uid): RecoveryOfficerController {
		return new RecoveryOfficerController($this->createMock(IRequest::class), $this->policy, $this->keys, $this->requests, $this->session($uid));
	}

	private function userController(string $uid): RecoveryUserController {
		return new RecoveryUserController($this->createMock(IRequest::class), $this->enrolments, $this->requests, $this->session($uid));
	}

	public function testTheAdministratorSettingsAreChecked(): void {
		foreach ([['optional', ['olga', 'omar'], 3], ['optional', ['olga', 'nosuite'], 1], ['sometimes', ['olga'], 1], ['required', [], 1]] as [$policy, $officers, $threshold]) {
			try {
				$this->policy->update($policy, $officers, $threshold);
				$this->fail('must refuse ' . json_encode([$policy, $officers, $threshold]));
			} catch (\InvalidArgumentException) {
				// Refused.
			}
		}

		$this->assertSame('off', $this->policy->policy());
	}

	public function testOnlyOfficersCreateTheKeyAndEachGetsOnlyTheirOwnCopy(): void {
		$this->policy->update('optional', ['olga', 'omar'], 2);
		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->officerController('mallory')->createKey('PUBLIC', ['olga' => 'A', 'omar' => 'B'])->getStatus()
		);
		$this->assertSame(
			Http::STATUS_BAD_REQUEST,
			$this->officerController('olga')->createKey('PUBLIC', ['olga' => 'A', 'mallory' => 'B'])->getStatus()
		);
		$this->assertSame(
			Http::STATUS_CREATED,
			$this->officerController('olga')->createKey('PUBLIC', ['olga' => 'A', 'omar' => 'B'])->getStatus()
		);

		$this->assertSame('B', $this->officerController('omar')->ownCopy()->getData()['wrappedPrivateKey']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->officerController('mallory')->ownCopy()->getStatus());
		$this->assertSame(['officer' => false], $this->officerController('bob')->overview()->getData());

		// Removing an officer deletes their copy.
		$this->policy->update('optional', ['olga'], 1);
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->officerController('omar')->ownCopy()->getStatus());
	}

	public function testWithdrawalIsRefusedUnderTheRequiredPolicy(): void {
		$this->bobAsks();
		$this->policy->update('required', ['olga', 'omar'], 2);
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->userController('bob')->withdraw()->getStatus());
		$this->assertNotNull($this->enrolments->current('bob'));

		$this->policy->update('optional', ['olga', 'omar'], 2);
		$this->assertSame(Http::STATUS_OK, $this->userController('bob')->withdraw()->getStatus());
		$this->assertNull($this->enrolments->current('bob'));
	}

	public function testANonEnrolledUserCannotFileARequest(): void {
		$this->policy->update('optional', ['olga', 'omar'], 2);
		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->userController('mallory')->createRequest(base64_encode(str_repeat('p', 32)))->getStatus()
		);
	}

	public function testTheThresholdCountsDistinctOfficersAndRefusesSelfApproval(): void {
		$request = $this->bobAsks();
		$id = $request->getId();

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->officerController('mallory')->approve($id)->getStatus());
		$this->assertSame('pending', $this->officerController('olga')->approve($id)->getData()['status']);
		$this->assertSame('pending', $this->officerController('olga')->approve($id)->getData()['status'], 'one officer counts once');
		$this->assertSame('approved', $this->officerController('omar')->approve($id)->getData()['status']);

		// An officer cannot approve their own recovery.
		$this->enrolments->enrol('olga', $this->keys->activeKey()->getId(), json_encode(['encKey' => 'K', 'ct' => 'C']));
		$own = $this->requests->create('olga', base64_encode(str_repeat('q', 32)));
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->officerController('olga')->approve($own->getId())->getStatus());
	}

	public function testHandoffMaterialReachesOnlyItsOwnersAndIsDeletedOnCompletion(): void {
		$id = $this->bobAsks()->getId();
		$this->officerController('olga')->approve($id);

		// Not approved yet: nobody gets the material.
		$notYet = $this->officerController('olga')->handoff($id);
		$this->assertSame(Http::STATUS_NOT_FOUND, $notYet->getStatus());

		$this->officerController('omar')->approve($id);
		$material = $this->officerController('omar')->handoff($id)->getData();
		$this->assertSame('WRAPPED-M', $material['wrappedRecoveryKey'], "omar gets his own copy, not olga's");
		$this->assertStringContainsString('BOB-ENVELOPE', $material['envelope']);

		foreach (['mallory', 'bob'] as $stranger) {
			$response = $this->officerController($stranger)->handoff($id);
			$this->assertContains($response->getStatus(), [Http::STATUS_NOT_FOUND, Http::STATUS_FORBIDDEN]);
			$this->assertArrayNotHasKey('envelope', $response->getData());
		}

		$this->assertSame(Http::STATUS_OK, $this->officerController('omar')->postSealed($id, 'SEALED-FOR-BOB')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->officerController('mallory')->postSealed($id, 'X')->getStatus());

		$this->assertSame('SEALED-FOR-BOB', $this->userController('bob')->myRequest()->getData()['request']['sealedResult']);
		$this->assertNull($this->userController('mallory')->myRequest()->getData()['request']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->userController('mallory')->complete($id)->getStatus());

		$this->assertSame(['handledBy' => 'omar'], $this->userController('bob')->complete($id)->getData());
		$this->assertNull($this->rows['requests'][$id]->getSealedResult());
		$this->assertSame('fulfilled', $this->rows['requests'][$id]->getStatus());

		foreach ($this->audits as $event) {
			$this->assertStringNotContainsString('SEALED', json_encode($event->getMetadata()));
			$this->assertStringNotContainsString('ENVELOPE', json_encode($event->getMetadata()));
			$this->assertStringNotContainsString('WRAPPED', json_encode($event->getMetadata()));
		}
	}

	public function testADeclineEndsTheRequestAndExpiryDropsTheSealedResult(): void {
		$id = $this->bobAsks()->getId();
		$this->officerController('omar')->decline($id);
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->officerController('olga')->approve($id)->getStatus());

		$second = $this->requests->create('bob', base64_encode(str_repeat('r', 32)));
		$second->setStatus('approved');
		$second->setSealedResult('SEALED');
		$second->setExpiresAt(new DateTime('-1 minute'));
		$this->assertSame(1, $this->requests->expireLapsed(new DateTime()));
		$this->assertNull($second->getSealedResult());
		$this->assertSame('expired', $second->getStatus());
	}

	public function testEnrolmentsAndRequestsFollowTheSuite(): void {
		$id = $this->bobAsks()->getId();
		$listener = new RecoverySuiteListener($this->enrolments, $this->requests, $this->createMock(LoggerInterface::class));

		$listener->handle(new SuiteMigrationCompletedEvent('suite-other', 'suite-new', 'm-1'));
		$this->assertNotNull($this->enrolments->current('bob'), 'another suite rotating leaves bob alone');

		$listener->handle(new EncryptionSuiteRevokedEvent('suite-bob', 'user', 'bob', 'admin'));
		$this->assertNull($this->enrolments->current('bob'));
		$this->assertSame('declined', $this->rows['requests'][$id]->getStatus());
	}

	public function testARetiredKeyTakesNoNewEnrolments(): void {
		$this->policy->update('optional', ['olga', 'omar'], 2);
		$key = $this->keys->createKey('olga', 'PUBLIC', ['olga' => 'A', 'omar' => 'B']);
		$this->keys->retire($key->getId(), 'admin');

		$this->expectException(\InvalidArgumentException::class);
		$this->enrolments->enrol('bob', $key->getId(), json_encode(['encKey' => 'K', 'ct' => 'C']));
	}

	public function testANewKeyRetiresTheOldOneAndOldEnrolmentsAreNoLongerCurrent(): void {
		$this->bobAsks();
		$this->assertTrue($this->enrolments->status('bob')['current']);
		$this->keys->createKey('omar', 'PUBLIC-2', ['olga' => 'A2', 'omar' => 'B2']);
		$status = $this->enrolments->status('bob');
		$this->assertTrue($status['enrolled']);
		$this->assertFalse($status['current'], 'bob re-enrols at his next unlock');
	}
}
