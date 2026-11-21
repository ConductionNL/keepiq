<?php

/**
 * SuiteReinstateGuard: a revoked suite may only be reinstated when its
 * revocation provably was not for compromise and its owner has no other
 * active suite.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\AuditEntry;
use OCA\Keepiq\Db\AuditEntryMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Exception\ReinstateRefusedException;
use OCA\Keepiq\Service\SuiteReinstateGuard;
use PHPUnit\Framework\TestCase;

/**
 * The suite reinstate guard.
 */
class SuiteReinstateGuardTest extends TestCase {
	/**
	 * A revoked suite of user anne.
	 *
	 * @return EncryptionSuite
	 */
	private function suite(): EncryptionSuite {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setOwnerType('user');
		$suite->setOwnerId('anne');
		return $suite;
	}//end suite()

	/**
	 * An audit entry of the given type and metadata.
	 *
	 * @param string $type The event type.
	 * @param string|null $metadata The metadata JSON.
	 *
	 * @return AuditEntry
	 */
	private function entry(string $type, ?string $metadata): AuditEntry {
		$entry = new AuditEntry();
		$entry->setEventType($type);
		$entry->setMetadata($metadata);
		return $entry;
	}//end entry()

	/**
	 * The error a guard over these audit entries and active-suite count refuses with, or null.
	 *
	 * @param array<int, AuditEntry>|null $entries The suite's audit entries, or null without an audit log.
	 * @param int $active The owner's other active suites.
	 *
	 * @return string|null
	 */
	private function refusal(?array $entries, int $active=0): ?string {
		$suites = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
		$suites->method('countActiveByOwner')->with('user', 'anne')->willReturn($active);
		$audit = null;
		if ($entries !== null) {
			$audit = $this->createMock(originalClassName: AuditEntryMapper::class);
			$audit->method('findByObject')->with('suite', 'suite-1')->willReturn($entries);
		}

		try {
			(new SuiteReinstateGuard(mapper: $suites, auditEntries: $audit))->assertReinstatable(suite: $this->suite());
		} catch (ReinstateRefusedException $e) {
			return $e->getError();
		}

		return null;
	}//end refusal()

	/**
	 * A suite revoked without compromise, whose owner has no active suite, may come back.
	 *
	 * @return void
	 */
	public function testAPlainRevocationMayBeReinstated(): void {
		$revoked = $this->entry(type: 'suite.revoked', metadata: '{"markCompromised":false,"reason":"lost device"}');
		$this->assertNull(actual: $this->refusal(entries: [$revoked]));
	}//end testAPlainRevocationMayBeReinstated()

	/**
	 * A suite revoked as compromised never comes back.
	 *
	 * @return void
	 */
	public function testACompromisedSuiteIsRefused(): void {
		$this->assertSame(
			expected: 'revoked_as_compromised',
			actual: $this->refusal(entries: [$this->entry(type: 'suite.revoked', metadata: '{"markCompromised":true}')])
		);
	}//end testACompromisedSuiteIsRefused()

	/**
	 * When the revocation cannot be confirmed the guard fails closed.
	 *
	 * @return void
	 */
	public function testAnUnconfirmedRevocationFailsClosed(): void {
		$this->assertSame(expected: 'revoked_as_compromised', actual: $this->refusal(entries: null));
		$this->assertSame(expected: 'revoked_as_compromised', actual: $this->refusal(entries: []));
		$this->assertSame(
			expected: 'revoked_as_compromised',
			actual: $this->refusal(entries: [$this->entry(type: 'suite.created', metadata: null), $this->entry(type: 'secret.deleted', metadata: '{}')])
		);
	}//end testAnUnconfirmedRevocationFailsClosed()

	/**
	 * A revocation whose metadata cannot be read does not show it was harmless, so it is refused.
	 *
	 * @return void
	 */
	public function testAnUnreadableRevocationFailsClosed(): void {
		$this->assertSame(expected: 'revoked_as_compromised', actual: $this->refusal(entries: [$this->entry(type: 'suite.revoked', metadata: 'not json')]));
		$this->assertSame(expected: 'revoked_as_compromised', actual: $this->refusal(entries: [$this->entry(type: 'suite.revoked', metadata: null)]));
	}//end testAnUnreadableRevocationFailsClosed()

	/**
	 * An owner who already has an active suite cannot get a second one back.
	 *
	 * @return void
	 */
	public function testAnOwnerWithAnActiveSuiteIsRefused(): void {
		$this->assertSame(
			expected: 'owner_has_active_suite',
			actual: $this->refusal(entries: [$this->entry(type: 'suite.revoked', metadata: '{"markCompromised":false}')], active: 1)
		);
	}//end testAnOwnerWithAnActiveSuiteIsRefused()
}//end class
