<?php

/**
 * Golden test for findById of the mappers of string-keyed tables.
 *
 * For every such mapper it records the query builder calls findById makes,
 * the entity it returns for one row and the error for none or two rows.
 * The connection, builder and result are recorders. Compared with
 * golden/find-by-string-id.json; KEEPIQ_RECORD_GOLDEN=1 rewrites that file.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
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

namespace OCA\Keepiq\Tests\Unit\Db;

use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Tests for findById of the mappers of string-keyed tables.
 *
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @covers \OCA\Keepiq\Db\DashboardSettingMapper
 * @covers \OCA\Keepiq\Db\SecretDelegationMapper
 * @covers \OCA\Keepiq\Db\TeamFolderMapper
 * @covers \OCA\Keepiq\Db\ApplicationMapper
 * @covers \OCA\Keepiq\Db\AttachmentMapper
 * @covers \OCA\Keepiq\Db\ComplianceReportMapper
 * @covers \OCA\Keepiq\Db\DeviceApprovalMapper
 * @covers \OCA\Keepiq\Db\EmergencyContactMapper
 * @covers \OCA\Keepiq\Db\EncryptionSuiteMapper
 * @covers \OCA\Keepiq\Db\EphemeralSendMapper
 * @covers \OCA\Keepiq\Db\ExpiryPolicyMapper
 * @covers \OCA\Keepiq\Db\FederatedInboundMapper
 * @covers \OCA\Keepiq\Db\FederatedShareMapper
 * @covers \OCA\Keepiq\Db\FederationPartnerMapper
 * @covers \OCA\Keepiq\Db\FolderMapper
 * @covers \OCA\Keepiq\Db\HoneyAlertMapper
 * @covers \OCA\Keepiq\Db\LinkShareMapper
 * @covers \OCA\Keepiq\Db\MachineLeaseMapper
 * @covers \OCA\Keepiq\Db\PasskeyMapper
 * @covers \OCA\Keepiq\Db\RecoveryApprovalMapper
 * @covers \OCA\Keepiq\Db\RecoveryEnrolmentMapper
 * @covers \OCA\Keepiq\Db\RecoveryKeyMapper
 * @covers \OCA\Keepiq\Db\RecoveryOfficerMapper
 * @covers \OCA\Keepiq\Db\RecoveryRequestMapper
 * @covers \OCA\Keepiq\Db\RotationFlagMapper
 * @covers \OCA\Keepiq\Db\SecretMapper
 * @covers \OCA\Keepiq\Db\SecretRequestMapper
 * @covers \OCA\Keepiq\Db\TeamFolderMemberMapper
 * @covers \OCA\Keepiq\Db\AttachmentGrantMapper
 * @covers \OCA\Keepiq\Db\SecretTypeMapper
 * @covers \OCA\Keepiq\Db\GroupShareMapper
 * @covers \OCA\Keepiq\Db\SiemSinkMapper
 * @covers \OCA\Keepiq\Db\SuiteMigrationMapper
 * @covers \OCA\Keepiq\Db\SecretVersionMapper
 * @uses   \OCA\Keepiq\Db\DashboardSetting
 * @uses   \OCA\Keepiq\Db\SecretDelegation
 * @uses   \OCA\Keepiq\Db\TeamFolder
 * @uses   \OCA\Keepiq\Db\Application
 * @uses   \OCA\Keepiq\Db\Attachment
 * @uses   \OCA\Keepiq\Db\ComplianceReport
 * @uses   \OCA\Keepiq\Db\DeviceApproval
 * @uses   \OCA\Keepiq\Db\EmergencyContact
 * @uses   \OCA\Keepiq\Db\EncryptionSuite
 * @uses   \OCA\Keepiq\Db\EphemeralSend
 * @uses   \OCA\Keepiq\Db\ExpiryPolicy
 * @uses   \OCA\Keepiq\Db\FederatedInbound
 * @uses   \OCA\Keepiq\Db\FederatedShare
 * @uses   \OCA\Keepiq\Db\FederationPartner
 * @uses   \OCA\Keepiq\Db\Folder
 * @uses   \OCA\Keepiq\Db\HoneyAlert
 * @uses   \OCA\Keepiq\Db\LinkShare
 * @uses   \OCA\Keepiq\Db\MachineLease
 * @uses   \OCA\Keepiq\Db\RecoveryApproval
 * @uses   \OCA\Keepiq\Db\RecoveryEnrolment
 * @uses   \OCA\Keepiq\Db\RecoveryKey
 * @uses   \OCA\Keepiq\Db\RecoveryOfficer
 * @uses   \OCA\Keepiq\Db\RecoveryRequest
 * @uses   \OCA\Keepiq\Db\RotationFlag
 * @uses   \OCA\Keepiq\Db\Secret
 * @uses   \OCA\Keepiq\Db\SecretRequest
 * @uses   \OCA\Keepiq\Db\TeamFolderMember
 * @uses   \OCA\Keepiq\Db\AttachmentGrant
 * @uses   \OCA\Keepiq\Db\SecretType
 * @uses   \OCA\Keepiq\Db\GroupShare
 * @uses   \OCA\Keepiq\Db\SiemSink
 * @uses   \OCA\Keepiq\Db\SuiteMigration
 * @uses   \OCA\Keepiq\Db\SecretVersion
 * @uses   \OCA\Keepiq\Db\PasskeyCredential
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class FindsByStringIdGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/find-by-string-id.json';

	/**
	 * The mappers of string-keyed tables, each with 0, 1 and 2 rows answered.
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function mappers(): array {
		$out = [];
		foreach ([
			'DashboardSettingMapper',
			'SecretDelegationMapper',
			'TeamFolderMapper',
			'ApplicationMapper',
			'AttachmentMapper',
			'ComplianceReportMapper',
			'DeviceApprovalMapper',
			'EmergencyContactMapper',
			'EncryptionSuiteMapper',
			'EphemeralSendMapper',
			'ExpiryPolicyMapper',
			'FederatedInboundMapper',
			'FederatedShareMapper',
			'FederationPartnerMapper',
			'FolderMapper',
			'HoneyAlertMapper',
			'LinkShareMapper',
			'MachineLeaseMapper',
			'PasskeyMapper',
			'RecoveryApprovalMapper',
			'RecoveryEnrolmentMapper',
			'RecoveryKeyMapper',
			'RecoveryOfficerMapper',
			'RecoveryRequestMapper',
			'RotationFlagMapper',
			'SecretMapper',
			'SecretRequestMapper',
			'TeamFolderMemberMapper',
			'AttachmentGrantMapper',
			'SecretTypeMapper',
			'GroupShareMapper',
			'SiemSinkMapper',
			'SuiteMigrationMapper',
			'SecretVersionMapper',
		] as $name) {
			foreach ([0, 1, 2] as $rows) {
				$out[$name . ' ' . $rows . ' rows'] = ['OCA\\Keepiq\\Db\\' . $name, $rows];
			}
		}

		return $out;
	}//end mappers()

	/**
	 * findById matches the record.
	 *
	 * @param string $class The mapper class.
	 * @param int    $rows  How many rows the result yields.
	 *
	 * @return void
	 */
	#[DataProvider('mappers')]
	public function testFindByIdMatchesGolden(string $class, int $rows): void {
		$calls = [];
		$answers = array_slice([['id' => 'row-1'], ['id' => 'row-2']], 0, $rows);

		$result = $this->createMock(IResult::class);
		foreach (['fetch', 'fetchAssociative'] as $method) {
			$result->method($method)->willReturnCallback(function () use (&$answers, &$calls) {
				$calls[] = 'fetch';
				return array_shift($answers) ?? false;
			});
		}
		$result->method('closeCursor')->willReturnCallback(function () use (&$calls) {
			$calls[] = 'closeCursor';
			return true;
		});

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(function (...$args) use (&$calls) {
			$calls[] = ['eq', $args];
			return 'eq(' . implode(',', array_map('strval', $args)) . ')';
		});

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'setMaxResults', 'orderBy'] as $method) {
			$qb->method($method)->willReturnCallback(function (...$args) use ($method, &$calls, &$qb) {
				$calls[] = [$method, array_map(static fn ($a) => is_scalar($a) ? $a : gettype($a), $args)];
				return $qb;
			});
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(function (...$args) use (&$calls) {
			$calls[] = ['createNamedParameter', array_map(static fn ($a) => is_scalar($a) ? $a : gettype($a), $args)];
			return ':param';
		});
		$qb->method('executeQuery')->willReturnCallback(function () use (&$calls, $result) {
			$calls[] = 'executeQuery';
			return $result;
		});

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new $class($db);
		try {
			$entity = $mapper->findById('wanted-id');
			$outcome = ['entity' => get_class($entity), 'id' => $entity->getId()];
		} catch (Throwable $error) {
			$outcome = ['threw' => get_class($error)];
		}

		$trace = ['calls' => $calls, 'outcome' => $outcome];
		$golden = is_file(self::GOLDEN) ? json_decode((string)file_get_contents(self::GOLDEN), true) : [];
		$key = substr($class, strrpos($class, '\\') + 1) . ' ' . $rows;
		if (getenv('KEEPIQ_RECORD_GOLDEN') === '1') {
			$golden[$key] = $trace;
			ksort($golden);
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame($golden[$key] ?? null, $trace);
	}//end testFindByIdMatchesGolden()
}//end class
