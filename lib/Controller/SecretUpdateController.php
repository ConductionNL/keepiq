<?php

/**
 * Keepiq Secret Update Controller
 *
 * `PUT /api/v1/secrets/{id}`: a partial update of one secret. Encrypted fields
 * are passed through as ciphertext; the server never decrypts them (ADR-003).
 * An offline edit names the version it was made from (`baseUpdatedAt`); when
 * the secret changed since, nothing is written and the answer is 409 with
 * the current row (offline-edit-queue).
 *
 * @category Controller
 * @package  OCA\Keepiq\Controller
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

namespace OCA\Keepiq\Controller;

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\StaleWriteException;
use OCA\Keepiq\Exception\WriteLockedException;
use OCA\Keepiq\Service\SecretService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Updates one secret.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-update-secret
 */
class SecretUpdateController extends OCSController {
	/**
	 * HTTP 423 Locked status code.
	 *
	 * @var int
	 */
	private const STATUS_LOCKED = 423;

	/**
	 * The fields a client may change; only those present in the request are
	 * forwarded, so an absent field stays as it is.
	 *
	 * @var string[]
	 */
	private const FIELDS = ['name', 'url', 'typeId', 'folderId', 'key', 'login', 'additionalFields'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object
	 * @param SecretService $secretService The secret service
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private SecretService $secretService,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Update a secret. Only the fields present in the request change: `name`,
	 * `url`, `typeId`, `folderId`, the ciphertext `key`, `login` and
	 * `additionalFields`, plus `mergedPending` (request-filled blobs the client
	 * merged, keepiq#750) and `baseUpdatedAt` (the version an offline edit was
	 * made from).
	 *
	 * @param string $id The secret ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-update-secret
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$secret = $this->secretService->update($id, $this->requestedChanges(), $user->getUID());
		} catch (StaleWriteException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage(), 'current' => $e->getCurrent()->jsonSerialize()],
				statusCode: Http::STATUS_CONFLICT
			);
		} catch (NotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (WriteLockedException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: self::STATUS_LOCKED);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}//end try

		return new JSONResponse(data: $secret->jsonSerialize());
	}//end update()

	/**
	 * The changes the request carries: each updatable field that is present
	 * (an explicit null clears it), and the merge count and base version when
	 * given.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-update-secret
	 */
	private function requestedChanges(): array {
		$data = [];
		foreach (self::FIELDS as $field) {
			$value = $this->request->getParam($field, '__unset__');
			if ($value === null) {
				$data[$field] = null;
			} else if ($value !== '__unset__') {
				$data[$field] = (string)$value;
			}
		}

		$mergedPending = $this->request->getParam('mergedPending');
		if ($mergedPending !== null) {
			$data['mergedPending'] = (int)$mergedPending;
		}

		$baseUpdatedAt = $this->request->getParam('baseUpdatedAt');
		if ($baseUpdatedAt !== null && $baseUpdatedAt !== '') {
			$data['baseUpdatedAt'] = (string)$baseUpdatedAt;
		}

		return $data;
	}//end requestedChanges()
}//end class
