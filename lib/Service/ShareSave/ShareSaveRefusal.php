<?php

/**
 * Keepiq ShareSaveRefusal
 *
 * @category Service
 * @package  OCA\Keepiq\Service\ShareSave
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

namespace OCA\Keepiq\Service\ShareSave;

use OCP\AppFramework\Http;
use RuntimeException;

/**
 * A share-save refused before anything was written, with the `error` code
 * and HTTP status the client acts on.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
 */
class ShareSaveRefusal extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $error The machine-readable code
	 * @param string $message A message for the log, never shown as is
	 * @param int $status The HTTP status
	 * @param array<string,mixed> $details Extra body fields
	 *
	 * @return void
	 */
	public function __construct(
		private string $error,
		string $message,
		private int $status = Http::STATUS_BAD_REQUEST,
		private array $details = [],
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The machine-readable code.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	public function getError(): string {
		return $this->error;
	}//end getError()

	/**
	 * The HTTP status.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()

	/**
	 * The response body: `{error, message}` plus the details.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	public function getBody(): array {
		return ['error' => $this->error, 'message' => $this->getMessage()] + $this->details;
	}//end getBody()
}//end class
