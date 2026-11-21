<?php

/**
 * Golden test for the string primary key of the UUID-keyed entities.
 *
 * For every entity that keeps its id as a string it records what getId()
 * returns and which fields are dirty after setId() with a string, an int and
 * the same value again, and after fromRow(). Compared with
 * golden/string-primary-key.json; KEEPIQ_RECORD_GOLDEN=1 rewrites that file.
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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the string primary key of the UUID-keyed entities.
 *
 * @covers \OCA\Keepiq\Db\StringPrimaryKey
 * @covers \OCA\Keepiq\Db\AttachmentGrant
 * @covers \OCA\Keepiq\Db\Attachment
 * @covers \OCA\Keepiq\Db\ComplianceReport
 * @covers \OCA\Keepiq\Db\DashboardSetting
 * @covers \OCA\Keepiq\Db\RotationFlag
 * @covers \OCA\Keepiq\Db\ExpiryPolicy
 * @covers \OCA\Keepiq\Db\CertificateMetadata
 * @covers \OCA\Keepiq\Db\FederatedShare
 * @covers \OCA\Keepiq\Db\HoneyAlert
 * @covers \OCA\Keepiq\Db\EphemeralSend
 * @covers \OCA\Keepiq\Db\LinkShare
 * @covers \OCA\Keepiq\Db\Application
 * @covers \OCA\Keepiq\Db\SecretType
 * @covers \OCA\Keepiq\Db\ShareSaveStage
 * @covers \OCA\Keepiq\Db\RecoveryRequest
 * @covers \OCA\Keepiq\Db\EmergencyContact
 * @covers \OCA\Keepiq\Db\EncryptionSuite
 * @covers \OCA\Keepiq\Db\CACertificate
 * @covers \OCA\Keepiq\Db\RecoveryKey
 * @covers \OCA\Keepiq\Db\Folder
 * @covers \OCA\Keepiq\Db\FederatedInbound
 * @covers \OCA\Keepiq\Db\FederationPartner
 * @covers \OCA\Keepiq\Db\TeamFolderMember
 * @covers \OCA\Keepiq\Db\HoneyFlag
 * @covers \OCA\Keepiq\Db\MachineLease
 * @covers \OCA\Keepiq\Db\PasskeyCredential
 * @covers \OCA\Keepiq\Db\RecoveryEnrolment
 * @covers \OCA\Keepiq\Db\SecretDelegation
 * @covers \OCA\Keepiq\Db\SecretRequest
 * @covers \OCA\Keepiq\Db\Secret
 * @covers \OCA\Keepiq\Db\SiemQueueItem
 * @covers \OCA\Keepiq\Db\GroupShare
 * @covers \OCA\Keepiq\Db\SecretVersion
 * @covers \OCA\Keepiq\Db\RecoveryApproval
 * @covers \OCA\Keepiq\Db\DeviceApproval
 * @covers \OCA\Keepiq\Db\RecoveryOfficer
 * @covers \OCA\Keepiq\Db\SuiteMigration
 * @covers \OCA\Keepiq\Db\TeamFolder
 * @covers \OCA\Keepiq\Db\ShareTarget
 * @covers \OCA\Keepiq\Db\SiemSink
 */
class StringPrimaryKeyGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/string-primary-key.json';

	/**
	 * The UUID-keyed entity classes.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function entities(): array {
		$out = [];
		foreach ([
			'AttachmentGrant',
			'Attachment',
			'ComplianceReport',
			'DashboardSetting',
			'RotationFlag',
			'ExpiryPolicy',
			'CertificateMetadata',
			'FederatedShare',
			'HoneyAlert',
			'EphemeralSend',
			'LinkShare',
			'Application',
			'SecretType',
			'ShareSaveStage',
			'RecoveryRequest',
			'EmergencyContact',
			'EncryptionSuite',
			'CACertificate',
			'RecoveryKey',
			'Folder',
			'FederatedInbound',
			'FederationPartner',
			'TeamFolderMember',
			'HoneyFlag',
			'MachineLease',
			'PasskeyCredential',
			'RecoveryEnrolment',
			'SecretDelegation',
			'SecretRequest',
			'Secret',
			'SiemQueueItem',
			'GroupShare',
			'SecretVersion',
			'RecoveryApproval',
			'DeviceApproval',
			'RecoveryOfficer',
			'SuiteMigration',
			'TeamFolder',
			'ShareTarget',
			'SiemSink',
		] as $name) {
			$out[$name] = ['OCA\\Keepiq\\Db\\' . $name];
		}

		return $out;
	}//end entities()

	/**
	 * The id behaviour of one entity class matches the record.
	 *
	 * @param string $class The entity class.
	 *
	 * @return void
	 */
	#[DataProvider('entities')]
	public function testIdMatchesGolden(string $class): void {
		$fresh = new $class();
		$trace = ['fresh' => $fresh->getId(), 'freshDirty' => array_keys($fresh->getUpdatedFields())];

		$fresh->setId('0f9a-uuid');
		$trace['afterString'] = $fresh->getId();
		$trace['dirtyAfterString'] = array_keys($fresh->getUpdatedFields());

		$fresh->resetUpdatedFields();
		$fresh->setId('0f9a-uuid');
		$trace['dirtyAfterSame'] = array_keys($fresh->getUpdatedFields());

		$fresh->setId(42);
		$trace['afterInt'] = $fresh->getId();
		$trace['afterIntType'] = gettype($fresh->getId());

		$row = $class::fromRow(['id' => 'row-id']);
		$trace['fromRow'] = $row->getId();
		$trace['fromRowDirty'] = array_keys($row->getUpdatedFields());

		$golden = is_file(self::GOLDEN) ? json_decode((string)file_get_contents(self::GOLDEN), true) : [];
		$short = substr($class, strrpos($class, '\\') + 1);
		if (getenv('KEEPIQ_RECORD_GOLDEN') === '1') {
			$golden[$short] = $trace;
			ksort($golden);
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame($golden[$short] ?? null, $trace);
	}//end testIdMatchesGolden()
}//end class
