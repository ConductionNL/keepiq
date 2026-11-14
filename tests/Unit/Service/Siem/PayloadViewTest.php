<?php

/**
 * Unit tests for PayloadView.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service\Siem
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

namespace OCA\Keepiq\Tests\Unit\Service\Siem;

use OCA\Keepiq\Service\Siem\PayloadView;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PayloadView.
 *
 * @covers \OCA\Keepiq\Service\Siem\PayloadView
 */
class PayloadViewTest extends TestCase {
	/**
	 * Scalars are returned as strings and missing fields as the empty string.
	 *
	 * @return void
	 */
	public function testGetStringifiesScalarsAndDefaultsMissingFields(): void {
		$view = new PayloadView(['eventType' => 'secret.read', 'count' => 3, 'flag' => true, 'ratio' => 1.5]);

		$this->assertSame('secret.read', $view->get(field: 'eventType'));
		$this->assertSame('3', $view->get(field: 'count'));
		$this->assertSame('1', $view->get(field: 'flag'));
		$this->assertSame('1.5', $view->get(field: 'ratio'));
		$this->assertSame('', $view->get(field: 'absent'));
	}//end testGetStringifiesScalarsAndDefaultsMissingFields()

	/**
	 * Non-scalar and null values never reach a formatter as text.
	 *
	 * @return void
	 */
	public function testGetRefusesNonScalarValues(): void {
		$view = new PayloadView(['actorId' => ['alice'], 'objectId' => null, 'objectType' => new \stdClass()]);

		$this->assertSame('', $view->get(field: 'actorId'));
		$this->assertSame('', $view->get(field: 'objectId'));
		$this->assertSame('', $view->get(field: 'objectType'));
	}//end testGetRefusesNonScalarValues()

	/**
	 * Forbidden keys are stripped from the metadata; the rest keeps its order.
	 *
	 * @return void
	 */
	public function testMetadataStripsForbiddenKeys(): void {
		$view = new PayloadView([
			'metadata' => [
				'folderId'  => 'f-1',
				'key'       => 'plain',
				'password'  => 'hunter2',
				'typeId'    => 't-1',
				'totp'      => 'otp',
				'passkeys'  => '[]',
				'ciphertext' => 'abc',
			],
		]);

		$this->assertSame(['folderId' => 'f-1', 'typeId' => 't-1'], $view->metadata());
	}//end testMetadataStripsForbiddenKeys()

	/**
	 * Missing or non-array metadata becomes an empty list.
	 *
	 * @return void
	 */
	public function testMetadataDefaultsToEmpty(): void {
		$this->assertSame([], (new PayloadView([]))->metadata());
		$this->assertSame([], (new PayloadView(['metadata' => 'key=plain']))->metadata());
		$this->assertSame([], (new PayloadView(['metadata' => null]))->metadata());
	}//end testMetadataDefaultsToEmpty()

	/**
	 * A parseable occurredAt becomes its epoch.
	 *
	 * @return void
	 */
	public function testEpochParsesOccurredAt(): void {
		$view = new PayloadView(['occurredAt' => '2026-01-02T03:04:05+00:00']);

		$this->assertSame(1767323045.0, $view->epoch());
	}//end testEpochParsesOccurredAt()

	/**
	 * An unparseable or missing occurredAt falls back to the current time.
	 *
	 * @return void
	 */
	public function testEpochFallsBackToNow(): void {
		$before = (float)time();
		$garbled = (new PayloadView(['occurredAt' => 'not a date']))->epoch();
		$missing = (new PayloadView(['occurredAt' => ['2026']]))->epoch();
		$after = (float)time();

		$this->assertGreaterThanOrEqual($before, $garbled);
		$this->assertLessThanOrEqual($after, $garbled);
		$this->assertGreaterThanOrEqual($before, $missing);
		$this->assertLessThanOrEqual($after, $missing);
	}//end testEpochFallsBackToNow()

	/**
	 * The sanitized view keeps only the known fields, in a fixed order, with metadata cleaned.
	 *
	 * @return void
	 */
	public function testSanitizedKeepsOnlyKnownFieldsInOrder(): void {
		$view = new PayloadView([
			'occurredAt' => '2026-01-02T03:04:05+00:00',
			'eventType'  => 'secret.updated',
			'extra'      => 'dropped',
			'actorId'    => 'alice',
			'metadata'   => ['changedFields' => ['login'], 'login' => 'bob'],
		]);

		$this->assertSame(
			[
				'eventType'  => 'secret.updated',
				'category'   => '',
				'actorType'  => '',
				'actorId'    => 'alice',
				'objectType' => '',
				'objectId'   => '',
				'occurredAt' => '2026-01-02T03:04:05+00:00',
				'metadata'   => ['changedFields' => ['login']],
			],
			$view->sanitized()
		);
	}//end testSanitizedKeepsOnlyKnownFieldsInOrder()
}//end class
