<?php

/**
 * The `totp` and `passkeys` value fields over federation (keepiq#1325): an owner's server sends them only to a
 * partner that advertised it can hold them, refuses the share or update otherwise, never serves them to a pull that
 * did not declare them, and the recipient's server puts them on its copy.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Federation
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Federation;

use DateTime;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Controller\FederatedShareController;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FederatedInbound;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\PartnerUpgradeRequiredException;
use OCA\Keepiq\Listener\FederationOcmRequestListener;
use OCA\Keepiq\Service\FederatedCertificateService;
use OCA\Keepiq\Service\FederatedCopyService;
use OCA\Keepiq\Service\FederatedNotificationDelivery;
use OCA\Keepiq\Service\FederatedShareAuditTrail;
use OCA\Keepiq\Service\FederatedShareMessenger;
use OCA\Keepiq\Service\FederatedSharePuller;
use OCA\Keepiq\Service\FederatedShareService;
use OCA\Keepiq\Service\FederatedValueFields;
use OCA\Keepiq\Service\FederationPartnerService;
use OCA\Keepiq\Service\FederationRootService;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCA\Keepiq\Service\ShareSave\ShareSaveRequest;
use OCA\Keepiq\Service\SecretTypeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\OCM\Events\OCMEndpointRequestEvent;
use OCP\OCM\IOCMDiscoveryService;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/FederationFixtures.php';
require_once __DIR__ . '/OcmDoubles.php';

class FederatedValueFieldsTest extends TestCase {
	use FederationFixtures;

	private const SHARE_ID = '6d1f6c1e-0000-4000-8000-000000000001';
	private const SHARED_SECRET = 'the-shared-secret';
	private const FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const NEW_FINGERPRINT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
	private const TOTP = 'CIPHER-TOTP-FOR-BOB';
	private const PASSKEYS = 'CIPHER-PASSKEYS-FOR-BOB';

	/** @var array<string,FederatedShare> What the database holds */
	private array $rows = [];

	/** @var array<int,mixed> OCM shares and notifications sent */
	private array $sent = [];

	/** @var AuditEvent[] */
	private array $audit = [];

	/** @var list<array<string,mixed>> The context of every warning logged */
	private array $warnings = [];

	/** Alice's secret that is shared */
	private Secret $source;

	private FederationPartner $partnerRow;

	/** Whether the partner row was removed */
	private bool $partnerGone = false;

	private FederatedShareService $service;

	protected function setUp(): void {
		$this->skipWithoutFederation();
		$this->partnerRow = $this->partner('cloud.partner.example', true, true);
	}

	/**
	 * Alice's server, whose outbound partner cloud.partner.example advertised `$valueFields`.
	 *
	 * @param string|null $valueFields The partner's recorded `valueFields`
	 *
	 * @return FederatedShareService
	 */
	private function alice(?string $valueFields): FederatedShareService {
		$this->partnerRow->setValueFields($valueFields);

		$mapper = $this->createMock(FederatedShareMapper::class);
		$mapper->method('insert')->willReturnCallback(
			function (FederatedShare $row): FederatedShare {
				$this->rows[$row->getId()] = clone $row;
				return $row;
			}
		);
		$mapper->method('delete')->willReturnCallback(
			function (FederatedShare $row): FederatedShare {
				unset($this->rows[$row->getId()]);
				return $row;
			}
		);
		$mapper->method('findById')->willReturnCallback(
			fn (string $id): FederatedShare => isset($this->rows[$id]) ? clone $this->rows[$id] : throw new DoesNotExistException('none')
		);
		$mapper->method('findBySourceSecret')->willReturnCallback(fn (): array => array_values($this->rows));
		$mapper->method('updateIfActive')->willReturnCallback(
			function (FederatedShare $row): int {
				if (($this->rows[$row->getId()] ?? null)?->getStatus() !== FederatedShare::STATUS_ACTIVE) {
					return 0;
				}
				$this->rows[$row->getId()] = clone $row;
				return 1;
			}
		);
		$mapper->method('updateIfUnchanged')->willReturn(1);

		$source = new Secret();
		$source->setId('src');
		$source->setName('Supplier portal');
		$source->setOwnerType('user');
		$source->setOwnerId('alice');
		$source->setKey('ALICE-OWN-CIPHERTEXT');
		$source->setTotp('ALICE-OWN-TOTP');
		$source->setPasskeys('ALICE-OWN-PASSKEYS');
		$this->source = $source;
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturn($source);

		$factory = $this->createMock(ICloudFederationFactory::class);
		$factory->method('getCloudFederationShare')->willReturnCallback(static fn () => new FakeCloudFederationShare());
		$factory->method('getCloudFederationNotification')->willReturnCallback(static fn () => new FakeCloudFederationNotification());
		$ok = function (...$args): IResponse {
			$this->sent[] = $args;
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn(201);
			return $response;
		};
		$ocm = $this->createMock(ICloudFederationProviderManager::class);
		$ocm->method('sendCloudShare')->willReturnCallback($ok);
		$ocm->method('sendCloudNotification')->willReturnCallback($ok);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (AuditEvent $event): void {
				$this->audit[] = $event;
			}
		);
		$audit = new FederatedShareAuditTrail($dispatcher);
		$messenger = new FederatedShareMessenger($ocm, $factory, $this->cloudIdManager(), $this->createMock(IUserManager::class));
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('s', 64));
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);

		$partners = [$this->partnerRow, $this->partner('cloud.other.example', true, true)];
		$partnerMapper = $this->partnerMapper($partners);
		$partnerMapper->method('findById')->willReturnCallback(
			function (string $id) use ($partners): FederationPartner {
				foreach ($partners as $partner) {
					if ($partner->getId() === $id && $this->partnerGone === false) {
						return $partner;
					}
				}
				throw new DoesNotExistException('none');
			}
		);

		$this->service = new FederatedShareService(
			shareMapper: $mapper,
			secretMapper: $secrets,
			partners: new FederationPartnerService(
				$partnerMapper,
				$this->createMock(IClientService::class),
				$config
			),
			root: new FederationRootService($this->caMapper()),
			cloudIdManager: $this->cloudIdManager(),
			messenger: $messenger,
			random: $random,
			delivery: new FederatedNotificationDelivery($mapper, $messenger, $audit),
			audit: $audit,
			logger: $this->warningLog(),
		);

		return $this->service;
	}

	/**
	 * A logger that keeps the context of every warning in `$warnings`.
	 *
	 * @return LoggerInterface
	 */
	private function warningLog(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = $context;
			}
		);

		return $logger;
	}

	/**
	 * Alice's controller; `$params` is what the request carried.
	 *
	 * @param array<string,mixed> $params The request parameters
	 *
	 * @return FederatedShareController
	 */
	private function controller(array $params = []): FederatedShareController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return new FederatedShareController($request, $this->service, $session);
	}

	/**
	 * A live share to bob, holding `$totp` and `$passkeys`.
	 *
	 * @param string|null $totp The stored TOTP ciphertext
	 * @param string|null $passkeys The stored passkeys ciphertext
	 *
	 * @return void
	 */
	private function existingShare(?string $totp, ?string $passkeys): void {
		$row = new FederatedShare();
		$row->setId(self::SHARE_ID);
		$row->setSourceSecretId('src');
		$row->setOwnerId('alice');
		$row->setRecipientCloudId('bob@cloud.partner.example');
		$row->setPartnerId($this->partnerRow->getId());
		$row->setRecipientCertFingerprint(self::FINGERPRINT);
		$row->setKey('OLD-KEY');
		$row->setTotp($totp);
		$row->setPasskeys($passkeys);
		$row->setSharedSecretHash(hash('sha256', self::SHARED_SECRET));
		$row->setStatus(FederatedShare::STATUS_ACTIVE);
		$row->setNotifyAttempts(0);
		$row->setUpdatedAt(new DateTime('2026-10-01T00:00:00Z'));
		$this->rows[self::SHARE_ID] = $row;
	}

	/**
	 * Alice shares with bob.
	 *
	 * @param string|null $totp The TOTP ciphertext
	 * @param string|null $passkeys The passkeys ciphertext
	 *
	 * @return JSONResponse
	 */
	private function share(?string $totp, ?string $passkeys): JSONResponse {
		return $this->controller()->create(
			'src',
			'bob@cloud.partner.example',
			self::FINGERPRINT,
			'KEY',
			'LOGIN',
			null,
			$totp,
			$passkeys
		);
	}

	/**
	 * The ciphertexts appear in no audit entry.
	 *
	 * @return void
	 */
	private function assertAuditWithoutValues(): void {
		foreach ($this->audit as $event) {
			$entry = json_encode([$event->getObjectId(), $event->getObjectName(), $event->getMetadata()]);
			$this->assertStringNotContainsString(self::TOTP, $entry);
			$this->assertStringNotContainsString(self::PASSKEYS, $entry);
		}
	}

	public function testAShareWithTheNewFieldsToAnOldPartnerIsRefusedAndNothingIsWritten(): void {
		$this->alice(valueFields: null);

		foreach ([[self::TOTP, null], [null, self::PASSKEYS], [self::TOTP, self::PASSKEYS]] as [$totp, $passkeys]) {
			$response = $this->share($totp, $passkeys);

			$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
			$this->assertSame(
				[
					'error' => 'partner_upgrade_required',
					'message' => (new PartnerUpgradeRequiredException())->getMessage(),
					'requiredVersion' => Application::TOTP_PASSKEYS_FIELDS_SINCE,
				],
				$response->getData()
			);
		}

		$this->assertSame([], $this->rows);
		$this->assertSame([], $this->sent);
		$this->assertSame([], $this->audit);
	}

	public function testAPartnerThatAdvertisedOnlyOneFieldIsRefusedTheOther(): void {
		$this->alice(valueFields: '["totp"]');

		$this->assertSame(Http::STATUS_CONFLICT, $this->share(self::TOTP, self::PASSKEYS)->getStatus());
		$this->assertSame([], $this->rows);
		$this->assertSame(Http::STATUS_CREATED, $this->share(self::TOTP, null)->getStatus());
	}

	/**
	 * A new partner share mirrors its source like a direct share: for a partner that stores the fields, leaving out
	 * one the source holds is refused, and so is carrying one the source does not hold. Nothing is written.
	 */
	public function testAShareThatDoesNotMirrorTheSourceIsRefused(): void {
		$this->alice(valueFields: '["totp","passkeys"]');

		$response = $this->share(self::TOTP, null);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'copy_fields_mismatch'], $response->getData());

		$this->source->setTotp(null);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->share(self::TOTP, self::PASSKEYS)->getStatus());

		$this->assertSame([], $this->rows);
		$this->assertSame([], $this->sent);
	}

	public function testAShareWithoutTheNewFieldsToAnOldPartnerWorksAsBefore(): void {
		$this->alice(valueFields: null);

		$response = $this->share(null, null);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$row = array_values($this->rows)[0];
		$this->assertSame('KEY', $row->getKey());
		$this->assertNull($row->getTotp());
		$this->assertNull($row->getPasskeys());
		$this->assertArrayNotHasKey('totp', $response->getData());
	}

	public function testANewPartnerGetsBothFieldsStored(): void {
		$this->alice(valueFields: '["totp","passkeys"]');

		$response = $this->share(self::TOTP, self::PASSKEYS);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$row = array_values($this->rows)[0];
		$this->assertSame(self::TOTP, $row->getTotp());
		$this->assertSame(self::PASSKEYS, $row->getPasskeys());
		$this->assertStringNotContainsString(self::TOTP, json_encode($response->getData()));
		$this->assertStringNotContainsString(self::TOTP, json_encode($this->sent));
		$this->assertAuditWithoutValues();
	}

	public function testAnUpdateWithTheNewFieldsToAnOldPartnerIsRefusedAndNothingIsWritten(): void {
		$this->alice(valueFields: null);
		$this->existingShare(totp: null, passkeys: null);

		$response = $this->controller(['totp' => self::TOTP])
			->update(self::SHARE_ID, self::FINGERPRINT, 'NEW-KEY', null, null, self::TOTP);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('partner_upgrade_required', $response->getData()['error']);
		$this->assertSame(Application::TOTP_PASSKEYS_FIELDS_SINCE, $response->getData()['requiredVersion']);
		$this->assertSame(self::SHARE_ID, $response->getData()['shareId']);
		$this->assertSame('OLD-KEY', $this->rows[self::SHARE_ID]->getKey());
		$this->assertNull($this->rows[self::SHARE_ID]->getTotp());
		$this->assertSame([], $this->sent);
		$this->assertSame([], $this->audit);
	}

	public function testAShareSaveWriteToAnOldPartnerThrowsBeforeWriting(): void {
		$this->alice(valueFields: null);
		$this->existingShare(totp: null, passkeys: null);

		try {
			$this->service->writeCiphertext(
				row: clone $this->rows[self::SHARE_ID],
				certFingerprint: self::FINGERPRINT,
				ciphertext: ['key' => 'NEW-KEY', 'login' => null, 'additionalFields' => null, 'totp' => null, 'passkeys' => self::PASSKEYS],
				savedAt: new DateTime(),
			);
			$this->fail('A partner that cannot hold passkeys was written to');
		} catch (PartnerUpgradeRequiredException $exception) {
			$this->assertSame(Application::TOTP_PASSKEYS_FIELDS_SINCE, $exception->getRequiredVersion());
		}

		$this->assertSame('OLD-KEY', $this->rows[self::SHARE_ID]->getKey());
		$this->assertNull($this->rows[self::SHARE_ID]->getPendingNotification());
		$this->assertSame([], $this->audit);
	}

	public function testAnUpdateFromAnOldClientToAnOldPartnerWorksAsBefore(): void {
		$this->alice(valueFields: null);
		$this->existingShare(totp: null, passkeys: null);

		$response = $this->controller()->update(self::SHARE_ID, self::FINGERPRINT, 'NEW-KEY', 'NEW-LOGIN');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('NEW-KEY', $this->rows[self::SHARE_ID]->getKey());
		$this->assertNull($this->rows[self::SHARE_ID]->getTotp());
	}

	/**
	 * A field the client did not send stays while the certificate is the same, and goes with a new certificate:
	 * nothing stays encrypted for a key the recipient no longer holds. A null that was sent clears it.
	 */
	public function testAnAbsentFieldIsKeptOnlyUnderTheSameCertificate(): void {
		$this->alice(valueFields: '["totp","passkeys"]');
		$this->existingShare(totp: self::TOTP, passkeys: self::PASSKEYS);

		$this->controller()->update(self::SHARE_ID, strtoupper(self::FINGERPRINT), 'NEW-KEY');
		$this->assertSame(self::TOTP, $this->rows[self::SHARE_ID]->getTotp());
		$this->assertSame(self::PASSKEYS, $this->rows[self::SHARE_ID]->getPasskeys());

		$this->controller(['passkeys' => null])->update(self::SHARE_ID, self::FINGERPRINT, 'NEW-KEY', null, null, null, null);
		$this->assertSame(self::TOTP, $this->rows[self::SHARE_ID]->getTotp());
		$this->assertNull($this->rows[self::SHARE_ID]->getPasskeys());

		$this->controller()->update(self::SHARE_ID, self::NEW_FINGERPRINT, 'KEY-FOR-NEW-CERT');
		$this->assertNull($this->rows[self::SHARE_ID]->getTotp());
		$this->assertSame(self::NEW_FINGERPRINT, $this->rows[self::SHARE_ID]->getRecipientCertFingerprint());
	}

	public function testAnUpdateToANewPartnerReplacesBothFields(): void {
		$this->alice(valueFields: '["totp","passkeys"]');
		$this->existingShare(totp: 'OLD-TOTP', passkeys: null);

		$response = $this->controller(['totp' => 'NEW-TOTP', 'passkeys' => self::PASSKEYS])
			->update(self::SHARE_ID, self::NEW_FINGERPRINT, 'NEW-KEY', null, null, 'NEW-TOTP', self::PASSKEYS);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('NEW-TOTP', $this->rows[self::SHARE_ID]->getTotp());
		$this->assertSame(self::PASSKEYS, $this->rows[self::SHARE_ID]->getPasskeys());
		$this->assertAuditWithoutValues();
	}

	/**
	 * A share-save's write of the share, next to a patch of Alice's row.
	 *
	 * @param array<string,mixed> $fields The entry's `totp` and `passkeys`, as sent
	 * @param array<string,mixed> $patch The patch of Alice's row
	 *
	 * @return FederatedShare
	 */
	private function shareSaveWrite(array $fields, array $patch): FederatedShare {
		return $this->service->writeCiphertext(
			row: clone $this->rows[self::SHARE_ID],
			certFingerprint: self::FINGERPRINT,
			ciphertext: ['key' => 'NEW-KEY', 'login' => null, 'additionalFields' => null] + $fields,
			savedAt: new DateTime(),
			patch: ['key' => 'K-ALICE'] + $patch,
		);
	}

	public function testAShareSaveMayLeaveTheNewFieldsOutForAPartnerThatCannotStoreThem(): void {
		$this->alice(valueFields: null);
		$this->existingShare(totp: null, passkeys: null);
		$patch = ['totp' => 'ENCRYPTED-EMPTY', 'passkeys' => 'ENCRYPTED-EMPTY'];

		$this->shareSaveWrite(fields: ['totp' => null, 'passkeys' => null], patch: $patch);
		$this->assertSame('NEW-KEY', $this->rows[self::SHARE_ID]->getKey());

		$this->shareSaveWrite(fields: [], patch: $patch);
		$this->assertNull($this->rows[self::SHARE_ID]->getTotp());
		$this->assertNull($this->rows[self::SHARE_ID]->getPasskeys());
	}

	public function testAShareSaveValueForAPartnerThatCannotStoreItIsRefused(): void {
		$this->alice(valueFields: null);
		$this->existingShare(totp: null, passkeys: null);

		try {
			$this->shareSaveWrite(fields: ['totp' => self::TOTP, 'passkeys' => null], patch: ['totp' => 'T', 'passkeys' => null]);
			$this->fail('A partner that cannot hold totp was sent one');
		} catch (PartnerUpgradeRequiredException $exception) {
			$this->assertSame(Application::TOTP_PASSKEYS_FIELDS_SINCE, $exception->getRequiredVersion());
		}

		$this->assertSame('OLD-KEY', $this->rows[self::SHARE_ID]->getKey());
		$this->assertSame([], $this->audit);
	}

	/**
	 * A partner that stores the fields gets the patch's null-ness: a left-out or null field would keep its old
	 * value or lose the new one.
	 */
	public function testAShareSaveToAPartnerThatStoresTheFieldsMustMirrorThePatch(): void {
		$this->alice(valueFields: '["totp","passkeys"]');
		$this->existingShare(totp: 'OLD-TOTP', passkeys: null);
		$patch = ['totp' => 'T', 'passkeys' => null];

		foreach ([['totp' => null, 'passkeys' => null], ['passkeys' => null], ['totp' => self::TOTP, 'passkeys' => self::PASSKEYS]] as $fields) {
			try {
				$this->shareSaveWrite(fields: $fields, patch: $patch);
				$this->fail('An entry that does not mirror the patch was written');
			} catch (ShareSaveRefusal $refusal) {
				$this->assertSame('copy_fields_mismatch', $refusal->getError());
			}
		}

		$this->assertSame('OLD-KEY', $this->rows[self::SHARE_ID]->getKey());
		$this->assertSame('OLD-TOTP', $this->rows[self::SHARE_ID]->getTotp());
		$this->assertSame([], $this->audit);

		$this->shareSaveWrite(fields: ['totp' => self::TOTP, 'passkeys' => null], patch: $patch);
		$this->assertSame(self::TOTP, $this->rows[self::SHARE_ID]->getTotp());
	}

	/**
	 * A restore of a version without a TOTP seed keeps the head's; the partner share carries the head's seed
	 * encrypted for a new certificate and keeps it, where a left-out field would be cleared.
	 */
	public function testARestoreOfAVersionWithoutTotpKeepsThePartnersUnderANewCertificate(): void {
		$entry = ['key' => 'NEW-KEY', 'login' => null, 'additionalFields' => null, 'totp' => 'HEAD-TOTP-FOR-NEW-CERT'];
		$request = new ShareSaveRequest([
			'baseUpdatedAt' => '2026-10-01T10:00:00+00:00',
			'restoredFrom' => 'v-1',
			'patch' => ['key' => 'K-ALICE', 'login' => null, 'additionalFields' => null, 'totp' => 'HEAD-TOTP'],
			'federated' => [['shareId' => self::SHARE_ID, 'certFingerprint' => self::NEW_FINGERPRINT] + $entry],
		]);
		$version = new SecretVersion();
		$version->setTotp(null);
		$head = new Secret();
		$head->setTotp('HEAD-TOTP');
		[$patch, $entries, $kept] = $request->restoring(version: $version, entries: $request->entries, head: $head);
		$ciphertext = array_intersect_key($entries->federated[0], $entry);
		$write = fn (array $ciphertext): FederatedShare => $this->service->writeCiphertext(
			row: clone $this->rows[self::SHARE_ID],
			certFingerprint: self::NEW_FINGERPRINT,
			ciphertext: $ciphertext,
			savedAt: new DateTime(),
			patch: $patch,
			kept: $kept,
		);

		$this->alice(valueFields: '["totp","passkeys"]');
		$this->existingShare(totp: 'HEAD-TOTP-FOR-OLD-CERT', passkeys: null);
		$write($ciphertext);
		$this->assertSame('HEAD-TOTP-FOR-NEW-CERT', $this->rows[self::SHARE_ID]->getTotp());
		$this->assertSame(self::NEW_FINGERPRINT, $this->rows[self::SHARE_ID]->getRecipientCertFingerprint());

		$this->alice(valueFields: null);
		$this->existingShare(totp: null, passkeys: null);
		try {
			$write($ciphertext);
			$this->fail('A partner that cannot hold totp was sent one');
		} catch (PartnerUpgradeRequiredException) {
			$this->assertSame('OLD-KEY', $this->rows[self::SHARE_ID]->getKey());
		}

		$write(['totp' => null] + $ciphertext);
		$this->assertSame('NEW-KEY', $this->rows[self::SHARE_ID]->getKey());
		$this->assertNull($this->rows[self::SHARE_ID]->getTotp());
	}

	/**
	 * The web app of this release restores with only the legacy fields. A version recorded after this release holds
	 * `''` for a login without a TOTP seed or passkeys; with a head that holds neither, the partner share is written.
	 */
	public function testACurrentClientsRestoreOfAVersionWithEmptyFieldsReachesThePartner(): void {
		$this->alice(valueFields: '["totp","passkeys"]');
		$this->existingShare(totp: null, passkeys: null);
		$version = new SecretVersion();
		$version->setTotp('');
		$version->setPasskeys('');
		$legacy = ['key' => 'NEW-KEY', 'login' => null, 'additionalFields' => null];
		$request = new ShareSaveRequest([
			'baseUpdatedAt' => '2026-10-01T10:00:00+00:00',
			'restoredFrom' => 'v-1',
			'patch' => ['key' => 'K-ALICE', 'login' => null, 'additionalFields' => null],
			'federated' => [['shareId' => self::SHARE_ID, 'certFingerprint' => self::NEW_FINGERPRINT] + $legacy],
		]);

		[$patch, $entries, $kept] = $request->restoring(version: $version, entries: $request->entries, head: new Secret());
		$this->service->writeCiphertext(
			row: clone $this->rows[self::SHARE_ID],
			certFingerprint: self::NEW_FINGERPRINT,
			ciphertext: array_intersect_key($entries->federated[0], array_flip(Secret::VALUE_FIELDS)),
			savedAt: new DateTime(),
			patch: $patch,
			kept: $kept,
		);

		$this->assertArrayNotHasKey('totp', $patch);
		$this->assertSame('NEW-KEY', $this->rows[self::SHARE_ID]->getKey());
		$this->assertNull($this->rows[self::SHARE_ID]->getTotp());
	}

	/**
	 * A restore that clears a TOTP seed the head holds clears the partner's too, without the client sending it.
	 */
	public function testARestoreThatClearsTheHeadsTotpClearsThePartners(): void {
		$this->alice(valueFields: '["totp","passkeys"]');
		$this->existingShare(totp: self::TOTP, passkeys: null);
		$version = new SecretVersion();
		$version->setTotp('');
		$head = new Secret();
		$head->setTotp('HEAD-TOTP');
		$legacy = ['key' => 'NEW-KEY', 'login' => null, 'additionalFields' => null];
		$request = new ShareSaveRequest([
			'baseUpdatedAt' => '2026-10-01T10:00:00+00:00',
			'restoredFrom' => 'v-1',
			'patch' => ['key' => 'K-ALICE', 'login' => null, 'additionalFields' => null],
			'federated' => [['shareId' => self::SHARE_ID, 'certFingerprint' => self::NEW_FINGERPRINT] + $legacy],
		]);

		[$patch, $entries, $kept] = $request->restoring(version: $version, entries: $request->entries, head: $head);
		$this->service->writeCiphertext(
			row: clone $this->rows[self::SHARE_ID],
			certFingerprint: self::NEW_FINGERPRINT,
			ciphertext: array_intersect_key($entries->federated[0], array_flip(Secret::VALUE_FIELDS)),
			savedAt: new DateTime(),
			patch: $patch,
			kept: $kept,
		);

		$this->assertSame('', $patch['totp']);
		$this->assertNull($this->rows[self::SHARE_ID]->getTotp());
		$this->assertSame('NEW-KEY', $this->rows[self::SHARE_ID]->getKey());
	}

	/**
	 * In a restore of a version without a TOTP seed, the head keeps its seed, so a partner that stores the field
	 * may not have its seed cleared or a seed set the head does not hold; leaving the field out stays allowed.
	 */
	public function testARestoreCannotChangeAPartnersTotpTheHeadKeeps(): void {
		$this->alice(valueFields: '["totp","passkeys"]');
		$version = new SecretVersion();
		$version->setTotp(null);
		$write = function (?string $headTotp, array $entry) use ($version): void {
			$request = new ShareSaveRequest([
				'baseUpdatedAt' => '2026-10-01T10:00:00+00:00',
				'restoredFrom' => 'v-1',
				'patch' => ['key' => 'K-ALICE', 'login' => null, 'additionalFields' => null],
				'federated' => [['shareId' => self::SHARE_ID, 'certFingerprint' => self::NEW_FINGERPRINT] + $entry],
			]);
			$head = new Secret();
			$head->setTotp($headTotp);
			[$patch, $entries, $kept] = $request->restoring(version: $version, entries: $request->entries, head: $head);
			$this->service->writeCiphertext(
				row: clone $this->rows[self::SHARE_ID],
				certFingerprint: self::NEW_FINGERPRINT,
				ciphertext: array_intersect_key($entries->federated[0], $entry),
				savedAt: new DateTime(),
				patch: $patch,
				kept: $kept,
			);
		};
		$legacy = ['key' => 'NEW-KEY', 'login' => null, 'additionalFields' => null];

		foreach ([['HEAD-TOTP', ['totp' => null]], [null, ['totp' => 'PLANTED']]] as [$headTotp, $field]) {
			$this->existingShare(totp: $headTotp === null ? null : 'HEAD-TOTP-FOR-BOB', passkeys: null);
			try {
				$write($headTotp, $legacy + $field);
				$this->fail('A restore changed the partner\'s totp while the head kept its own');
			} catch (ShareSaveRefusal $refusal) {
				$this->assertSame('copy_fields_mismatch', $refusal->getError());
				$this->assertSame('OLD-KEY', $this->rows[self::SHARE_ID]->getKey());
			}
		}

		$this->existingShare(totp: 'HEAD-TOTP-FOR-BOB', passkeys: null);
		$write('HEAD-TOTP', $legacy);
		$this->assertSame('NEW-KEY', $this->rows[self::SHARE_ID]->getKey());
	}

	/**
	 * A restore with an entry that carries none of the kept fields does not look the partner up, so a share whose
	 * partner row is gone is written as a plain save is; an entry that carries a kept field passes too, as a gone
	 * partner stores no field.
	 */
	public function testARestoreToAShareWhosePartnerIsGoneIsWrittenAsAPlainSave(): void {
		$legacy = ['key' => 'NEW-KEY', 'login' => null, 'additionalFields' => null];
		foreach ([$legacy, $legacy + ['totp' => null]] as $ciphertext) {
			$this->alice(valueFields: '["totp","passkeys"]');
			$this->existingShare(totp: null, passkeys: null);
			$this->partnerGone = true;

			$this->service->writeCiphertext(
				row: clone $this->rows[self::SHARE_ID],
				certFingerprint: self::FINGERPRINT,
				ciphertext: $ciphertext,
				savedAt: new DateTime(),
				patch: ['key' => 'K', 'login' => null, 'additionalFields' => null],
				kept: ['totp' => 'HEAD-TOTP', 'passkeys' => null],
			);

			$this->assertSame('NEW-KEY', $this->rows[self::SHARE_ID]->getKey());
		}
	}

	public function testAWriteToAShareWhosePartnerIsGoneIsNotFound(): void {
		$this->alice(valueFields: '["totp","passkeys"]');
		$this->existingShare(totp: null, passkeys: null);
		$this->partnerGone = true;

		try {
			$this->shareSaveWrite(fields: ['totp' => self::TOTP, 'passkeys' => null], patch: ['totp' => 'T', 'passkeys' => null]);
			$this->fail('A share whose partner is gone was written');
		} catch (NotFoundException $exception) {
			$this->assertSame('Partner not found', $exception->getMessage());
		}

		$this->assertSame('OLD-KEY', $this->rows[self::SHARE_ID]->getKey());
	}

	/**
	 * A partner's nested values are dropped without so much as a warning.
	 */
	public function testAnAdvertisementKeepsOnlyTheKnownFieldNames(): void {
		$fields = new FederatedValueFields();
		set_error_handler(static function (int $errno, string $message): never {
			throw new \ErrorException($message, 0, $errno);
		});

		try {
			$this->assertSame(['totp', 'passkeys'], $fields->advertised(['totp', ['passkeys'], 5, null, 'passkeys']));
			$this->assertSame([], $fields->advertised([['totp' => 'x'], ['passkeys']]));
			$this->assertNull($fields->advertised(['totp' => 'totp']));
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Pull through Alice's listener as bob's server.
	 *
	 * @param array<string,mixed> $payload Beyond the shared secret
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function pull(array $payload): array {
		$listener = new FederationOcmRequestListener($this->createMock(FederatedCertificateService::class), $this->service);
		$event = new OCMEndpointRequestEvent(
			'POST',
			'keepiq/shares/' . self::SHARE_ID,
			['sharedSecret' => self::SHARED_SECRET] + $payload,
			'cloud.partner.example'
		);
		$listener->handle($event);
		$response = $event->getResponse();
		$this->assertInstanceOf(JSONResponse::class, $response);

		return [$response->getStatus(), $response->getData()];
	}

	public function testAPullThatDoesNotDeclareTheFieldsGetsTheUnknownAnswer(): void {
		$this->alice(valueFields: '["totp","passkeys"]');
		$this->existingShare(totp: self::TOTP, passkeys: null);

		[$status, $body] = $this->pull([]);

		$this->assertSame(Http::STATUS_NOT_FOUND, $status);
		$this->assertSame(['message' => 'Unknown recipient'], $body);
		[$status] = $this->pull(['valueFields' => ['passkeys']]);
		$this->assertSame(Http::STATUS_NOT_FOUND, $status);

		// The answer leaks nothing, but the owner's log names the partner and the field.
		$this->assertCount(2, $this->warnings);
		$this->assertSame(self::SHARE_ID, $this->warnings[0]['share']);
		$this->assertSame('totp', $this->warnings[0]['fields']);
		$this->assertStringNotContainsString(self::TOTP, (string)json_encode($this->warnings));
	}

	/**
	 * A pull refused for another reason, such as a wrong shared secret, logs no value-field warning.
	 *
	 * @return void
	 */
	public function testAPullWithAWrongSecretLogsNoValueFieldWarning(): void {
		$this->alice(valueFields: '["totp","passkeys"]');
		$this->existingShare(totp: self::TOTP, passkeys: null);
		$this->rows[self::SHARE_ID]->setSharedSecretHash(hash('sha256', 'another secret'));

		[$status] = $this->pull([]);

		$this->assertSame(Http::STATUS_NOT_FOUND, $status);
		$this->assertSame([], $this->warnings);
	}

	public function testAPullThatDeclaresTheFieldsGetsThem(): void {
		$this->alice(valueFields: '["totp","passkeys"]');
		$this->existingShare(totp: self::TOTP, passkeys: self::PASSKEYS);

		[$status, $body] = $this->pull(['valueFields' => ['totp', 'passkeys']]);

		$this->assertSame(Http::STATUS_OK, $status);
		$this->assertSame('OLD-KEY', $body['key']);
		$this->assertSame(self::TOTP, $body['totp']);
		$this->assertSame(self::PASSKEYS, $body['passkeys']);
	}

	public function testAnOldPartnersPullOfAShareWithoutTheFieldsWorksAsBefore(): void {
		$this->alice(valueFields: null);
		$this->existingShare(totp: null, passkeys: null);

		[$status, $body] = $this->pull([]);

		$this->assertSame(Http::STATUS_OK, $status);
		$this->assertSame('OLD-KEY', $body['key']);
		$this->assertNull($body['totp']);
	}

	/**
	 * Bob's server pulling `$answer`, with the stored copies in `$copies`.
	 *
	 * @param array<string,mixed> $answer The sender's answer
	 * @param array<string,Secret> $copies Bob's secrets
	 * @param array<int,array> $requests The payloads sent, filled in
	 *
	 * @return FederatedCopyService
	 */
	private function bob(array $answer, array &$copies, array &$requests): FederatedCopyService {
		$ocm = $this->createMock(IOCMDiscoveryService::class);
		$ocm->method('requestRemoteOcmEndpoint')->willReturnCallback(
			function (string $capability, string $url, string $path, array $payload) use ($answer, &$requests): IResponse {
				$requests[] = $payload;
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn(200);
				$response->method('getBody')->willReturn(json_encode($answer));
				return $response;
			}
		);
		$partner = $this->partner('cloud.city.example', false, true);
		$partners = $this->partnerMapper([$partner]);
		$partners->method('findById')->willReturn($partner);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturn(self::SHARED_SECRET);

		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('insert')->willReturnCallback(
			function (Secret $secret) use (&$copies): Secret {
				$copies[$secret->getId()] = $secret;
				return $secret;
			}
		);
		$secrets->method('update')->willReturnArgument(0);
		$secrets->method('findById')->willReturnCallback(
			static function (string $id) use (&$copies): Secret {
				return $copies[$id] ?? throw new DoesNotExistException('none');
			}
		);
		$suite = new EncryptionSuite();
		$suite->setId('suite-bob');
		$suites = $this->createMock(EncryptionSuiteMapper::class);
		$suites->method('findActiveByOwner')->willReturn($suite);
		$types = $this->createMock(SecretTypeService::class);
		$types->method('resolveTypeForSecret')->willReturn('type-login');

		return new FederatedCopyService(
			puller: new FederatedSharePuller($partners, $ocm, $crypto, $this->cloudIdManager()),
			secretMapper: $secrets,
			suiteMapper: $suites,
			typeService: $types,
		);
	}

	/**
	 * The inbound share bob accepted.
	 *
	 * @return FederatedInbound
	 */
	private function inbound(): FederatedInbound {
		$row = new FederatedInbound();
		$row->setId('in-1');
		$row->setRecipientUid('bob');
		$row->setSenderCloudId('alice@cloud.city.example');
		$row->setPartnerId('p-cloud.city.example');
		$row->setRemoteShareId(self::SHARE_ID);
		$row->setName('Supplier portal');
		$row->setSharedSecretEnc('enc');
		$row->setStatus(FederatedInbound::STATUS_ACCEPTED);

		return $row;
	}

	public function testBobsServerDeclaresTheFieldsAndPutsThemOnTheCopy(): void {
		$copies = [];
		$requests = [];
		$answer = [
			'recipientCloudId' => 'bob@cloud.here.example',
			'name' => 'Supplier portal',
			'key' => 'KEY',
			'totp' => self::TOTP,
			'passkeys' => self::PASSKEYS,
		];
		$service = $this->bob($answer, $copies, $requests);

		$copy = $service->pullNew($this->inbound());

		$this->assertSame(['totp', 'passkeys'], $requests[0]['valueFields']);
		$this->assertSame(self::TOTP, $copy->getTotp());
		$this->assertSame(self::PASSKEYS, $copy->getPasskeys());
	}

	public function testACopyFromAnAnswerWithoutTheFieldsHoldsNone(): void {
		$copies = [];
		$requests = [];
		$answer = ['recipientCloudId' => 'bob@cloud.here.example', 'name' => 'Supplier portal', 'key' => 'KEY'];
		$service = $this->bob($answer, $copies, $requests);
		$copy = $service->pullNew($this->inbound());
		$this->assertNull($copy->getTotp());
		$this->assertNull($copy->getPasskeys());

		// A refresh replaces the copy whole: a field the sender no longer serves goes.
		$copy->setTotp('STALE');
		$inbound = $this->inbound();
		$inbound->setSecretId($copy->getId());
		$refreshed = $service->refresh($inbound);
		$this->assertNull($refreshed->getTotp());
	}
}
