<?php

/**
 * Keepiq Application Secrets Controller
 *
 * Bearer-authenticated machine secret-store surface for a registered
 * application. Returns the application's own secrets as the versioned
 * `doriath-machine-secret-v1` ciphertext envelope (the server never sees
 * plaintext, per ADR-003), supports name-addressable retrieval, ETag /
 * `updated_since` rotation polling, and client-encrypted write-back. The
 * Application entity is injected by JwtAuthMiddleware after the
 * Authorization header has been validated; every query is keyed by that
 * application's id so cross-vault access is structurally impossible.
 *
 * No delete route exists on this surface — deletion stays a human /
 * administrative operation (application-mgmt cascade); a compromised
 * 5-minute bearer token being able to destroy credentials is a worse
 * failure mode than being able to overwrite them (overwrites are visible
 * via updatedAt and the audit trail).
 *
 * @category Controller
 * @package  OCA\Keepiq\Controller
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

namespace OCA\Keepiq\Controller;

use DateTime;
use Exception;
use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application as KeepiqApp;
use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\MachineSecretEnvelopeService;
use OCA\Keepiq\Service\MachineSecretResponseService;
use OCA\Keepiq\Service\SecretService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Machine secret-store API for Bearer-authenticated applications.
 *
 * Endpoints (all `#[PublicPage]` so the request reaches the controller;
 * JwtAuthMiddleware enforces the Bearer token before any handler runs):
 *
 * - `GET    /api/v1/app/secrets`               — list (optional `updated_since`).
 * - `GET    /api/v1/app/secrets/{id}`          — fetch by id (envelope, ETag/304).
 * - `GET    /api/v1/app/secrets/by-name/{name}`— fetch by name (404 / envelope / 409).
 * - `POST   /api/v1/app/secrets`               — create (client-encrypted).
 * - `PUT    /api/v1/app/secrets/{id}`          — replace ciphertext (client-encrypted).
 *
 * Responses contain ciphertext only — the calling application decrypts
 * with its private key.
 *
 * The single-secret answer itself — lease policy, ETag/304 negotiation, the
 * retrieval audit event and the envelope body — is built by
 * MachineSecretResponseService, which three of these endpoints share.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Thin transport layer that
 *   wires the mappers, the envelope serializer, and the write service; the
 *   coupling is the controller's whole job.
 */
class ApplicationSecretsController extends ApplicationApiController {
	/**
	 * Constructor for ApplicationSecretsController.
	 *
	 * @param IRequest $request The HTTP request
	 * @param SecretMapper $secretMapper The secret mapper
	 * @param FolderMapper $folderMapper The folder mapper
	 * @param SecretService $secretService The secret write service
	 * @param MachineSecretEnvelopeService $envelopeService The envelope serializer
	 * @param MachineSecretResponseService $responseService The single-secret response builder
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private SecretMapper $secretMapper,
		private FolderMapper $folderMapper,
		private SecretService $secretService,
		private MachineSecretEnvelopeService $envelopeService,
		private MachineSecretResponseService $responseService,
	) {
		parent::__construct(appName: KeepiqApp::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List the calling application's secrets as envelopes.
	 *
	 * Accepts an optional `updated_since` (ISO 8601) query: only secrets
	 * updated strictly after that instant are returned, so a connector can
	 * detect rotated credentials with one cheap indexed call per poll.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function index(): JSONResponse {
		$application = $this->getApplication();
		if ($application === null) {
			return $this->unauthorized();
		}

		$since = null;
		$updatedSinceRaw = $this->request->getParam('updated_since');
		if ($updatedSinceRaw !== null && $updatedSinceRaw !== '') {
			$since = $this->parseIso8601(value: (string)$updatedSinceRaw);
			if ($since === null) {
				return new JSONResponse(
					data: ['message' => 'updated_since must be a valid ISO 8601 timestamp'],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}
		}

		$secrets = $this->fetchOwnedSecrets(applicationId: $application->getId(), since: $since);

		return new JSONResponse(
			data: [
				'format' => MachineSecretEnvelopeService::FORMAT,
				'items' => array_map(
					fn (Secret $secret) => $this->envelopeService->serialize($secret),
					$secrets
				),
				'total' => count($secrets),
			]
		);
	}//end index()

	/**
	 * The application's secrets, optionally narrowed to those updated
	 * strictly after $since. Exactly one query runs either way.
	 *
	 * @param string $applicationId The owning application's ID
	 * @param DateTime|null $since Lower bound, or null for all rows
	 *
	 * @return Secret[]
	 */
	private function fetchOwnedSecrets(string $applicationId, ?DateTime $since): array {
		if ($since !== null) {
			return $this->secretMapper->findByOwnerUpdatedSince(
				ownerType: 'application',
				ownerId: $applicationId,
				since: $since
			);
		}

		return $this->secretMapper->findByOwner(
			ownerType: 'application',
			ownerId: $applicationId
		);
	}//end fetchOwnedSecrets()

	/**
	 * Fetch one of the calling application's secrets by id.
	 *
	 * Returns the `doriath-machine-secret-v1` envelope with a strong ETag;
	 * an `If-None-Match` that matches yields 304 with no body. A secret
	 * owned by another vault returns the SAME 404 as a nonexistent id (no
	 * existence oracle).
	 *
	 * @param string $id The secret ID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function show(string $id): JSONResponse {
		$application = $this->getApplication();
		if ($application === null) {
			return $this->unauthorized();
		}

		$secret = $this->loadOwnedOrNull(id: $id, applicationId: $application->getId());
		if ($secret === null) {
			return $this->notFound();
		}

		return $this->responseService->envelope(secret: $secret, applicationId: $application->getId());
	}//end show()

	/**
	 * Resolve one of the calling application's secrets by exact name.
	 *
	 * Exact, case-sensitive name match within the application's vault,
	 * optionally constrained by a `folder` slash-path query. Zero matches
	 * → 404; exactly one → the envelope (with ETag/304); multiple → 409
	 * with a candidate list `{id, name, folderPath, updatedAt}`. The
	 * system never silently picks one of several same-named secrets.
	 *
	 * @param string $name The exact secret name
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function byName(string $name): JSONResponse {
		$application = $this->getApplication();
		if ($application === null) {
			return $this->unauthorized();
		}

		$folderPath = $this->request->getParam('folder');
		$folderId = null;
		if ($folderPath !== null && $folderPath !== '') {
			$folderId = $this->resolveFolderId(
				path: (string)$folderPath,
				applicationId: $application->getId()
			);
			if ($folderId === null) {
				// Folder does not exist in this vault → no secret can match.
				return $this->notFound();
			}
		}

		$matches = $this->secretMapper->findByName(
			ownerType: 'application',
			ownerId: $application->getId(),
			name: $name,
			folderId: $folderId
		);

		if (count($matches) === 0) {
			return $this->notFound();
		}

		if (count($matches) > 1) {
			return new JSONResponse(
				data: [
					'message' => 'Multiple secrets match this name; disambiguate by folder or rename',
					'candidates' => array_map(
						fn (Secret $secret) => $this->envelopeService->candidate($secret),
						$matches
					),
				],
				statusCode: Http::STATUS_CONFLICT
			);
		}

		return $this->responseService->envelope(secret: $matches[0], applicationId: $application->getId());
	}//end byName()

	/**
	 * Create a secret in the calling application's vault (write-back).
	 *
	 * Accepts plaintext-safe metadata plus fields the application already
	 * encrypted with its own public certificate. The server validates the
	 * shape only — it can never inspect the plaintext.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function create(): JSONResponse {
		$application = $this->getApplication();
		if ($application === null) {
			return $this->unauthorized();
		}

		try {
			$secret = $this->secretService->createByApplication(
				data: $this->writePayload(),
				applicationId: $application->getId()
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		} catch (Exception $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse(
			data: $this->envelopeService->serialize($secret),
			statusCode: Http::STATUS_CREATED
		);
	}//end create()

	/**
	 * Replace the ciphertext of one of the calling application's secrets.
	 *
	 * The new value arrives pre-encrypted with the application's own
	 * certificate. PUT advances updatedAt (and keyUpdatedAt when the key
	 * blob changes). A secret owned by another vault returns 404,
	 * indistinguishable from a nonexistent id.
	 *
	 * @param string $id The secret ID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function update(string $id): JSONResponse {
		$application = $this->getApplication();
		if ($application === null) {
			return $this->unauthorized();
		}

		try {
			$secret = $this->secretService->updateByApplication(
				id: $id,
				data: $this->writePayload(),
				applicationId: $application->getId()
			);
		} catch (NotFoundException) {
			return $this->notFound();
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return $this->responseService->envelope(secret: $secret, applicationId: $application->getId());
	}//end update()

	/**
	 * Load a secret only when it is owned by the given application, else
	 * null — the caller maps null to a 404, never an existence-revealing
	 * 403.
	 *
	 * @param string $id The secret ID
	 * @param string $applicationId The owning application id
	 *
	 * @return Secret|null
	 */
	private function loadOwnedOrNull(string $id, string $applicationId): ?Secret {
		try {
			$secret = $this->secretMapper->findById($id);
		} catch (DoesNotExistException) {
			return null;
		}

		if ($secret->getOwnerType() !== 'application'
			|| $secret->getOwnerId() !== $applicationId
		) {
			return null;
		}

		return $secret;
	}//end loadOwnedOrNull()

	/**
	 * Resolve a slash-separated folder path to a folder id within the
	 * application's vault, or null when no such folder exists.
	 *
	 * @param string $path The slash-separated folder path
	 * @param string $applicationId The owning application id
	 *
	 * @return string|null
	 */
	private function resolveFolderId(string $path, string $applicationId): ?string {
		$normalized = trim($path, '/');
		if ($normalized === '') {
			return null;
		}

		$folders = $this->folderMapper->findByOwner(
			ownerType: 'application',
			ownerId: $applicationId
		);

		foreach ($folders as $folder) {
			if ($folder instanceof Folder
				&& $this->folderMapper->getPath($folder->getId()) === $normalized
			) {
				return $folder->getId();
			}
		}

		return null;
	}//end resolveFolderId()

	/**
	 * Collect the plaintext-safe metadata + ciphertext fields a write
	 * request may carry. The server treats every field opaquely.
	 *
	 * @return array<string,mixed>
	 */
	private function writePayload(): array {
		$payload = [];
		foreach (['name', 'url', 'typeId', 'folderId', 'key', 'login', 'additionalFields'] as $field) {
			$value = $this->request->getParam($field);
			if ($value !== null) {
				$payload[$field] = $value;
			}
		}

		return $payload;
	}//end writePayload()

	/**
	 * Parse a strict ISO 8601 timestamp, returning null on any malformed
	 * input.
	 *
	 * @param string $value The raw timestamp
	 *
	 * @return DateTime|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DateTime::createFromFormat() is a
	 * named constructor on a PHP built-in: there is no instance API for
	 * format-strict parsing (an instance would already have to exist), and no
	 * Nextcloud/OCP abstraction offers one — ITimeFactory only produces "now".
	 * Wrapping one call in a bespoke adapter would be disproportionate.
	 */
	private function parseIso8601(string $value): ?DateTime {
		$parsed = DateTime::createFromFormat(DateTime::ATOM, $value);
		if ($parsed instanceof DateTime) {
			return $parsed;
		}

		// Accept the common 'Z' / fractional-second variants too.
		try {
			return new DateTime($value);
		} catch (Exception) {
			return null;
		}
	}//end parseIso8601()

	/**
	 * 401 response for a missing/invalid Bearer token (defence in depth —
	 * the middleware normally rejects first).
	 *
	 * @return JSONResponse
	 */
	private function unauthorized(): JSONResponse {
		return new JSONResponse(
			data: ['message' => 'Bearer token required'],
			statusCode: Http::STATUS_UNAUTHORIZED
		);
	}//end unauthorized()

	/**
	 * 404 response — the single shape for both nonexistent and
	 * cross-vault secrets (no existence oracle).
	 *
	 * @return JSONResponse
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(
			data: ['message' => 'Secret not found'],
			statusCode: Http::STATUS_NOT_FOUND
		);
	}//end notFound()
}//end class
