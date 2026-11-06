<?php

/**
 * Unit tests for OfflineController (offline-readonly-cache §5.1).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
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

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\OfflineController;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretTypeMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\OfflineManifestService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the offline-cache manifest endpoint.
 */
class OfflineControllerTest extends TestCase {
	private OfflineController $controller;

	private EncryptionSuiteMapper&MockObject $suiteMapper;

	private SecretMapper&MockObject $secretMapper;

	private FolderMapper&MockObject $folderMapper;

	private SecretTypeMapper&MockObject $typeMapper;

	private ShareTargetMapper&MockObject $shareTargets;

	private IAppConfig&MockObject $appConfig;

	private IUserSession&MockObject $userSession;

	/**
	 * Whether the two-factor gate blocks alice (no provider, policy on).
	 *
	 * @var bool
	 */
	private bool $twoFactorBlocks = false;

	/**
	 * Build the controller over mocked mappers.
	 *
	 * The snapshot assembler is the REAL OfflineManifestService wrapped around
	 * those same mocks rather than a mock of its own: these tests assert the
	 * manifest's shape, so stubbing the assembler would assert only that the
	 * controller returns whatever it is handed.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->suiteMapper = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
		$this->secretMapper = $this->createMock(originalClassName: SecretMapper::class);
		$this->folderMapper = $this->createMock(originalClassName: FolderMapper::class);
		$this->typeMapper = $this->createMock(originalClassName: SecretTypeMapper::class);
		$this->shareTargets = $this->createMock(originalClassName: ShareTargetMapper::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new OfflineController(
			request: $this->createMock(originalClassName: IRequest::class),
			manifestService: new OfflineManifestService(
				suiteMapper: $this->suiteMapper,
				secretMapper: $this->secretMapper,
				folderMapper: $this->folderMapper,
				typeMapper: $this->typeMapper,
				twoFactor: $this->twoFactorGate(),
				shareTargets: $this->shareTargets,
			),
			appConfig: $this->appConfig,
			userSession: $this->userSession,
		);
	}//end setUp()

	/**
	 * The manifest 403s when the admin org-wide switch is off (§5.1).
	 *
	 * @return void
	 */
	public function testManifestForbiddenWhenDisabled(): void {
		$this->appConfig->method('getValueBool')->willReturn(false);
		$this->suiteMapper->expects($this->never())->method('findActiveByOwner');

		$response = $this->controller->manifest();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testManifestForbiddenWhenDisabled()

	/**
	 * The manifest is a ciphertext-only owner-scoped snapshot: it reads the
	 * caller's active suite + own secrets + own folders and never decrypts
	 * (§5.1). The secret ciphertext fields are passed through verbatim.
	 *
	 * @return void
	 */
	public function testManifestReturnsOwnerScopedCiphertextSnapshot(): void {
		$this->appConfig->method('getValueBool')->willReturn(true);

		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setOwnerType('user');
		$suite->setOwnerId('alice');
		$suite->setCertificate('CERT-PEM');
		$suite->setPrivateKey('ENVELOPE-BLOB');
		$suite->setStatus('active');
		$this->suiteMapper->expects($this->once())->method('findActiveByOwner')
			->with('user', 'alice')->willReturn($suite);

		$secret = new Secret();
		$secret->setId('sec-1');
		$secret->setName('Prod key');
		$secret->setOwnerType('user');
		$secret->setOwnerId('alice');
		$secret->setKey('RSA-CIPHERTEXT');
		$secret->setTotp('TOTP-CIPHERTEXT');
		$secret->setPasskeys('PASSKEYS-CIPHERTEXT');
		$this->secretMapper->expects($this->once())->method('findByOwner')
			->with('user', 'alice')->willReturn([$secret]);

		$folder = new Folder();
		$folder->setId('f-1');
		$folder->setName('Servers');
		$this->folderMapper->expects($this->once())->method('findByOwner')
			->with('user', 'alice')->willReturn([$folder]);

		$this->typeMapper->expects($this->once())->method('findAvailableForUser')
			->with('alice')->willReturn([]);

		$data = $this->controller->manifest()->getData();

		$this->assertSame('suite-1', $data['suite']['id']);
		$this->assertSame('ENVELOPE-BLOB', $data['suite']['privateKey']);
		$this->assertCount(1, $data['secrets']);
		$this->assertSame('RSA-CIPHERTEXT', $data['secrets'][0]['key']);
		$this->assertSame('TOTP-CIPHERTEXT', $data['secrets'][0]['totp']);
		$this->assertSame('PASSKEYS-CIPHERTEXT', $data['secrets'][0]['passkeys']);
		$this->assertCount(1, $data['folders']);
		$this->assertArrayHasKey('syncedAt', $data);
	}//end testManifestReturnsOwnerScopedCiphertextSnapshot()

	/**
	 * The manifest carries the offline edits rule: off unless set
	 * (offline-edit-queue).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-administrators-control-offline-edits
	 */
	public function testManifestCarriesTheOfflineEditsRule(): void {
		foreach ([null => false, 'on' => true] as $stored => $expected) {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueBool')->willReturnCallback(
				static fn (string $app, string $key, bool $default) => $key === 'offline_edits_enabled' ? ($stored === 'on' ? true : $default) : true
			);
			$suite = new EncryptionSuite();
			$suite->setId('suite-1');
			$this->suiteMapper->method('findActiveByOwner')->willReturn($suite);
			$this->secretMapper->method('findByOwner')->willReturn([]);
			$this->folderMapper->method('findByOwner')->willReturn([]);
			$this->typeMapper->method('findAvailableForUser')->willReturn([]);
			$controller = new OfflineController(
				request: $this->createMock(IRequest::class),
				manifestService: new OfflineManifestService(
					suiteMapper: $this->suiteMapper,
					secretMapper: $this->secretMapper,
					folderMapper: $this->folderMapper,
					typeMapper: $this->typeMapper,
					twoFactor: $this->twoFactorGate(),
					shareTargets: $this->shareTargets,
				),
				appConfig: $appConfig,
				userSession: $this->userSession,
			);

			$this->assertSame($expected, $controller->manifest()->getData()['offlineEditsEnabled']);
		}
	}//end testManifestCarriesTheOfflineEditsRule()

	/**
	 * A copy's row carries its source's version, written as the row's own,
	 * so an edit made offline can name it; a row that is not a copy, or
	 * whose source is gone, carries null. Nothing else of the source shows.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/offline-edit-queue/spec.md#requirement-replay-saves-value-changes-through-the-share-save
	 */
	public function testManifestCarriesEachCopysSourceVersion(): void {
		$this->appConfig->method('getValueBool')->willReturn(true);
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$this->suiteMapper->method('findActiveByOwner')->willReturn($suite);
		$this->folderMapper->method('findByOwner')->willReturn([]);
		$this->typeMapper->method('findAvailableForUser')->willReturn([]);

		$rows = [];
		foreach (['own', 'c-bob', 'c-gone'] as $id) {
			$row = new Secret();
			$row->setId($id);
			$row->setOwnerType('user');
			$row->setOwnerId('alice');
			$row->setUpdatedAt(new \DateTime('2026-10-01T09:00:00+00:00'));
			$rows[] = $row;
		}

		$source = new Secret();
		$source->setId('src-bob');
		$source->setOwnerId('bob');
		$source->setKey('BOBS-CIPHERTEXT');
		$source->setUpdatedAt(new \DateTime('2026-10-01T10:00:05+00:00'));
		$this->secretMapper->method('findByOwner')->willReturn($rows);
		$this->secretMapper->expects($this->once())->method('findByIds')
			->with(['src-bob', 'src-gone'])->willReturn(['src-bob' => $source]);
		$targets = [];
		foreach (['c-bob' => 'src-bob', 'c-gone' => 'src-gone'] as $copyId => $sourceId) {
			$target = new ShareTarget();
			$target->setSecretId($copyId);
			$target->setSourceSecretId($sourceId);
			$targets[] = $target;
		}

		$this->shareTargets->expects($this->once())->method('findByTargetUser')->with('alice')->willReturn($targets);

		$secrets = array_column($this->controller->manifest()->getData()['secrets'], null, 'id');

		$this->assertNull($secrets['own']['sourceUpdatedAt']);
		$this->assertSame($source->getUpdatedAt()?->format('c'), $secrets['c-bob']['sourceUpdatedAt']);
		$this->assertNull($secrets['c-gone']['sourceUpdatedAt']);
		$this->assertStringNotContainsString('BOBS-CIPHERTEXT', (string)json_encode($secrets));
	}//end testManifestCarriesEachCopysSourceVersion()

	/**
	 * A REAL TwoFactorGate whose policy applies when $twoFactorBlocks is
	 * set; alice has no provider enabled.
	 *
	 * @return \OCA\Keepiq\Service\TwoFactorGate
	 */
	private function twoFactorGate(): \OCA\Keepiq\Service\TwoFactorGate {
		$policies = $this->createMock(\OCA\Keepiq\Service\VaultPolicyService::class);
		$policies->method('appliesTo')->willReturnCallback(fn (): bool => $this->twoFactorBlocks);
		$registry = $this->createMock(\OCP\Authentication\TwoFactorAuth\IRegistry::class);
		$registry->method('getProviderStates')->willReturn(['backup_codes' => true]);
		$userManager = $this->createMock(\OCP\IUserManager::class);
		$userManager->method('get')->willReturn($this->createMock(IUser::class));

		return new \OCA\Keepiq\Service\TwoFactorGate(policies: $policies, registry: $registry, userManager: $userManager);
	}//end twoFactorGate()

	/**
	 * admin-vault-policies §3.2: the manifest leaves the suite out and says
	 * why, so no offline unlock is possible either.
	 *
	 * @return void
	 */
	public function testTwoFactorPolicyLeavesTheSuiteOut(): void {
		$this->twoFactorBlocks = true;
		$this->appConfig->method('getValueBool')->willReturn(true);
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setPrivateKey('ENVELOPE-BLOB');
		$this->suiteMapper->method('findActiveByOwner')->willReturn($suite);
		$this->secretMapper->method('findByOwner')->willReturn([]);
		$this->folderMapper->method('findByOwner')->willReturn([]);
		$this->typeMapper->method('findAvailableForUser')->willReturn([]);

		$data = $this->controller->manifest()->getData();

		$this->assertNull($data['suite']);
		$this->assertSame('two_factor_required', $data['unlockBlocked']);
		$this->assertStringNotContainsString('ENVELOPE-BLOB', (string)json_encode($data));
	}//end testTwoFactorPolicyLeavesTheSuiteOut()
}//end class
