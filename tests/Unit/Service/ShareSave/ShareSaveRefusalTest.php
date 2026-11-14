<?php

/**
 * Unit tests for ShareSaveRefusal and IncompleteShareSaveException.
 *
 * The refusal is what the share-save endpoint answers with, so its error
 * code, HTTP status and body shape are pinned.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service\ShareSave;

use OCA\Keepiq\Service\ShareSave\IncompleteShareSaveException;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCP\AppFramework\Http;
use PHPUnit\Framework\TestCase;

/**
 * The refusal carries its code, status and details into one response body.
 *
 * @covers \OCA\Keepiq\Service\ShareSave\ShareSaveRefusal
 * @covers \OCA\Keepiq\Service\ShareSave\IncompleteShareSaveException
 */
class ShareSaveRefusalTest extends TestCase {

	/**
	 * Without a status the refusal is a bad request with a two-key body.
	 *
	 * @return void
	 */
	public function testARefusalDefaultsToABadRequest(): void {
		$refusal = new ShareSaveRefusal(error: 'not_allowed', message: 'You may not save this secret');

		$this->assertSame('not_allowed', $refusal->getError());
		$this->assertSame('You may not save this secret', $refusal->getMessage());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $refusal->getStatus());
		$this->assertSame(['error' => 'not_allowed', 'message' => 'You may not save this secret'], $refusal->getBody());
	}//end testARefusalDefaultsToABadRequest()

	/**
	 * Details follow the error and message, and cannot override them.
	 *
	 * @return void
	 */
	public function testDetailsCannotOverrideTheErrorOrMessage(): void {
		$refusal = new ShareSaveRefusal(
			error: 'share_scan_pending',
			message: 'Try again shortly',
			status: Http::STATUS_SERVICE_UNAVAILABLE,
			details: ['error' => 'spoofed', 'message' => 'spoofed', 'retryAfter' => 30],
		);

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $refusal->getStatus());
		$this->assertSame(
			['error' => 'share_scan_pending', 'message' => 'Try again shortly', 'retryAfter' => 30],
			$refusal->getBody()
		);
	}//end testDetailsCannotOverrideTheErrorOrMessage()

	/**
	 * An incomplete save is a conflict naming the path, the source version
	 * and the copies and partner shares the save has to cover.
	 *
	 * @return void
	 */
	public function testAnIncompleteSaveIsAConflictWithWhatToWrite(): void {
		$refusal = new IncompleteShareSaveException(
			path: 'owner',
			sourceUpdatedAt: '2026-11-13T10:00:00+00:00',
			copies: ['copy-bob', 'copy-carol'],
			federated: ['fed-1'],
		);

		$this->assertInstanceOf(ShareSaveRefusal::class, $refusal);
		$this->assertSame(Http::STATUS_CONFLICT, $refusal->getStatus());
		$this->assertSame('incomplete', $refusal->getError());
		$this->assertSame(
			[
				'error' => 'incomplete',
				'message' => 'The save does not name exactly the copies and partner shares to write',
				'path' => 'owner',
				'sourceUpdatedAt' => '2026-11-13T10:00:00+00:00',
				'copies' => ['copy-bob', 'copy-carol'],
				'federated' => ['fed-1'],
			],
			$refusal->getBody()
		);
	}//end testAnIncompleteSaveIsAConflictWithWhatToWrite()

	/**
	 * A source without a recorded version is reported as null.
	 *
	 * @return void
	 */
	public function testAnUnversionedSourceIsReportedAsNull(): void {
		$body = (new IncompleteShareSaveException(path: 'copy', sourceUpdatedAt: null, copies: [], federated: []))->getBody();

		$this->assertArrayHasKey('sourceUpdatedAt', $body);
		$this->assertNull($body['sourceUpdatedAt']);
		$this->assertSame('copy', $body['path']);
	}//end testAnUnversionedSourceIsReportedAsNull()
}//end class
