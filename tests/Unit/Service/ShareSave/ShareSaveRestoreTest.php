<?php

/**
 * Unit tests for ShareSaveRestore.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service\ShareSave
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

namespace OCA\Keepiq\Tests\Unit\Service\ShareSave;

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Service\ShareSave\ShareSaveEntries;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCA\Keepiq\Service\ShareSave\ShareSaveRestore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * How a version restore splits the patch: the totp and passkeys blobs the
 * version does not change are kept from the head, blobs the version cleared
 * are cleared on the patch and every copy, and a patch that does not carry
 * the version's blob is refused.
 */
#[CoversClass(ShareSaveRestore::class)]
#[UsesClass(ShareSaveEntries::class)]
#[UsesClass(ShareSaveRefusal::class)]
#[UsesClass(Secret::class)]
#[UsesClass(SecretVersion::class)]
class ShareSaveRestoreTest extends TestCase {
	/**
	 * A version holding the given blobs.
	 *
	 * @param string|null $totp     The totp blob.
	 * @param string|null $passkeys The passkeys blob.
	 *
	 * @return SecretVersion
	 */
	private static function version(?string $totp, ?string $passkeys): SecretVersion {
		$version = new SecretVersion();
		$version->setTotp($totp);
		$version->setPasskeys($passkeys);
		return $version;
	}

	/**
	 * A head secret holding the given blobs.
	 *
	 * @param string|null $totp     The totp blob.
	 * @param string|null $passkeys The passkeys blob.
	 *
	 * @return Secret
	 */
	private static function head(?string $totp, ?string $passkeys): Secret {
		$head = new Secret();
		$head->setTotp($totp);
		$head->setPasskeys($passkeys);
		return $head;
	}

	/**
	 * Entries with one copy, one skipped copy, one federated share and one suspension.
	 *
	 * @return ShareSaveEntries
	 */
	private static function entries(): ShareSaveEntries {
		return new ShareSaveEntries(
			copies: [['secretId' => 'c-1', 'totp' => 'CT', 'passkeys' => 'CP', 'data' => 'D']],
			skipped: [['secretId' => 'c-2', 'reason' => 'no_vault_key']],
			federated: [['shareId' => 'f-1', 'data' => 'F']],
			suspend: [['shareId' => 'f-2', 'reason' => 'x']],
		);
	}

	public function testAVersionPredatingTheFieldsKeepsTheHeadBlobs(): void {
		$patch = ['data' => 'D', 'totp' => 'T', 'passkeys' => 'P'];

		[$out, $entries, $kept] = (new ShareSaveRestore())->split(
			$patch,
			self::version(null, null),
			self::entries(),
			self::head('HT', 'HP')
		);

		$this->assertSame(['data' => 'D'], $out);
		$this->assertSame(['totp' => 'HT', 'passkeys' => 'HP'], $kept);
		$this->assertSame([['secretId' => 'c-1', 'data' => 'D']], $entries->copies);
		$this->assertSame([['shareId' => 'f-1', 'data' => 'F']], $entries->federated);
		$this->assertSame([['secretId' => 'c-2', 'reason' => 'no_vault_key']], $entries->skipped);
		$this->assertSame([['shareId' => 'f-2', 'reason' => 'x']], $entries->suspend);
	}

	public function testTheHeadsOwnBlobNotResentIsKept(): void {
		[$out, , $kept] = (new ShareSaveRestore())->split(
			['data' => 'D', 'totp' => '', 'passkeys' => 'NEW'],
			self::version('HT', 'OLD'),
			self::entries(),
			self::head('HT', 'HP')
		);

		$this->assertSame(['totp' => 'HT'], $kept);
		$this->assertSame(['data' => 'D', 'passkeys' => 'NEW'], $out);
	}

	public function testAVersionBlobTheClientResendsIsWrittenAsSent(): void {
		$patch = ['data' => 'D', 'totp' => 'VT', 'passkeys' => 'VP'];

		[$out, $entries, $kept] = (new ShareSaveRestore())->split(
			$patch,
			self::version('OLD-T', 'OLD-P'),
			self::entries(),
			self::head('HT', 'HP')
		);

		$this->assertSame($patch, $out);
		$this->assertSame([], $kept);
		$this->assertSame(self::entries()->copies, $entries->copies);
		$this->assertSame(self::entries()->federated, $entries->federated);
	}

	public function testAVersionBlobMissingFromThePatchIsRefused(): void {
		try {
			(new ShareSaveRestore())->split(
				['data' => 'D', 'totp' => 'VT'],
				self::version('OLD-T', 'OLD-P'),
				self::entries(),
				self::head('HT', 'HP')
			);
			$this->fail('A restore without the version passkeys must be refused');
		} catch (ShareSaveRefusal $refusal) {
			$this->assertSame('invalid_body', $refusal->getError());
			$this->assertSame(400, $refusal->getStatus());
			$this->assertSame(
				'Invalid patch: a restore of this version must carry passkeys as the version holds it',
				$refusal->getMessage()
			);
		}
	}

	public function testAClearedVersionBlobClearsThePatchCopiesAndFederatedShares(): void {
		[$out, $entries, $kept] = (new ShareSaveRestore())->split(
			['data' => 'D', 'passkeys' => 'VP'],
			self::version('', 'OLD-P'),
			self::entries(),
			self::head('HT', 'HP')
		);

		$this->assertSame([], $kept);
		$this->assertSame(['data' => 'D', 'passkeys' => 'VP', 'totp' => ''], $out);
		$this->assertSame([['secretId' => 'c-1', 'totp' => '', 'passkeys' => 'CP', 'data' => 'D']], $entries->copies);
		$this->assertSame([['shareId' => 'f-1', 'data' => 'F', 'totp' => null]], $entries->federated);
	}

	public function testAClearedVersionBlobWithABlobInThePatchIsRefused(): void {
		$this->expectException(ShareSaveRefusal::class);
		$this->expectExceptionMessage('must carry totp as the version holds it');

		(new ShareSaveRestore())->split(
			['data' => 'D', 'totp' => 'T', 'passkeys' => 'VP'],
			self::version('', 'OLD-P'),
			self::entries(),
			self::head('HT', 'HP')
		);
	}

	public function testAClearedVersionBlobOnAnEmptyHeadIsKeptEmpty(): void {
		[$out, $entries, $kept] = (new ShareSaveRestore())->split(
			['data' => 'D', 'totp' => '', 'passkeys' => null],
			self::version('', ''),
			self::entries(),
			self::head(null, '')
		);

		$this->assertSame(['totp' => null, 'passkeys' => ''], $kept);
		$this->assertSame(['data' => 'D'], $out);
		$this->assertSame([['shareId' => 'f-1', 'data' => 'F']], $entries->federated);
	}
}
