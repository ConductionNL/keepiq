<?php

/**
 * Unit tests for SecretService.
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

use InvalidArgumentException;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\SuiteBlockedException;
use OCA\Keepiq\Exception\ViewOnlyCopyException;
use OCA\Keepiq\Exception\WriteLockedException;
use OCA\Keepiq\Service\FolderOwnershipGuard;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\SecretVersionService;
use OCA\Keepiq\Service\ViewOnlyCopyGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SecretService.
 */
class SecretServiceTest extends TestCase {

	/**
	 * @var SecretService
	 */
	private SecretService $service;

	/**
	 * @var SecretMapper
	 */
	private $mapper;

	/**
	 * @var SecretTypeService
	 */
	private $typeService;

	/**
	 * @var EncryptionSuiteMapper
	 */
	private $suiteMapper;

	/**
	 * @var MigrationService
	 */
	private $migrationService;

	/**
	 * @var LinkShareService
	 */
	private $linkShareService;

	/**
	 * @var FolderOwnershipGuard
	 */
	private FolderOwnershipGuard $folderOwnership;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(SecretMapper::class);
		$this->typeService = $this->createMock(SecretTypeService::class);
		$this->suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$this->migrationService = $this->createMock(MigrationService::class);
		$this->linkShareService = $this->createMock(LinkShareService::class);
		$logger = $this->createMock(LoggerInterface::class);

		// Keepiq#795: a folder move is checked against the owner. In these
		// tests alice owns every folder, through the real guard.
		$folderMapper = $this->createMock(FolderMapper::class);
		$folderMapper->method('findById')->willReturnCallback(
			static function (string $id): Folder {
				$folder = new Folder();
				$folder->setId($id);
				$folder->setOwnerType('user');
				$folder->setOwnerId('alice');
				return $folder;
			}
		);
		$this->folderOwnership = new FolderOwnershipGuard($folderMapper);

		$this->service = new SecretService(
			mapper: $this->mapper,
			typeService: $this->typeService,
			suiteMapper: $this->suiteMapper,
			migrationService: $this->migrationService,
			linkShareService: $this->linkShareService,
			logger: $logger,
			db: $this->createMock(\OCP\IDBConnection::class),
			viewOnlyCopies: $this->createStub(ViewOnlyCopyGuard::class),
			folderOwnership: $this->folderOwnership,
		);
	}//end setUp()

	/**
	 * Build a suite with a status.
	 *
	 * @param string $status The suite status
	 * @param string $id The suite ID
	 *
	 * @return EncryptionSuite
	 */
	private function makeSuite(string $status = 'active', string $id = 'suite-1'): EncryptionSuite {
		$suite = new EncryptionSuite();
		$suite->setId($id);
		$suite->setOwnerType('user');
		$suite->setOwnerId('alice');
		$suite->setStatus($status);
		return $suite;
	}//end makeSuite()

	/**
	 * Build a secret owned by alice.
	 *
	 * @param string $id The secret ID
	 * @param string $ownerId The owner ID
	 * @param string $suiteId The suite ID
	 *
	 * @return Secret
	 */
	private function makeSecret(string $id = 's-1', string $ownerId = 'alice', string $suiteId = 'suite-1'): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setName('GitHub');
		$secret->setUrl('https://github.com');
		$secret->setTypeId('login-id');
		$secret->setKey('CIPHERTEXT');
		$secret->setEncryptionSuiteId($suiteId);
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		return $secret;
	}//end makeSecret()

	/**
	 * Build a secret with a specific name (and a non-matching url), for
	 * bounded-scan tests that need distinct filler vs. target rows.
	 *
	 * @param string $id The secret ID
	 * @param string $name The plaintext name
	 *
	 * @return Secret
	 */
	private function makeNamedSecret(string $id, string $name): Secret {
		$secret = $this->makeSecret(id: $id);
		$secret->setName($name);
		$secret->setUrl('https://example.invalid/' . $id);
		return $secret;
	}//end makeNamedSecret()

	/**
	 * Create stores the encrypted blob and records the suite.
	 *
	 * @return void
	 */
	public function testCreateStoresCiphertextAndSuite(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);
		$this->suiteMapper->method('findActiveByOwner')->willReturn($this->makeSuite());
		$this->typeService->method('resolveTypeForSecret')->willReturn('login-id');
		$this->mapper->expects($this->once())->method('insert');

		$secret = $this->service->create(
			data: ['name' => 'GitHub', 'key' => 'CIPHERTEXT'],
			userId: 'alice',
		);

		$this->assertSame('CIPHERTEXT', $secret->getKey());
		$this->assertSame('suite-1', $secret->getEncryptionSuiteId());
		$this->assertSame('login-id', $secret->getTypeId());
	}//end testCreateStoresCiphertextAndSuite()

	/**
	 * Create requires a name and a key.
	 *
	 * @return void
	 */
	public function testCreateMissingFieldsRejected(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);
		$this->expectException(InvalidArgumentException::class);
		$this->service->create(data: ['name' => 'NoKey'], userId: 'alice');
	}//end testCreateMissingFieldsRejected()

	/**
	 * Create is blocked when no active suite exists (e.g. revoked).
	 *
	 * @return void
	 */
	/**
	 * An ordinary user create still refuses an empty key.
	 *
	 * The placeholder exception must never become the default: a Secret with no
	 * value and no request justifying it is litter, and this is the failure the
	 * opt-in exists to keep out.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-unfilled-request-placeholder
	 */
	public function testCreateStillRefusesAnEmptyKeyByDefault(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A secret requires a name and a key');
		$this->service->create(
			data: ['name' => 'has-a-name', 'key' => ''],
			userId: 'alice',
		);
	}//end testCreateStillRefusesAnEmptyKeyByDefault()

	/**
	 * `allowUnfilled` permits the keyless secret-request placeholder.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-unfilled-request-placeholder
	 */
	public function testCreateAllowsAnUnfilledPlaceholder(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);
		$this->suiteMapper->method('findActiveByOwner')->willReturn($this->makeSuite());
		$this->typeService->method('resolveTypeForSecret')->willReturn('login-id');
		$this->mapper->expects($this->once())->method('insert');

		$secret = $this->service->create(
			data: ['name' => 'Unfilled request', 'key' => ''],
			userId: 'alice',
			allowUnfilled: true,
		);

		$this->assertSame('', $secret->getKey());
		$this->assertSame('user', $secret->getOwnerType());
		$this->assertSame('alice', $secret->getOwnerId());
		$this->assertSame('suite-1', $secret->getEncryptionSuiteId());
	}//end testCreateAllowsAnUnfilledPlaceholder()

	/**
	 * A NAME is required in both modes.
	 *
	 * A nameless empty Secret cannot be identified in a vault, so the exception
	 * relaxes the key requirement only.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-unfilled-request-placeholder
	 */
	public function testUnfilledPlaceholderStillRequiresAName(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);

		$this->expectException(InvalidArgumentException::class);
		$this->service->create(
			data: ['name' => '', 'key' => ''],
			userId: 'alice',
			allowUnfilled: true,
		);
	}//end testUnfilledPlaceholderStillRequiresAName()

	/**
	 * Filling a placeholder does not snapshot its empty prior state.
	 *
	 * Regression guard for a 500 that reached a live instance. `update()` snapshots
	 * the PRE-update row; for a placeholder that row has an empty `key`, and
	 * SecretVersion::$key defaults to '' — so the Entity setter never marked it
	 * dirty, QBMapper omitted the column, and the NOT NULL constraint on
	 * keepiq_secret_versions.key rejected the insert. The recipient saw
	 * "Unable to fulfil request".
	 *
	 * Skipping is also right on its own terms: a first fill has no earlier value to
	 * return to, so the version row would say "nothing".
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-unfilled-request-placeholder
	 */
	public function testFillingAPlaceholderDoesNotSnapshotItsEmptyState(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);

		$placeholder = new Secret();
		$placeholder->setId('sec-placeholder');
		$placeholder->setName('Unfilled request');
		$placeholder->setKey('');
		$placeholder->setOwnerType('user');
		$placeholder->setOwnerId('alice');
		$placeholder->setEncryptionSuiteId('suite-1');
		$this->mapper->method('findById')->willReturn($placeholder);
		$this->mapper->expects($this->once())->method('update');

		// versionService is not wired in setUp (it is an optional dependency), so
		// the snapshot branch would be dead here without an explicit instance.
		$versionService = $this->createPartialMock(SecretVersionService::class, ['snapshot']);
		$versionService->expects($this->never())->method('snapshot');
		$service = $this->serviceWithVersions($versionService);

		$secret = $service->update(
			id: 'sec-placeholder',
			data: ['key' => 'CIPHERTEXT', 'url' => 'https://example.test'],
			userId: 'alice',
		);

		$this->assertSame('CIPHERTEXT', $secret->getKey());
	}//end testFillingAPlaceholderDoesNotSnapshotItsEmptyState()

	/**
	 * Updating a secret that HELD a value still snapshots it.
	 *
	 * The skip above must be narrow: losing version history on a real credential
	 * change would be a worse bug than the one it fixes.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-unfilled-request-placeholder
	 */
	public function testUpdatingAFilledSecretStillSnapshots(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);

		$filled = new Secret();
		$filled->setId('sec-filled');
		$filled->setName('GitHub');
		$filled->setKey('OLD-CIPHERTEXT');
		$filled->setOwnerType('user');
		$filled->setOwnerId('alice');
		$filled->setEncryptionSuiteId('suite-1');
		$this->mapper->method('findById')->willReturn($filled);
		$this->mapper->method('update')->willReturnArgument(0);

		$versionService = $this->createPartialMock(SecretVersionService::class, ['snapshot']);
		$versionService->expects($this->once())->method('snapshot');
		$service = $this->serviceWithVersions($versionService);

		$service->update(
			id: 'sec-filled',
			data: ['key' => 'NEW-CIPHERTEXT'],
			userId: 'alice',
		);
	}//end testUpdatingAFilledSecretStillSnapshots()

	/**
	 * A SecretService with the version-history dependency wired.
	 *
	 * @param SecretVersionService $versionService The snapshot recorder
	 *
	 * @return SecretService
	 */
	private function serviceWithVersions(SecretVersionService $versionService): SecretService {
		return new SecretService(
			mapper: $this->mapper,
			typeService: $this->typeService,
			suiteMapper: $this->suiteMapper,
			migrationService: $this->migrationService,
			linkShareService: $this->linkShareService,
			logger: $this->createMock(LoggerInterface::class),
			db: $this->createMock(\OCP\IDBConnection::class),
			viewOnlyCopies: $this->createStub(ViewOnlyCopyGuard::class),
			versionService: $versionService,
		);
	}//end serviceWithVersions()

	public function testCreateBlockedWhenNoActiveSuite(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);
		$this->suiteMapper->method('findActiveByOwner')->willThrowException(new DoesNotExistException('none'));

		$this->expectException(SuiteBlockedException::class);
		$this->service->create(data: ['name' => 'X', 'key' => 'C'], userId: 'alice');
	}//end testCreateBlockedWhenNoActiveSuite()

	/**
	 * Create is rejected with a write lock.
	 *
	 * @return void
	 */
	public function testCreateRejectedDuringWriteLock(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(true);

		$this->expectException(WriteLockedException::class);
		$this->service->create(data: ['name' => 'X', 'key' => 'C'], userId: 'alice');
	}//end testCreateRejectedDuringWriteLock()

	/**
	 * Reading another user's secret is forbidden.
	 *
	 * @return void
	 */
	public function testGetForeignSecretForbidden(): void {
		$this->mapper->method('findById')->willReturn($this->makeSecret(ownerId: 'bob'));

		$this->expectException(ForbiddenException::class);
		$this->service->get(id: 's-1', userId: 'alice');
	}//end testGetForeignSecretForbidden()

	/**
	 * Reading a missing secret throws NotFound.
	 *
	 * @return void
	 */
	public function testGetMissingSecret(): void {
		$this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));

		$this->expectException(NotFoundException::class);
		$this->service->get(id: 'gone', userId: 'alice');
	}//end testGetMissingSecret()

	/**
	 * A secret with a revoked suite returns 403 (blocked) on read.
	 *
	 * @return void
	 */
	public function testGetRevokedSuiteBlocked(): void {
		$this->mapper->method('findById')->willReturn($this->makeSecret());
		$this->suiteMapper->method('findById')->willReturn($this->makeSuite(status: 'revoked'));

		$this->expectException(SuiteBlockedException::class);
		$this->service->get(id: 's-1', userId: 'alice');
	}//end testGetRevokedSuiteBlocked()

	/**
	 * A secret with an active suite reads normally.
	 *
	 * @return void
	 */
	public function testGetActiveSuiteReturnsSecret(): void {
		$this->mapper->method('findById')->willReturn($this->makeSecret());
		$this->suiteMapper->method('findById')->willReturn($this->makeSuite(status: 'active'));

		$secret = $this->service->get(id: 's-1', userId: 'alice');
		$this->assertSame('s-1', $secret->getId());
	}//end testGetActiveSuiteReturnsSecret()

	/**
	 * List includes blocked secrets with metadata only (no ciphertext).
	 *
	 * @return void
	 */
	public function testListBlockedSecretOmitsCiphertext(): void {
		$this->mapper->method('findByOwner')->willReturn([$this->makeSecret()]);
		$this->mapper->method('countByOwner')->willReturn(1);
		$this->suiteMapper->method('findById')->willReturn($this->makeSuite(status: 'revoked'));

		$result = $this->service->list(
			userId: 'alice',
			folderId: null,
			sort: 'name',
			direction: 'asc',
			page: 1,
			limit: 50,
		);

		$item = $result['items'][0];
		$this->assertTrue($item['blocked']);
		$this->assertArrayNotHasKey('key', $item);
		$this->assertArrayHasKey('blockedReason', $item);
		$this->assertSame('GitHub', $item['name']);
	}//end testListBlockedSecretOmitsCiphertext()

	/**
	 * List returns the full encrypted blob for an active suite.
	 *
	 * @return void
	 */
	public function testListActiveSecretIncludesCiphertext(): void {
		$this->mapper->method('findByOwner')->willReturn([$this->makeSecret()]);
		$this->mapper->method('countByOwner')->willReturn(1);
		$this->suiteMapper->method('findById')->willReturn($this->makeSuite(status: 'active'));

		$result = $this->service->list(
			userId: 'alice',
			folderId: null,
			sort: 'name',
			direction: 'asc',
			page: 1,
			limit: 50,
		);

		$item = $result['items'][0];
		$this->assertFalse($item['blocked']);
		$this->assertSame('CIPHERTEXT', $item['key']);
	}//end testListActiveSecretIncludesCiphertext()

	/**
	 * Update is rejected during a write lock.
	 *
	 * @return void
	 */
	public function testUpdateRejectedDuringWriteLock(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(true);

		$this->expectException(WriteLockedException::class);
		$this->service->update(id: 's-1', data: ['name' => 'New'], userId: 'alice');
	}//end testUpdateRejectedDuringWriteLock()

	/**
	 * Create stamps keyUpdatedAt — ciphertext age starts at creation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-password-age-tracking
	 */
	public function testCreateStampsKeyUpdatedAt(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);
		$this->suiteMapper->method('findActiveByOwner')->willReturn($this->makeSuite());
		$this->typeService->method('resolveTypeForSecret')->willReturn('login-id');
		$this->mapper->expects($this->once())->method('insert');

		$secret = $this->service->create(
			data: ['name' => 'GitHub', 'key' => 'CIPHERTEXT'],
			userId: 'alice',
		);

		$this->assertNotNull($secret->getKeyUpdatedAt());
	}//end testCreateStampsKeyUpdatedAt()

	/**
	 * Updating the encrypted key blob stamps a fresh keyUpdatedAt.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-password-age-tracking
	 */
	public function testUpdateChangedKeyStampsKeyUpdatedAt(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);
		$this->typeService->method('resolveTypeForSecret')->willReturn('login-id');
		$old = $this->makeSecret();
		$old->setKey('OLD-CIPHERTEXT');
		$old->setKeyUpdatedAt(new \DateTime('2020-01-01T00:00:00+00:00'));
		$this->mapper->method('findById')->willReturn($old);
		$this->mapper->expects($this->once())->method('update');

		$secret = $this->service->update(
			id: 's-1',
			data: ['key' => 'NEW-CIPHERTEXT'],
			userId: 'alice',
		);

		$this->assertSame('NEW-CIPHERTEXT', $secret->getKey());
		$this->assertGreaterThan(
			(new \DateTime('2021-01-01T00:00:00+00:00'))->getTimestamp(),
			$secret->getKeyUpdatedAt()->getTimestamp(),
			'A changed key blob must refresh keyUpdatedAt.'
		);
	}//end testUpdateChangedKeyStampsKeyUpdatedAt()

	/**
	 * Replacing the value clears the possibly-compromised warning.
	 *
	 * The warning asks the user to replace the exposed value at its source.
	 * Doing so is the one thing that answers it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
	 */
	public function testReplacingTheValueClearsPossiblyCompromised(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);
		$this->typeService->method('resolveTypeForSecret')->willReturn('login-id');
		$old = $this->makeSecret();
		$old->setKey('OLD-CIPHERTEXT');
		$old->setPossiblyCompromisedAt(new \DateTime('2026-01-01T00:00:00+00:00'));
		$this->mapper->method('findById')->willReturn($old);
		$this->mapper->expects($this->once())->method('update');

		$secret = $this->service->update(
			id: 's-1',
			data: ['key' => 'REPLACED-CIPHERTEXT'],
			userId: 'alice',
		);

		$this->assertNull($secret->getPossiblyCompromisedAt());
	}//end testReplacingTheValueClearsPossiblyCompromised()

	/**
	 * A metadata edit MUST leave the possibly-compromised warning standing.
	 *
	 * Renaming a secret does nothing about the exposed value, so clearing the
	 * warning here would quietly tell the user they were safe when they are not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
	 */
	public function testMetadataEditPreservesPossiblyCompromised(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);
		$this->typeService->method('resolveTypeForSecret')->willReturn('login-id');
		$stamp = new \DateTime('2026-01-01T00:00:00+00:00');
		$old = $this->makeSecret();
		$old->setPossiblyCompromisedAt($stamp);
		$this->mapper->method('findById')->willReturn($old);
		$this->mapper->expects($this->once())->method('update');

		$secret = $this->service->update(
			id: 's-1',
			data: ['name' => 'Renamed', 'url' => 'https://new.example', 'folderId' => 'folder-2'],
			userId: 'alice',
		);

		$this->assertSame($stamp->getTimestamp(), $secret->getPossiblyCompromisedAt()->getTimestamp());
	}//end testMetadataEditPreservesPossiblyCompromised()

	/**
	 * Re-sending the SAME key alongside a rename MUST leave the warning set.
	 *
	 * A client that echoes the whole record back on every save would otherwise
	 * clear the warning without the value ever having changed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
	 */
	public function testUnchangedKeyPreservesPossiblyCompromised(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);
		$this->typeService->method('resolveTypeForSecret')->willReturn('login-id');
		$stamp = new \DateTime('2026-01-01T00:00:00+00:00');
		$old = $this->makeSecret();
		$old->setKey('SAME-CIPHERTEXT');
		$old->setPossiblyCompromisedAt($stamp);
		$this->mapper->method('findById')->willReturn($old);
		$this->mapper->expects($this->once())->method('update');

		$secret = $this->service->update(
			id: 's-1',
			data: ['name' => 'Renamed', 'key' => 'SAME-CIPHERTEXT'],
			userId: 'alice',
		);

		$this->assertSame($stamp->getTimestamp(), $secret->getPossiblyCompromisedAt()->getTimestamp());
	}//end testUnchangedKeyPreservesPossiblyCompromised()

	/**
	 * Renaming a secret (or any non-key edit) MUST NOT reset keyUpdatedAt.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-password-age-tracking
	 */
	public function testRenameDoesNotResetKeyUpdatedAt(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);
		$this->typeService->method('resolveTypeForSecret')->willReturn('login-id');
		$stamp = new \DateTime('2020-01-01T00:00:00+00:00');
		$old = $this->makeSecret();
		$old->setKeyUpdatedAt($stamp);
		$this->mapper->method('findById')->willReturn($old);
		$this->mapper->expects($this->once())->method('update');

		$secret = $this->service->update(
			id: 's-1',
			data: ['name' => 'Renamed', 'url' => 'https://new.example'],
			userId: 'alice',
		);

		$this->assertSame(
			$stamp->getTimestamp(),
			$secret->getKeyUpdatedAt()->getTimestamp(),
			'A rename must not touch ciphertext age.'
		);
	}//end testRenameDoesNotResetKeyUpdatedAt()

	/**
	 * Re-submitting the identical key ciphertext MUST NOT reset keyUpdatedAt.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-password-age-tracking
	 */
	public function testUnchangedKeyDoesNotResetKeyUpdatedAt(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);
		$this->typeService->method('resolveTypeForSecret')->willReturn('login-id');
		$stamp = new \DateTime('2020-01-01T00:00:00+00:00');
		$old = $this->makeSecret();
		$old->setKey('CIPHERTEXT');
		$old->setKeyUpdatedAt($stamp);
		$this->mapper->method('findById')->willReturn($old);
		$this->mapper->expects($this->once())->method('update');

		$secret = $this->service->update(
			id: 's-1',
			data: ['key' => 'CIPHERTEXT'],
			userId: 'alice',
		);

		$this->assertSame(
			$stamp->getTimestamp(),
			$secret->getKeyUpdatedAt()->getTimestamp(),
			'A no-op key re-submit must not un-stale the credential.'
		);
	}//end testUnchangedKeyDoesNotResetKeyUpdatedAt()

	/**
	 * Delete cascades to link shares and removes the secret.
	 *
	 * @return void
	 */
	public function testDeleteCascadesToLinkShares(): void {
		$this->mapper->method('findById')->willReturn($this->makeSecret());
		$this->linkShareService->expects($this->once())->method('deleteBySecretId')->with('s-1');
		$this->mapper->expects($this->once())->method('delete');

		$this->service->delete(id: 's-1', userId: 'alice');
	}//end testDeleteCascadesToLinkShares()

	/**
	 * Delete cascades to GroupShares and SecretDelegations when the
	 * optional mappers are wired (W29 §10.1).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#task-10.1
	 */
	public function testDeleteCascadesToGroupSharesAndDelegations(): void {
		$groupShareMapper = $this->createMock(\OCA\Keepiq\Db\GroupShareMapper::class);
		$delegationMapper = $this->createMock(\OCA\Keepiq\Db\SecretDelegationMapper::class);
		$logger = $this->createMock(LoggerInterface::class);

		$service = new SecretService(
			mapper: $this->mapper,
			typeService: $this->typeService,
			suiteMapper: $this->suiteMapper,
			migrationService: $this->migrationService,
			linkShareService: $this->linkShareService,
			logger: $logger,
			db: $this->createMock(\OCP\IDBConnection::class),
			viewOnlyCopies: $this->createStub(ViewOnlyCopyGuard::class),
			secretRequestService: null,
			shareService: null,
			groupShareMapper: $groupShareMapper,
			secretDelegationMapper: $delegationMapper,
		);

		$this->mapper->method('findById')->willReturn($this->makeSecret());
		$this->linkShareService->expects($this->once())->method('deleteBySecretId')->with('s-1');
		$groupShareMapper->expects($this->once())->method('deleteBySecret')->with('s-1');
		$delegationMapper->expects($this->once())->method('deleteBySecret')->with('s-1');
		$this->mapper->expects($this->once())->method('delete');

		$service->delete(id: 's-1', userId: 'alice');
	}//end testDeleteCascadesToGroupSharesAndDelegations()

	/**
	 * Fuzzy search matches an exact substring.
	 *
	 * @return void
	 */
	public function testFuzzyExactSubstring(): void {
		$this->mapper->method('searchByNameOrUrl')->willReturn([$this->makeSecret()]);
		$this->mapper->method('countByOwner')->willReturn(1);
		$this->mapper->method('findByOwner')->willReturn([$this->makeSecret()]);

		$matched = $this->service->fuzzyMatch(userId: 'alice', term: 'GitHub');
		$this->assertCount(1, $matched);
	}//end testFuzzyExactSubstring()

	/**
	 * Fuzzy search tolerates a one-character typo.
	 *
	 * @return void
	 */
	public function testFuzzyTypoDistanceOne(): void {
		// SQL pre-filter misses the typo; the Levenshtein pass must catch it.
		$this->mapper->method('searchByNameOrUrl')->willReturn([]);
		$this->mapper->method('countByOwner')->willReturn(1);
		$this->mapper->method('findByOwner')->willReturn([$this->makeSecret()]);

		$matched = $this->service->fuzzyMatch(userId: 'alice', term: 'Githb');
		$this->assertCount(1, $matched);
	}//end testFuzzyTypoDistanceOne()

	/**
	 * Fuzzy search returns nothing for an unrelated query.
	 *
	 * @return void
	 */
	public function testFuzzyNoMatch(): void {
		$this->mapper->method('searchByNameOrUrl')->willReturn([]);
		$this->mapper->method('countByOwner')->willReturn(1);
		$this->mapper->method('findByOwner')->willReturn([$this->makeSecret()]);

		$matched = $this->service->fuzzyMatch(userId: 'alice', term: 'xyzzyplugh');
		$this->assertCount(0, $matched);
	}//end testFuzzyNoMatch()

	/**
	 * The fuzzy scan is bounded: it MUST NOT ask the mapper for the user's
	 * entire vault in one call, and it MUST stop at the candidate ceiling
	 * even when the vault appears effectively inexhaustible. Regression guard
	 * for the unbounded `findByOwner($total)` bug this change fixes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bound-fuzzy-secret-search/tasks.md#task-2.1
	 */
	public function testFuzzyScanIsBoundedByCandidateCeiling(): void {
		$this->mapper->method('searchByNameOrUrl')->willReturn([]);

		// The mapper would happily return a full page forever; the service
		// must stop itself at FUZZY_SCAN_MAX_CANDIDATES.
		$requestedLimits = [];
		$fullPage = array_map(
			fn (int $i): Secret => $this->makeSecret(id: 'row-' . $i),
			range(1, SecretService::FUZZY_SCAN_PAGE_SIZE)
		);

		$this->mapper->method('findByOwner')->willReturnCallback(
			function (...$args) use (&$requestedLimits, $fullPage): array {
				// Signature: ownerType, ownerId, folderId, sort, direction, limit, offset.
				$requestedLimits[] = $args[5];
				return $fullPage;
			}
		);
		// countByOwner must NOT be used to drive an unbounded load anymore.
		$this->mapper->method('countByOwner')->willReturn(1000000);

		$this->service->fuzzyMatch(userId: 'alice', term: 'zzzznomatch');

		// Every page request used the fixed page size, never a full-vault limit.
		$this->assertNotEmpty($requestedLimits);
		foreach ($requestedLimits as $limit) {
			$this->assertSame(SecretService::FUZZY_SCAN_PAGE_SIZE, $limit);
		}

		// Total rows scanned never exceeds the candidate ceiling.
		$pagesScanned = count($requestedLimits);
		$this->assertLessThanOrEqual(
			SecretService::FUZZY_SCAN_MAX_CANDIDATES,
			($pagesScanned * SecretService::FUZZY_SCAN_PAGE_SIZE)
		);
	}//end testFuzzyScanIsBoundedByCandidateCeiling()

	/**
	 * A term that only matches on a row past the first scanned page is still
	 * found within the candidate ceiling (bounded, but not broken).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bound-fuzzy-secret-search/tasks.md#task-2.2
	 */
	public function testFuzzyFindsMatchOnLaterPage(): void {
		$this->mapper->method('searchByNameOrUrl')->willReturn([]);
		$this->mapper->method('countByOwner')->willReturn(SecretService::FUZZY_SCAN_PAGE_SIZE + 1);

		// First page: 200 non-matching rows. Second page: the matching row.
		$filler = array_map(
			fn (int $i): Secret => $this->makeNamedSecret(id: 'filler-' . $i, name: 'Unrelated' . $i),
			range(1, SecretService::FUZZY_SCAN_PAGE_SIZE)
		);
		$target = $this->makeNamedSecret(id: 'target', name: 'GitHub');

		$this->mapper->method('findByOwner')->willReturnCallback(
			function (...$args) use ($filler, $target): array {
				$offset = $args[6];
				if ($offset === 0) {
					return $filler;
				}

				if ($offset === SecretService::FUZZY_SCAN_PAGE_SIZE) {
					return [$target];
				}

				return [];
			}
		);

		$matched = $this->service->fuzzyMatch(userId: 'alice', term: 'Githb');

		$ids = array_map(static fn (Secret $s): string => $s->getId(), $matched);
		$this->assertContains('target', $ids);
	}//end testFuzzyFindsMatchOnLaterPage()

	/**
	 * An empty search term returns an empty result set.
	 *
	 * @return void
	 */
	public function testSearchEmptyTerm(): void {
		$result = $this->service->search(userId: 'alice', term: '   ', page: 1, limit: 50);
		$this->assertSame(0, $result['total']);
		$this->assertSame([], $result['items']);
	}//end testSearchEmptyTerm()

	/**
	 * createForApplication resolves the application's active suite and persists
	 * the row with owner_type=application.
	 *
	 * @return void
	 */
	public function testCreateForApplicationPersistsApplicationOwnedSecret(): void {
		$suite = $this->makeSuite(status: 'active', id: 'app-suite-1');
		$this->suiteMapper->expects($this->once())
			->method('findActiveByOwner')
			->with('application', 'app-1')
			->willReturn($suite);

		$this->typeService->method('resolveTypeForSecret')->willReturn('type-default');

		$captured = null;
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(
				static function (Secret $entity) use (&$captured) {
					$captured = $entity;
					return $entity;
				}
			);

		$result = $this->service->createForApplication(
			data: ['name' => 'PROD-DB', 'key' => 'cipher'],
			applicationId: 'app-1',
			writingUserId: 'alice',
		);

		$this->assertSame($captured, $result);
		$this->assertSame('application', $result->getOwnerType());
		$this->assertSame('app-1', $result->getOwnerId());
		$this->assertSame('app-suite-1', $result->getEncryptionSuiteId());
		$this->assertSame('PROD-DB', $result->getName());
	}//end testCreateForApplicationPersistsApplicationOwnedSecret()

	/**
	 * createForApplication blocks when the application has no active suite.
	 *
	 * @return void
	 */
	public function testCreateForApplicationRejectsMissingSuite(): void {
		$this->suiteMapper->expects($this->once())
			->method('findActiveByOwner')
			->willThrowException(new DoesNotExistException('no suite'));

		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(SuiteBlockedException::class);
		$this->expectExceptionMessage('No active EncryptionSuite for application app-1');

		$this->service->createForApplication(
			data: ['name' => 'X', 'key' => 'cipher'],
			applicationId: 'app-1',
			writingUserId: 'alice',
		);
	}//end testCreateForApplicationRejectsMissingSuite()

	/**
	 * An update based on an older version changes nothing and reports the
	 * current row; a matching base or no base updates as before
	 * (offline-edit-queue).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	public function testUpdateWithAStaleBaseChangesNothing(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);
		$stored = $this->makeSecret();
		$stored->setName('Router');
		$stored->setUpdatedAt(new \DateTime('2026-10-02T10:00:00+00:00'));
		$this->mapper->method('findById')->willReturn($stored);
		$this->mapper->expects($this->never())->method('update');

		try {
			$this->service->update(
				id: 's-1',
				data: ['name' => 'Offline name', 'baseUpdatedAt' => '2026-10-01T09:00:00+00:00'],
				userId: 'alice',
			);
			$this->fail('A stale base must be refused');
		} catch (\OCA\Keepiq\Exception\StaleWriteException $e) {
			$this->assertSame($stored, $e->getCurrent());
		}

		$this->assertSame('Router', $stored->getName());
	}//end testUpdateWithAStaleBaseChangesNothing()

	/**
	 * A matching base (in any time zone) and an absent base both update.
	 *
	 * @return void
	 */
	public function testUpdateWithAMatchingOrAbsentBaseUpdates(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);
		$stored = $this->makeSecret();
		$stored->setName('Router');
		$stored->setUpdatedAt(new \DateTime('2026-10-02T10:00:00+00:00'));
		$this->mapper->method('findById')->willReturn($stored);
		$this->mapper->expects($this->exactly(2))->method('update')->willReturnArgument(0);
		$locks = [];
		$this->mapper->expects($this->exactly(2))->method('advanceVersion')->willReturnCallback(
			static function (string $id, \DateTime $base, \DateTime $savedAt) use (&$locks): int {
				$locks[] = [$base->getTimestamp(), $savedAt->getTimestamp()];
				return 1;
			}
		);

		$this->service->update(
			id: 's-1',
			data: ['name' => 'Same base', 'baseUpdatedAt' => '2026-10-02T12:00:00+02:00'],
			userId: 'alice',
		);
		$this->assertSame('Same base', $stored->getName());

		$this->service->update(id: 's-1', data: ['name' => 'No base'], userId: 'alice');
		$this->assertSame('No base', $stored->getName());

		// Each update is locked against the version it read.
		$this->assertSame((new \DateTime('2026-10-02T10:00:00+00:00'))->getTimestamp(), $locks[0][0]);
		$this->assertSame($locks[0][1], $locks[1][0]);
	}//end testUpdateWithAMatchingOrAbsentBaseUpdates()

	/**
	 * The update with a base and the one without it.
	 *
	 * @return array<string,array{0:array<string,string>}>
	 */
	public static function baseOrNone(): array {
		return [
			'with a base'    => [['baseUpdatedAt' => '2026-10-02T10:00:00+00:00']],
			'without a base' => [[]],
		];
	}//end baseOrNone()

	/**
	 * A save that commits after the update's read but before its write moves
	 * the row: the compare-and-set finds it gone, and the update is refused
	 * and rolled back instead of overwriting the save, whether or not the
	 * client named a base.
	 *
	 * @param array<string,string> $base The client's base, if any
	 *
	 * @return void
	 *
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('baseOrNone')]
	public function testUpdateOverARowThatMovesAfterItsReadIsRefused(array $base): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);
		$stored = $this->makeSecret();
		$stored->setName('Router');
		$stored->setUpdatedAt(new \DateTime('2026-10-02T10:00:00+00:00'));
		$this->mapper->method('findById')->willReturn($stored);
		$this->mapper->expects($this->once())->method('advanceVersion')->willReturn(0);
		$this->mapper->expects($this->never())->method('update');
		$versions = $this->createPartialMock(SecretVersionService::class, ['snapshot']);
		$versions->expects($this->never())->method('snapshot');
		$transaction = [];
		$db = $this->createMock(\OCP\IDBConnection::class);
		$db->method('beginTransaction')->willReturnCallback(static function () use (&$transaction): void {
			$transaction[] = 'begin';
		});
		$db->method('rollBack')->willReturnCallback(static function () use (&$transaction): void {
			$transaction[] = 'rollback';
		});
		$service = new SecretService(
			mapper: $this->mapper,
			typeService: $this->typeService,
			suiteMapper: $this->suiteMapper,
			migrationService: $this->migrationService,
			linkShareService: $this->linkShareService,
			logger: $this->createMock(LoggerInterface::class),
			versionService: $versions,
			db: $db,
			viewOnlyCopies: $this->createStub(ViewOnlyCopyGuard::class),
		);

		try {
			$service->update(id: 's-1', data: ['key' => 'NEW-CIPHERTEXT'] + $base, userId: 'alice');
			$this->fail('A row that moved after the read must be refused');
		} catch (\OCA\Keepiq\Exception\StaleWriteException $e) {
			$this->assertSame('s-1', $e->getCurrent()->getId());
		}

		$this->assertSame(['begin', 'rollback'], $transaction);
	}//end testUpdateOverARowThatMovesAfterItsReadIsRefused()

	/**
	 * An update without a base still moves the row past its stored version,
	 * even within the same second, so an edit made on that version cannot
	 * pass the stale check afterwards.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	public function testUpdateWithoutABaseMovesTheRowPastItsStoredVersion(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);
		$stored = $this->makeSecret();
		$version = (new \DateTime('+1 hour'))->getTimestamp();
		$stored->setUpdatedAt(new \DateTime('@'.$version));
		$this->mapper->method('findById')->willReturn($stored);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->mapper->expects($this->once())->method('advanceVersion')
			->with('s-1', $this->anything(), $this->callback(static fn (\DateTime $at): bool => $at->getTimestamp() === $version + 1))
			->willReturn(1);

		$secret = $this->service->update(id: 's-1', data: ['name' => 'No base'], userId: 'alice');

		$this->assertSame($version + 1, $secret->getUpdatedAt()?->getTimestamp());
	}//end testUpdateWithoutABaseMovesTheRowPastItsStoredVersion()

	/**
	 * A share-save gives the row the version it already locked in, not "now".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	public function testUpdateTakesTheVersionAShareSaveLockedIn(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);
		$this->mapper->method('findById')->willReturn($this->makeSecret());
		$this->mapper->method('update')->willReturnArgument(0);
		$this->mapper->expects($this->never())->method('advanceVersion');
		$savedAt = new \DateTime('2030-01-01T00:00:01+00:00');

		$secret = $this->service->update(id: 's-1', data: ['key' => 'NEW-CIPHERTEXT'], userId: 'alice', savedAt: $savedAt);

		$this->assertSame($savedAt->getTimestamp(), $secret->getUpdatedAt()?->getTimestamp());
	}//end testUpdateTakesTheVersionAShareSaveLockedIn()

	/**
	 * Setting an expiry moves the row off the version it read to one after
	 * it, even when the stored version sits ahead of now, so the version
	 * never goes back.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	public function testSetExpiryMovesTheRowPastItsStoredVersion(): void {
		$stored = $this->makeSecret();
		$version = (new \DateTime('+1 hour'))->getTimestamp();
		$stored->setUpdatedAt(new \DateTime('@'.$version));
		$this->mapper->method('findById')->willReturn($stored);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->mapper->expects($this->once())->method('advanceVersion')
			->with(
				's-1',
				$this->callback(static fn (\DateTime $at): bool => $at->getTimestamp() === $version),
				$this->callback(static fn (\DateTime $at): bool => $at->getTimestamp() === $version + 1)
			)
			->willReturn(1);
		$expiry = new \DateTime('2027-01-01T00:00:00+00:00');

		$secret = $this->service->setExpiry(id: 's-1', expiresAt: $expiry, userId: 'alice');

		$this->assertSame($version + 1, $secret->getUpdatedAt()?->getTimestamp());
		$this->assertSame($expiry, $secret->getExpiresAt());
	}//end testSetExpiryMovesTheRowPastItsStoredVersion()

	/**
	 * A save that lands between the expiry's read and its write keeps its
	 * value, and the expiry is set once more on the row as it is now.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	public function testSetExpiryOverARowThatMovesIsSetOnTheCurrentRow(): void {
		$read = $this->makeSecret();
		$read->setUpdatedAt(new \DateTime('2026-10-02T10:00:00+00:00'));
		$moved = $this->makeSecret();
		$moved->setKey('SAVED-MEANWHILE');
		$moved->setUpdatedAt(new \DateTime('2026-10-02T10:00:01+00:00'));
		$this->mapper->method('findById')->willReturnOnConsecutiveCalls($read, $moved);
		$bases = [];
		$this->mapper->expects($this->exactly(2))->method('advanceVersion')->willReturnCallback(
			static function (string $id, \DateTime $base) use (&$bases): int {
				$bases[] = $base->getTimestamp();
				return (count($bases) === 1) ? 0 : 1;
			}
		);
		$this->mapper->expects($this->once())->method('update')->with($this->identicalTo($moved))->willReturnArgument(0);
		$expiry = new \DateTime('2027-01-01T00:00:00+00:00');

		$secret = $this->service->setExpiry(id: 's-1', expiresAt: $expiry, userId: 'alice');

		$this->assertSame($moved, $secret);
		$this->assertSame('SAVED-MEANWHILE', $secret->getKey());
		$this->assertSame($expiry, $secret->getExpiresAt());
		$this->assertSame([$read->getUpdatedAt()?->getTimestamp(), (new \DateTime('2026-10-02T10:00:01+00:00'))->getTimestamp()], $bases);
	}//end testSetExpiryOverARowThatMovesIsSetOnTheCurrentRow()

	/**
	 * loadForUpdate refuses what update() refuses, before any write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	public function testLoadForUpdateRefusesWhatUpdateRefuses(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);
		$useOnly = $this->makeSecret();
		$useOnly->setUseOnly(true);
		$foreign = $this->makeSecret();
		$foreign->setOwnerId('mallory');
		$this->mapper->method('findById')->willReturnOnConsecutiveCalls($this->makeSecret(), $useOnly, $foreign);
		$this->mapper->expects($this->never())->method('update');

		$this->assertSame('s-1', $this->service->loadForUpdate(id: 's-1', fields: ['key'], userId: 'alice')->getId());
		foreach (['A use-only copy cannot be changed', 'Secret belongs to another user'] as $refusal) {
			try {
				$this->service->loadForUpdate(id: 's-1', fields: ['key'], userId: 'alice');
				$this->fail('Refusal expected: '.$refusal);
			} catch (ForbiddenException $e) {
				$this->assertSame($refusal, $e->getMessage());
			}
		}
	}//end testLoadForUpdateRefusesWhatUpdateRefuses()

	/**
	 * The service with a view-only guard that records the fields it is asked
	 * about and refuses any value change.
	 *
	 * @param array<int,string[]> $asked Receives the fields of each call
	 *
	 * @return SecretService
	 */
	private function serviceWithViewOnlyGuard(array &$asked): SecretService {
		$guard = $this->createMock(ViewOnlyCopyGuard::class);
		$guard->method('assertMayChangeValue')->willReturnCallback(
			static function (Secret $row, array $fields, string $userId) use (&$asked): void {
				$asked[] = $fields;
				if ($fields !== []) {
					throw new ViewOnlyCopyException();
				}
			}
		);

		return new SecretService(
			mapper: $this->mapper,
			typeService: $this->typeService,
			suiteMapper: $this->suiteMapper,
			migrationService: $this->migrationService,
			linkShareService: $this->linkShareService,
			logger: $this->createMock(LoggerInterface::class),
			db: $this->createMock(\OCP\IDBConnection::class),
			folderOwnership: $this->folderOwnership,
			viewOnlyCopies: $guard,
		);
	}//end serviceWithViewOnlyGuard()

	/**
	 * A new key, login or extra-field blob on a view-only copy is refused
	 * before anything is written (keepiq#1287).
	 *
	 * @return void
	 */
	public function testAValueChangeOfAViewOnlyCopyIsRefusedBeforeAnyWrite(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);
		$stored = $this->makeSecret();
		$stored->setLogin('LOGIN');
		$this->mapper->method('findById')->willReturn($stored);
		$this->mapper->expects($this->never())->method('update');
		$asked = [];
		$service = $this->serviceWithViewOnlyGuard(asked: $asked);

		$changes = [
			['name' => 'Renamed', 'key' => 'NEW-CIPHERTEXT'],
			['login' => null],
			['additionalFields' => 'EXTRA'],
		];
		foreach ($changes as $data) {
			try {
				$service->update(id: 's-1', data: $data, userId: 'alice');
				$this->fail('A value change of a view-only copy must be refused');
			} catch (ViewOnlyCopyException $e) {
				$this->assertSame(Secret::VIEW_ONLY_REFUSAL, $e->getMessage());
			}
		}

		$this->assertSame([['key'], ['login'], ['additionalFields']], $asked);
		$this->assertSame('GitHub', $stored->getName());
		$this->assertSame('CIPHERTEXT', $stored->getKey());
	}//end testAValueChangeOfAViewOnlyCopyIsRefusedBeforeAnyWrite()

	/**
	 * Filing and renaming a view-only copy still work, and so does an update
	 * that resends the stored value unchanged (keepiq#1287).
	 *
	 * @return void
	 */
	public function testAViewOnlyCopyIsStillFiledRenamedAndResentUnchanged(): void {
		$this->migrationService->method('isWriteLocked')->willReturn(false);
		$stored = $this->makeSecret();
		$this->mapper->method('findById')->willReturn($stored);
		$this->mapper->method('advanceVersion')->willReturn(1);
		$this->mapper->expects($this->exactly(2))->method('update')->willReturnArgument(0);
		$asked = [];
		$service = $this->serviceWithViewOnlyGuard(asked: $asked);

		$service->update(id: 's-1', data: ['folderId' => 'f-1'], userId: 'alice');
		$secret = $service->update(
			id: 's-1',
			data: ['name' => 'Renamed', 'key' => 'CIPHERTEXT', 'login' => '', 'additionalFields' => null],
			userId: 'alice'
		);

		$this->assertSame([[], []], $asked);
		$this->assertSame('f-1', $secret->getFolderId());
		$this->assertSame('Renamed', $secret->getName());
	}//end testAViewOnlyCopyIsStillFiledRenamedAndResentUnchanged()
}//end class
