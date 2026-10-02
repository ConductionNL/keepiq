<?php

/**
 * Guards the CLI's envelope fixture against the real envelope serializer.
 *
 * `sdk/testdata/machine_envelope.json` is what the Go CLI tests decrypt. It
 * holds an envelope written by the real MachineSecretEnvelopeService with
 * ciphertext from the real EncryptService, plus the throwaway RSA-4096 key
 * that decrypts it. This test proves the committed file is still exactly what
 * serialize() produces, so a change to the envelope shape turns this test red
 * instead of leaving the CLI parsing a shape the server no longer sends
 * (keepiq#793).
 *
 * To regenerate the fixture after an intended envelope change:
 *
 *   KEEPIQ_WRITE_CLI_FIXTURE=1 ./vendor/bin/phpunit --filter MachineEnvelopeCliFixtureTest
 *
 * then run `go test ./...` in `sdk/go/` and `cli/`.
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
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Repair\SeedSecretTypes;
use OCA\Keepiq\Service\DecryptService;
use OCA\Keepiq\Service\EncryptService;
use OCA\Keepiq\Service\MachineSecretEnvelopeService;
use PHPUnit\Framework\TestCase;

/**
 * The CLI fixture is the real serializer's output, and its ciphertext is real.
 *
 * @spec openspec/changes/apps-client-libraries-and-ci/tasks.md#1.2
 */
class MachineEnvelopeCliFixtureTest extends TestCase {

	/**
	 * The fixture the Go CLI tests read.
	 *
	 * @var string
	 */
	private const FIXTURE = __DIR__ . '/../../../sdk/testdata/machine_envelope.json';

	/**
	 * The folder id the rebuilt secret carries (the envelope only holds the path).
	 *
	 * @var string
	 */
	private const FOLDER_ID = 'fol-cli-fixture';

	/**
	 * The plaintext the fixture ciphertext holds.
	 *
	 * @var array<string,string>
	 */
	private const PLAINTEXT = [
		'key' => 'ci-fixture-db-password',
		'login' => 'ci-deployer',
		'additionalFields' => '{"host":"db.internal.test","port":"5432"}',
	];

	/**
	 * The committed fixture is exactly what serialize() writes for the secret
	 * it describes, and its ciphertext decrypts with its key through the real
	 * DecryptService.
	 *
	 * @return void
	 */
	public function testCliFixtureIsTheRealEnvelope(): void {
		if (getenv('KEEPIQ_WRITE_CLI_FIXTURE') === '1') {
			$this->writeFixture();
		}

		$this->assertFileExists(self::FIXTURE);
		$fixture = json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);

		$envelope = $fixture['envelope'];
		$secret = $this->secretFromEnvelope(envelope: $envelope);
		$service = $this->envelopeService(
			certificatePem: $fixture['certificatePem'],
			folderPath: $envelope['secret']['folderPath'],
		);

		$this->assertSame(
			$envelope,
			$service->serialize($secret),
			'sdk/testdata/machine_envelope.json no longer matches MachineSecretEnvelopeService::serialize(); '
			. 'regenerate it (see this class docblock) and run go test in sdk/go/ and cli/.'
		);

		$this->assertSame(self::PLAINTEXT, $fixture['plaintext']);
		$decrypt = new DecryptService();
		foreach (self::PLAINTEXT as $field => $plaintext) {
			$this->assertSame(
				$plaintext,
				$decrypt->rsaDecrypt($envelope['ciphertext'][$field], $fixture['privateKeyPem']),
				'ciphertext.' . $field . ' does not decrypt with the fixture key'
			);
		}

		$this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $fixture['privateKeyPem']);
	}//end testCliFixtureIsTheRealEnvelope()

	/**
	 * Build the secret an envelope describes, carrying the envelope's ciphertext.
	 *
	 * @param array<string,mixed> $envelope The envelope
	 *
	 * @return Secret
	 */
	private function secretFromEnvelope(array $envelope): Secret {
		$secret = new Secret();
		$secret->setId($envelope['secret']['id']);
		$secret->setName($envelope['secret']['name']);
		$secret->setUrl($envelope['secret']['url']);
		$secret->setTypeId($envelope['secret']['type']);
		$secret->setFolderId(self::FOLDER_ID);
		$secret->setKey($envelope['ciphertext']['key']);
		$secret->setLogin($envelope['ciphertext']['login']);
		$secret->setAdditionalFields($envelope['ciphertext']['additionalFields']);
		$secret->setEncryptionSuiteId($envelope['encryption']['suiteId']);
		$secret->setOwnerType('application');
		$secret->setOwnerId('app-cli-fixture');
		$secret->setCreatedAt(new DateTime($envelope['secret']['createdAt']));
		$secret->setUpdatedAt(new DateTime($envelope['secret']['updatedAt']));
		$secret->setKeyUpdatedAt(new DateTime($envelope['secret']['keyUpdatedAt']));
		return $secret;
	}//end secretFromEnvelope()

	/**
	 * The real envelope service over mappers that answer for the fixture suite.
	 *
	 * @param string $certificatePem The suite certificate
	 * @param string $folderPath     The folder path the folder id resolves to
	 *
	 * @return MachineSecretEnvelopeService
	 */
	private function envelopeService(string $certificatePem, string $folderPath): MachineSecretEnvelopeService {
		$suite = new EncryptionSuite();
		$suite->setCertificate($certificatePem);

		$suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$suiteMapper->method('findById')->willReturn($suite);

		$folderMapper = $this->createMock(FolderMapper::class);
		$folderMapper->method('getPath')->with(self::FOLDER_ID)->willReturn($folderPath);

		return new MachineSecretEnvelopeService(
			suiteMapper: $suiteMapper,
			folderMapper: $folderMapper,
		);
	}//end envelopeService()

	/**
	 * Write a fresh fixture: a throwaway RSA-4096 key pair and certificate,
	 * ciphertext from the real EncryptService, and the real serialize() output.
	 *
	 * @return void
	 */
	private function writeFixture(): void {
		$config = [
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
			'private_key_bits' => 4096,
			'digest_alg' => 'sha256',
		];
		$pkey = openssl_pkey_new($config);
		$this->assertNotFalse($pkey);
		openssl_pkey_export($pkey, $privateKeyPem);
		$publicKeyPem = openssl_pkey_get_details($pkey)['key'];
		$csr = openssl_csr_new(['CN' => 'keepiq-cli-fixture-test-only'], $pkey, $config);
		$cert = openssl_csr_sign($csr, null, $pkey, 3650, $config);
		openssl_x509_export($cert, $certificatePem);

		$encrypt = new EncryptService();
		$secret = new Secret();
		$secret->setId('sec-cli-fixture');
		$secret->setName('ci-fixture-db-password');
		$secret->setUrl('https://db.internal.test');
		$secret->setTypeId(SeedSecretTypes::deterministicId('database'));
		$secret->setFolderId(self::FOLDER_ID);
		$secret->setKey($encrypt->rsaEncrypt(self::PLAINTEXT['key'], $publicKeyPem));
		$secret->setLogin($encrypt->rsaEncrypt(self::PLAINTEXT['login'], $publicKeyPem));
		$secret->setAdditionalFields($encrypt->rsaEncrypt(self::PLAINTEXT['additionalFields'], $publicKeyPem));
		$secret->setEncryptionSuiteId('suite-cli-fixture');
		$secret->setOwnerType('application');
		$secret->setOwnerId('app-cli-fixture');
		$secret->setCreatedAt(new DateTime('2026-09-28T09:00:00+00:00'));
		$secret->setUpdatedAt(new DateTime('2026-09-28T09:00:00+00:00'));
		$secret->setKeyUpdatedAt(new DateTime('2026-09-28T09:00:00+00:00'));

		$service = $this->envelopeService(certificatePem: $certificatePem, folderPath: 'ci/database');
		$fixture = [
			'_comment' => 'TEST ONLY. A throwaway RSA-4096 key and the envelope MachineSecretEnvelopeService::serialize() writes for a secret encrypted to it by EncryptService. Written by tests/Unit/Service/MachineEnvelopeCliFixtureTest.php with KEEPIQ_WRITE_CLI_FIXTURE=1. The key protects nothing.',
			'privateKeyPem' => $privateKeyPem,
			'certificatePem' => $certificatePem,
			'plaintext' => self::PLAINTEXT,
			'envelope' => $service->serialize($secret),
		];

		file_put_contents(
			self::FIXTURE,
			json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
		);
	}//end writeFixture()
}//end class
