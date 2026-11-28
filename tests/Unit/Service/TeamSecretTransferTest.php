<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden outcomes of handing a leaver's team secrets to a successor: which
 * secrets move, the delegation each one records, and which are skipped.
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Service
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/changes/team-folder-sharing/tasks.md#2.5
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\SecretDelegation;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\TeamFolder;
use OCA\Keepiq\Db\TeamFolderMapper;
use OCA\Keepiq\Service\SecretOwnerTransferService;
use OCA\Keepiq\Service\ShareRowJudge;
use OCA\Keepiq\Service\ShareRowVerdict;
use OCA\Keepiq\Service\TeamFolderMembershipResolver;
use OCA\Keepiq\Service\TeamSecretTransferService;
use PHPUnit\Framework\TestCase;

/**
 * Team secret hand-over outcomes.
 */
class TeamSecretTransferTest extends TestCase {

	/**
	 * Dave owns two team folders with three secrets; bob holds a trusted copy
	 * of s-1 and s-3 only. Each delegation is recorded, and each transfer moves
	 * two link shares.
	 *
	 * @return void
	 */
	public function testOnlySecretsTheSuccessorHoldsAreHandedOverWithAPermanentDelegation(): void {
		$folderA = new TeamFolder();
		$folderA->setId('tf-a');
		$folderB = new TeamFolder();
		$folderB->setId('tf-b');
		$mapper = $this->createMock(TeamFolderMapper::class);
		$mapper->method('findByOwner')->with('dave')->willReturn([$folderA, $folderB]);
		$memberships = $this->createMock(TeamFolderMembershipResolver::class);
		$memberships->method('subtreeSecretRefs')->willReturnCallback(
			static fn (TeamFolder $folder): array => $folder->getId() === 'tf-a'
				? [['id' => 's-1', 'name' => 'one'], ['id' => 's-2', 'name' => 'two']]
				: [['id' => 's-3', 'name' => 'three']]
		);
		$judge = $this->createMock(ShareRowJudge::class);
		$judge->method('forPair')->willReturnCallback(
			static fn (string $sourceId, string $targetUserId): ShareRowVerdict => $targetUserId === 'bob' && $sourceId !== 's-2'
				? new ShareRowVerdict(state: ShareRowVerdict::TRUSTED, row: new ShareTarget())
				: new ShareRowVerdict(state: ShareRowVerdict::NONE)
		);
		$delegations = [];
		$delegationMapper = $this->createMock(SecretDelegationMapper::class);
		$delegationMapper->method('insert')->willReturnCallback(
			static function (SecretDelegation $delegation) use (&$delegations): SecretDelegation {
				$delegations[] = $delegation;
				return $delegation;
			}
		);
		$moved = [];
		$owners = $this->createMock(SecretOwnerTransferService::class);
		$owners->method('transfer')->willReturnCallback(
			static function (string $secretId, string $fromOwnerId, string $newOwnerId) use (&$moved, &$delegations): int {
				$moved[] = [$secretId, $fromOwnerId, $newOwnerId, count($delegations)];
				return 2;
			}
		);
		$service = new TeamSecretTransferService(
			mapper: $mapper,
			memberships: $memberships,
			rowJudge: $judge,
			delegationMapper: $delegationMapper,
			ownerTransfer: $owners,
		);

		$result = $service->transfer(leavingUserId: 'dave', successorUserId: 'bob', adminId: 'admin');

		$this->assertSame(['transferred' => 2, 'linksTransferred' => 4, 'skipped' => ['s-2']], $result);
		$this->assertSame([['s-1', 'dave', 'bob', 1], ['s-3', 'dave', 'bob', 2]], $moved);
		$this->assertCount(2, $delegations);
		foreach ($delegations as $i => $delegation) {
			$this->assertSame(
				[['s-1', 's-3'][$i], 'dave', 'bob', 'admin', true],
				[$delegation->getSecretId(), $delegation->getOriginalOwnerId(), $delegation->getDelegatedTo(), $delegation->getInitiatedBy(), $delegation->getIsPermanent()]
			);
			$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $delegation->getId());
			$this->assertNotNull($delegation->getDelegatedAt());
		}

		$this->assertNotSame($delegations[0]->getId(), $delegations[1]->getId());
	}//end testOnlySecretsTheSuccessorHoldsAreHandedOverWithAPermanentDelegation()
}//end class
