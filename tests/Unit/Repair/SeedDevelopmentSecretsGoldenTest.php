<?php

/**
 * Golden test of the development secrets seed step.
 *
 * Records, per scenario (debug off, no suite, secrets present, no system
 * types, full seed), the folders and secrets the step inserts, the tags it
 * files, and its output and log lines, and compares them with
 * golden/seed-development-secrets.json. KEEPIQ_RECORD_GOLDEN=1 rewrites it.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Repair
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

namespace OCA\Keepiq\Tests\Unit\Repair;

use DateTime;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretTagMapper;
use OCA\Keepiq\Db\SecretType;
use OCA\Keepiq\Db\SecretTypeMapper;
use OCA\Keepiq\Repair\SeedDevelopmentSecrets;
use OCA\Keepiq\Service\EncryptService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden record of SeedDevelopmentSecrets::run().
 *
 * @covers \OCA\Keepiq\Repair\SeedDevelopmentSecrets
 * @uses   \OCA\Keepiq\Db\EncryptionSuite
 * @uses   \OCA\Keepiq\Db\Folder
 * @uses   \OCA\Keepiq\Db\Secret
 * @uses   \OCA\Keepiq\Db\SecretType
 */
class SeedDevelopmentSecretsGoldenTest extends TestCase {
	private const GOLDEN = __DIR__.'/golden/seed-development-secrets.json';

	/** @var array<int,mixed> */
	private array $log = [];

	/** @var array<string,string> */
	private array $folderNames = [];

	/**
	 * Run the step for one scenario and return what it did.
	 *
	 * @param array<string,mixed> $scenario debug, suite, existing, types
	 *
	 * @return array<int,mixed>
	 */
	private function runScenario(array $scenario): array {
		$this->log = [];
		$this->folderNames = [];

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturnCallback(
			function (string $key, bool $default) use ($scenario): bool {
				$this->log[] = ['systemValue', $key, $default];
				return $scenario['debug'];
			}
		);

		$suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$suiteMapper->method('findActiveByOwner')->willReturnCallback(
			function (string $type, string $owner) use ($scenario): EncryptionSuite {
				$this->log[] = ['findSuite', $type, $owner];
				if ($scenario['suite'] === false) {
					throw new DoesNotExistException('none');
				}

				$suite = new EncryptionSuite();
				$suite->setId('suite-1');
				$suite->setCertificate('CERT');
				return $suite;
			}
		);

		$secretMapper = $this->createMock(SecretMapper::class);
		$secretMapper->method('countByOwner')->willReturnCallback(
			function (...$args) use ($scenario): int {
				$this->log[] = array_merge(['countByOwner'], $args);
				return $scenario['existing'];
			}
		);
		$secretMapper->method('insert')->willReturnCallback(
			function (Secret $secret): Secret {
				$this->log[] = ['insertSecret', $this->describeSecret($secret)];
				return $secret;
			}
		);

		$folderMapper = $this->createMock(FolderMapper::class);
		$folderMapper->method('insert')->willReturnCallback(
			function (Folder $folder): Folder {
				$this->folderNames[$folder->getId()] = $folder->getName();
				$this->log[] = [
					'insertFolder',
					$folder->getName(),
					$folder->getParentId(),
					$folder->getOwnerType(),
					$folder->getOwnerId(),
					$folder->getCreatedAt() == $folder->getUpdatedAt(),
				];
				return $folder;
			}
		);

		$typeMapper = $this->createMock(SecretTypeMapper::class);
		$typeMapper->method('findSystemTypes')->willReturnCallback(
			function () use ($scenario): array {
				$types = [];
				foreach ($scenario['types'] as $name) {
					$type = new SecretType();
					$type->setId('type-'.$name);
					$type->setName($name);
					$types[] = $type;
				}

				return $types;
			}
		);

		$encrypt = $this->createMock(EncryptService::class);
		$encrypt->method('rsaEncrypt')->willReturnCallback(
			fn (string $plain, string $cert): string => 'enc['.$cert.']('.$plain.')'
		);

		$tags = $this->createMock(SecretTagMapper::class);
		$tags->method('replaceForSecret')->willReturnCallback(
			function (string $secretId, string $owner, array $names): void {
				$this->log[] = ['tags', $owner, $names];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message): void {
				$this->log[] = ['logger', $message];
			}
		);

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->log[] = ['output', $message];
			}
		);

		$step = new SeedDevelopmentSecrets(
			suiteMapper: $suiteMapper,
			secretMapper: $secretMapper,
			folderMapper: $folderMapper,
			typeMapper: $typeMapper,
			encryptService: $encrypt,
			config: $config,
			logger: $logger,
			tagMapper: $tags,
		);
		$step->run($output);

		return $this->log;
	}//end runScenario()

	/**
	 * The inserted secret's fields, with the folder named and the last-used
	 * moment as whole days before creation.
	 *
	 * @param Secret $secret The inserted secret
	 *
	 * @return array<string,mixed>
	 */
	private function describeSecret(Secret $secret): array {
		$used = $secret->getLastUsedAt();
		$created = $secret->getCreatedAt();
		return [
			'name'       => $secret->getName(),
			'url'        => $secret->getUrl(),
			'typeId'     => $secret->getTypeId(),
			'folder'     => ($this->folderNames[$secret->getFolderId() ?? ''] ?? $secret->getFolderId()),
			'key'        => $secret->getKey(),
			'login'      => $secret->getLogin(),
			'additional' => $secret->getAdditionalFields(),
			'suite'      => $secret->getEncryptionSuiteId(),
			'ownerType'  => $secret->getOwnerType(),
			'ownerId'    => $secret->getOwnerId(),
			'favourite'  => $secret->getIsFavourite(),
			'usedDays'   => ($used instanceof DateTime && $created instanceof DateTime) ? (int)round(($created->getTimestamp() - $used->getTimestamp()) / 86400) : null,
			'idIsUuid'   => (preg_match('/^[0-9a-f-]{36}$/', (string)$secret->getId()) === 1),
		];
	}//end describeSecret()

	/**
	 * Every scenario matches the golden record.
	 *
	 * @return void
	 */
	public function testRunMatchesGolden(): void {
		$all = ['login', 'api_key', 'database', 'ssh_key', 'certificate', 'note'];
		$scenarios = [
			'debug off'        => ['debug' => false, 'suite' => true, 'existing' => 0, 'types' => $all],
			'no suite'         => ['debug' => true, 'suite' => false, 'existing' => 0, 'types' => $all],
			'secrets present'  => ['debug' => true, 'suite' => true, 'existing' => 3, 'types' => $all],
			'one secret'       => ['debug' => true, 'suite' => true, 'existing' => 1, 'types' => $all],
			'no system types'  => ['debug' => true, 'suite' => true, 'existing' => 0, 'types' => []],
			'only login type'  => ['debug' => true, 'suite' => true, 'existing' => 0, 'types' => ['login']],
			'full seed'        => ['debug' => true, 'suite' => true, 'existing' => 0, 'types' => $all],
		];
		$got = [];
		foreach ($scenarios as $name => $scenario) {
			$got[$name] = $this->runScenario($scenario);
		}

		$json = json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))."\n";
		if (getenv('KEEPIQ_RECORD_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, $json);
		}

		$this->assertFileExists(self::GOLDEN);
		$this->assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testRunMatchesGolden()
}//end class
