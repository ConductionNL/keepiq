<?php

/**
 * Keepiq Machine Secret Response Service
 *
 * Turns one Secret into the HTTP answer the machine secret-store API owes for
 * it: the lease decision, the conditional-request (ETag / 304) negotiation,
 * the retrieval audit event, and finally the `doriath-machine-secret-v1`
 * envelope body.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Db\MachineLease;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;

/**
 * Builds the single-secret response for the Bearer-authenticated machine API.
 *
 * Three of the machine endpoints (`show`, `byName`, `update`) end in exactly
 * the same way: decide the lease, answer 304 when the caller's validator still
 * matches, otherwise audit the read and return the envelope. That sequence is
 * one behaviour with one set of collaborators — the lease service, the
 * envelope serializer, the request's conditional-request headers, and the
 * audit stream — so it lives here rather than being repeated, or hidden, in
 * ApplicationSecretsController.
 *
 * Zero-knowledge (ADR-003) is preserved: everything this class emits is
 * already ciphertext or public metadata.
 */
class MachineSecretResponseService {
	/**
	 * Constructor for MachineSecretResponseService.
	 *
	 * @param IRequest $request The HTTP request (validators + lease TTL)
	 * @param MachineSecretEnvelopeService $envelopeService The envelope serializer
	 * @param IEventDispatcher $eventDispatcher The event dispatcher (audit)
	 * @param LeaseService|null $leaseService The lease service (null = leases off)
	 * @param AuditEventFactory $auditEvents The audit-event factory
	 *
	 * @return void
	 */
	public function __construct(
		private IRequest $request,
		private MachineSecretEnvelopeService $envelopeService,
		private IEventDispatcher $eventDispatcher,
		private ?LeaseService $leaseService = null,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Build the envelope response for a single secret, with lease policy,
	 * ETag / 304 handling and an `application.secret_retrieved` audit event
	 * (on a full read only — a 304 dispatches nothing).
	 *
	 * @param Secret $secret The secret
	 * @param string $applicationId The calling application id (audit actor)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
	 */
	public function envelope(Secret $secret, string $applicationId): JSONResponse {
		// Machine leases (machine-secret-leases §3.1/§3.2): refuse the
		// fetch when block-on-revoke applies; otherwise grant or reuse a
		// lease (a reuse never extends) and expose it in headers. The
		// envelope body stays byte-identical to the pre-lease contract.
		if ($this->leaseService?->fetchBlocked(applicationId: $applicationId, secretId: $secret->getId()) === true) {
			return new JSONResponse(
				data: ['message' => 'Lease revoked — access to this secret is blocked until re-granted'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		$lease = $this->grantLease(applicationId: $applicationId, secretId: $secret->getId());

		$etag = $this->envelopeService->etag($secret);
		$ifNoneMatch = $this->request->getHeader('If-None-Match');
		if ($ifNoneMatch !== '' && $this->etagMatches(ifNoneMatch: $ifNoneMatch, etag: $etag) === true) {
			$response = new JSONResponse(data: [], statusCode: Http::STATUS_NOT_MODIFIED);
			$response->addHeader('ETag', $etag);
			$this->addLeaseHeaders(response: $response, lease: $lease);
			return $response;
		}

		$this->eventDispatcher->dispatchTyped(
			$this->auditEvents->forApplication(
				actorId: $applicationId,
				eventType: AuditEventTypes::APPLICATION_SECRET_RETRIEVED,
				objectType: 'secret',
				objectId: $secret->getId(),
				objectName: $secret->getName(),
			)
		);

		$response = new JSONResponse(data: $this->envelopeService->serialize($secret));
		$response->addHeader('ETag', $etag);
		$this->addLeaseHeaders(response: $response, lease: $lease);
		return $response;
	}//end envelope()

	/**
	 * Grant or reuse the machine lease covering this fetch.
	 *
	 * The requested TTL is a REQUEST, not an instruction: the lease service
	 * caps it, and a reuse never extends an existing lease.
	 *
	 * @param string $applicationId The calling application id
	 * @param string $secretId The secret being fetched
	 *
	 * @return MachineLease|null The lease, or null when leases are disabled.
	 */
	private function grantLease(string $applicationId, string $secretId): ?MachineLease {
		if ($this->leaseService === null) {
			return null;
		}

		$requestedTtlRaw = $this->request->getParam('lease_ttl');
		$requestedTtl = null;
		if ($requestedTtlRaw !== null && $requestedTtlRaw !== '') {
			$requestedTtl = (int)$requestedTtlRaw;
		}

		return $this->leaseService->grantOrReuse(
			applicationId: $applicationId,
			secretId: $secretId,
			requestedTtl: $requestedTtl,
		);

	}//end grantLease()

	/**
	 * Attach the lease id + expiry headers when a lease was granted or
	 * reused (machine-secret-leases §3.1).
	 *
	 * @param JSONResponse $response The response to mutate
	 * @param MachineLease|null $lease The lease (null = leases off)
	 *
	 * @return void
	 */
	private function addLeaseHeaders(JSONResponse $response, ?MachineLease $lease): void {
		if ($lease === null) {
			return;
		}

		// HEADER NAMES DELIBERATELY STILL SAY `Doriath-` AFTER THE RENAME.
		// These are a published wire contract, not an app id: the CLI reads
		// them (cli/internal/client/client.go) and so does every third-party
		// lease-aware machine consumer, keyed off the string. A consumer that
		// stops finding `Doriath-Lease-Id` does not error — it silently falls
		// back to the lease-unaware path and stops renewing, so the grant
		// expires mid-run with no diagnostic. Per
		// openspec/specs/secret-store-api/spec.md a breaking change to the
		// machine surface ships as a NEW apiVersion, never as an in-place
		// mutation, so renaming these belongs to that coordinated change.
		$response->addHeader('Doriath-Lease-Id', (string)$lease->getId());
		$response->addHeader('Doriath-Lease-Expires', (string)$lease->getExpiresAt()?->format('c'));

	}//end addLeaseHeaders()

	/**
	 * Match an `If-None-Match` header (possibly a comma list, possibly
	 * weak-prefixed) against the secret's strong ETag.
	 *
	 * @param string $ifNoneMatch The raw If-None-Match header
	 * @param string $etag The current strong ETag (quoted)
	 *
	 * @return bool
	 */
	private function etagMatches(string $ifNoneMatch, string $etag): bool {
		if (trim($ifNoneMatch) === '*') {
			return true;
		}

		foreach (explode(',', $ifNoneMatch) as $candidate) {
			$candidate = trim($candidate);
			// Strip an optional weak validator prefix.
			if (str_starts_with($candidate, 'W/') === true) {
				$candidate = substr($candidate, 2);
			}

			if ($candidate === $etag) {
				return true;
			}
		}

		return false;
	}//end etagMatches()
}//end class
