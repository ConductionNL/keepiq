<?php

/**
 * Unit tests for ResealedFields.
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

use InvalidArgumentException;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Exception\FieldsNotReencryptedException;
use OCA\Keepiq\Service\ResealedFields;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the coverage check and application of re-sealed value fields.
 *
 * @covers \OCA\Keepiq\Service\ResealedFields
 * @uses   \OCA\Keepiq\Db\Secret
 * @uses   \OCA\Keepiq\Db\SecretVersion
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 * @uses   \OCA\Keepiq\Exception\FieldsNotReencryptedException
 */
class ResealedFieldsTest extends TestCase {
	private function secret(?string $totp, ?string $passkeys): Secret {
		$secret = new Secret();
		$secret->setKey('old-key');
		$secret->setLogin('old-login');
		$secret->setAdditionalFields('old-extra');
		if ($totp !== null) {
			$secret->setTotp($totp);
		}
		if ($passkeys !== null) {
			$secret->setPasskeys($passkeys);
		}

		return $secret;
	}

	public function testAssertCoversAcceptsMatchingFields(): void {
		(new ResealedFields('k', 'l', 'a', 'new-totp', null))->assertCovers($this->secret('t', null));
		(new ResealedFields('k', null, null, null, ''))->assertCovers($this->secret(null, ''));
		$this->addToAssertionCount(2);
	}

	public function testAssertCoversNamesStoredFieldsThatWereNotResealed(): void {
		try {
			(new ResealedFields('k', 'l', 'a', '', null))->assertCovers($this->secret('t', 'p'));
			$this->fail('Expected refusal');
		} catch (FieldsNotReencryptedException $e) {
			$this->assertSame('Stored value fields were not re-encrypted: totp, passkeys', $e->getMessage());
			$this->assertSame(['totp', 'passkeys'], $e->getFields());
		}
	}

	public function testAssertCoversRefusesValuesTheRecordDoesNotHold(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The record holds no value to re-encrypt for: passkeys');
		(new ResealedFields('k', 'l', 'a', null, 'p'))->assertCovers($this->secret(null, null));
	}

	public function testUnheldFieldsWinOverMissingOnes(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The record holds no value to re-encrypt for: passkeys');
		(new ResealedFields('k', 'l', 'a', null, 'p'))->assertCovers($this->secret('t', null));
	}

	public function testAssertCoversWorksOnAVersion(): void {
		$version = new SecretVersion();
		$version->setTotp('t');

		$this->expectException(FieldsNotReencryptedException::class);
		(new ResealedFields('k', null, null, null, null))->assertCovers($version);
	}

	public function testApplyToReplacesAllValueFields(): void {
		$secret = $this->secret('t', 'p');

		(new ResealedFields('k2', 'l2', 'a2', 't2', 'p2'))->applyTo($secret);

		$this->assertSame('k2', $secret->getKey());
		$this->assertSame('l2', $secret->getLogin());
		$this->assertSame('a2', $secret->getAdditionalFields());
		$this->assertSame('t2', $secret->getTotp());
		$this->assertSame('p2', $secret->getPasskeys());
	}

	public function testApplyToLeavesTotpAndPasskeysWhenNotSent(): void {
		$secret = $this->secret('t', 'p');

		(new ResealedFields('k2', null, null, '', null))->applyTo($secret);

		$this->assertSame('k2', $secret->getKey());
		$this->assertNull($secret->getLogin());
		$this->assertNull($secret->getAdditionalFields());
		$this->assertSame('t', $secret->getTotp());
		$this->assertSame('p', $secret->getPasskeys());
	}
}
