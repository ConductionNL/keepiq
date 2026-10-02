<?php

/**
 * Team folder ownership policy on every secret write path
 * (admin-vault-policies §4.1): create, move and import, through the REAL
 * OrgOwnershipGuard, TeamFolderQueryService and FolderOwnershipGuard over
 * mocked mappers.
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

use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretType;
use OCA\Keepiq\Db\SecretTypeMapper;
use OCA\Keepiq\Db\TeamFolder;
use OCA\Keepiq\Db\TeamFolderMapper;
use OCA\Keepiq\Db\TeamFolderMemberMapper;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Exception\PolicyViolationException;
use OCA\Keepiq\Service\FolderOwnershipGuard;
use OCA\Keepiq\Service\FolderService;
use OCA\Keepiq\Service\ImportService;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\OrgOwnershipGuard;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\TeamFolderMembershipResolver;
use OCA\Keepiq\Service\TeamFolderQueryService;
use OCA\Keepiq\Service\VaultPolicyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * gina owns folder Private (personal) and folder Team (a team folder she
 * owns) with a child Team/Sub.
 */
class SecretServiceOrgOwnershipTest extends TestCase {

	private bool $policyOn = true;

	private string $nextType = 'type-login';

	private SecretMapper&MockObject $mapper;

	private SecretService $service;

	/**
	 * Wire the real guards.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(SecretMapper::class);
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->mapper->method('update')->willReturnArgument(0);

		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setStatus('active');
		$suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$suiteMapper->method('findActiveByOwner')->willReturn($suite);

		$typeService = $this->createMock(SecretTypeService::class);
		$typeService->method('resolveTypeForSecret')->willReturnCallback(
			fn ($typeId): string => ($typeId ?? $this->nextType)
		);
		$migrationService = $this->createMock(MigrationService::class);
		$migrationService->method('isWriteLocked')->willReturn(false);

		$folders = [
			'private' => $this->folder(id: 'private', parent: null),
			'team' => $this->folder(id: 'team', parent: null),
			'sub' => $this->folder(id: 'sub', parent: 'team'),
		];
		$folderMapper = $this->createMock(FolderMapper::class);
		$folderMapper->method('findById')->willReturnCallback(
			static fn (string $id) => ($folders[$id] ?? throw new DoesNotExistException(''))
		);

		$teamFolder = new TeamFolder();
		$teamFolder->setId('tf-team');
		$teamFolder->setFolderId('team');
		$teamFolder->setOwnerId('gina');
		$teamFolderMapper = $this->createMock(TeamFolderMapper::class);
		$teamFolderMapper->method('findByFolder')->willReturnCallback(
			static fn (string $id) => ($id === 'team' ? $teamFolder : throw new DoesNotExistException(''))
		);

		$typeMapper = $this->createMock(SecretTypeMapper::class);
		$typeMapper->method('findById')->willReturnCallback(static function (string $id): SecretType {
			$type = new SecretType();
			$type->setName(substr($id, 5));
			return $type;
		});

		$policies = $this->createMock(VaultPolicyService::class);
		$policies->method('appliesTo')->willReturnCallback(fn (): bool => $this->policyOn);
		$policies->method('ownershipTypes')->willReturn(['login', 'api_key', 'database']);

		$memberMapper = $this->createMock(TeamFolderMemberMapper::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$queries = new TeamFolderQueryService(
			mapper: $teamFolderMapper,
			memberMapper: $memberMapper,
			folderMapper: $folderMapper,
			secretMapper: $this->mapper,
			groupManager: $groupManager,
			memberships: $this->createMock(TeamFolderMembershipResolver::class),
		);

		$this->service = new SecretService(
			mapper: $this->mapper,
			typeService: $typeService,
			suiteMapper: $suiteMapper,
			migrationService: $migrationService,
			linkShareService: $this->createMock(LinkShareService::class),
			logger: $this->createMock(LoggerInterface::class),
			auditEvents: new AuditEventFactory(),
			folderOwnership: new FolderOwnershipGuard($folderMapper),
			orgOwnership: new OrgOwnershipGuard(policies: $policies, teamFolders: $queries, typeMapper: $typeMapper),
		);
	}//end setUp()

	/**
	 * A folder gina owns.
	 *
	 * @param string $id The folder id
	 * @param string|null $parent The parent id
	 *
	 * @return Folder
	 */
	private function folder(string $id, ?string $parent): Folder {
		$folder = new Folder();
		$folder->setId($id);
		$folder->setName($id);
		$folder->setOwnerType('user');
		$folder->setOwnerId('gina');
		$folder->setParentId($parent);
		return $folder;
	}//end folder()

	/**
	 * A create payload.
	 *
	 * @param string $type The type id
	 * @param string|null $folderId The folder
	 *
	 * @return array<string,mixed>
	 */
	private function payload(string $type, ?string $folderId): array {
		return ['name' => 'Bank', 'key' => 'CIPHER', 'typeId' => $type, 'folderId' => $folderId];
	}//end payload()

	/**
	 * Scenario "Personal login refused": 403 code, nothing stored.
	 *
	 * @return void
	 */
	public function testPersonalLoginIsRefused(): void {
		$this->mapper->expects($this->never())->method('insert');

		foreach (['private', null] as $folderId) {
			try {
				$this->service->create($this->payload(type: 'type-login', folderId: $folderId), 'gina');
				$this->fail('A personal login was stored');
			} catch (PolicyViolationException $e) {
				$this->assertSame('org_ownership_required', $e->policyCode);
			}
		}
	}//end testPersonalLoginIsRefused()

	/**
	 * Scenario "Exempt type stays personal".
	 *
	 * @return void
	 */
	public function testExemptTypeStaysPersonal(): void {
		$secret = $this->service->create($this->payload(type: 'type-card', folderId: 'private'), 'gina');

		$this->assertSame('private', $secret->getFolderId());
	}//end testExemptTypeStaysPersonal()

	/**
	 * A login in (a subfolder of) an owned team folder is fine.
	 *
	 * @return void
	 */
	public function testLoginInOwnedTeamFolderIsStored(): void {
		$this->assertSame('sub', $this->service->create($this->payload(type: 'type-login', folderId: 'sub'), 'gina')->getFolderId());
	}//end testLoginInOwnedTeamFolderIsStored()

	/**
	 * Moving a login out of the team folder is refused; nothing is written.
	 *
	 * @return void
	 */
	public function testMovingALoginOutIsRefused(): void {
		$secret = new Secret();
		$secret->setId('s1');
		$secret->setName('Bank');
		$secret->setOwnerType('user');
		$secret->setOwnerId('gina');
		$secret->setTypeId('type-login');
		$secret->setFolderId('team');
		$this->mapper->method('findById')->willReturn($secret);
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(PolicyViolationException::class);
		$this->service->update('s1', ['folderId' => 'private'], 'gina');
	}//end testMovingALoginOutIsRefused()

	/**
	 * With the policy off, a personal login is stored as before.
	 *
	 * @return void
	 */
	public function testPolicyOffChangesNothing(): void {
		$this->policyOn = false;

		$this->assertSame('private', $this->service->create($this->payload(type: 'type-login', folderId: 'private'), 'gina')->getFolderId());
	}//end testPolicyOffChangesNothing()

	/**
	 * Import commits through create(): a login at the root fails per item,
	 * a card is created.
	 *
	 * @return void
	 */
	public function testImportRefusesThePersonalLoginPerItem(): void {
		$import = new ImportService(
			secretService: $this->service,
			folderService: $this->createMock(FolderService::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $import->commitChunk(
			items: [
				['name' => 'Bank', 'key' => 'Q0lQSEVSVEVYVC1PTkUtQUJDREVGRw==', 'typeId' => 'type-login'],
				['name' => 'Visa', 'key' => 'Q0lQSEVSVEVYVC1UV08tQUJDREVGRw==', 'typeId' => 'type-card'],
			],
			userId: 'gina'
		);

		$this->assertSame(['failed', 'created'], array_column($result['results'], 'status'));
		$this->assertStringContainsString('team folder', $result['results'][0]['error']);
	}//end testImportRefusesThePersonalLoginPerItem()
}//end class
