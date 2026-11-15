<?php

/**
 * Contract of SiemService
 *
 * The public surface of SiemService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Keepiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/keepiq
 *
 * @spec openspec/specs/app-shell/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Kernel\Contracts;

use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Event\Audit\AuditEvent;

/**
 * Contract of SiemService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SiemServiceInterface {

	/**
	 * Build the forwarding payload for an audit event by rebuilding the
	 * metadata through the whitelist — a strict subset of the sanitized
	 * audit entry (§2.1). Returns null for unknown event types.
	 *
	 * @param AuditEvent $event The dispatched audit event
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-forwarded-payload-carries-no-secret-material
	 */
	public function buildPayload(AuditEvent $event): ?array;

	/**
	 * Create a sink (§6.1).
	 *
	 * @param string $adminUid The creating admin
	 * @param array<string,mixed> $params {name, type, endpoint, tls?, hmacSecret?, categoryFilter?, queueCap?}
	 *
	 * @return SiemSink
	 *
	 * @throws \InvalidArgumentException On invalid parameters
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
	 */
	public function createSink(string $adminUid, array $params): SiemSink;

	/**
	 * Delete a sink and its queue rows.
	 *
	 * @param string $adminUid The deleting admin
	 * @param string $sinkId The sink UUID
	 *
	 * @return void
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the sink is missing
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
	 */
	public function deleteSink(string $adminUid, string $sinkId): void;

	/**
	 * Drain due rows for every enabled sink in bounded batches (§4.1).
	 *
	 * After the drain, the sinks it tried to deliver to are handed to the
	 * connection reporter, which reports the SIEM export row at most once an
	 * hour while the outcome stays the same (adopt-connection-registry). The
	 * drain runs from cron, never from a page request.
	 *
	 * @return int Rows delivered
	 *
	 * @spec openspec/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
	 */
	public function deliverDue(): int;

	/**
	 * Enqueue a payload for every enabled sink whose category filter
	 * matches, with drop-oldest backpressure at the cap (§2.3).
	 *
	 * @param array<string,mixed> $payload The forwarding payload
	 *
	 * @return int Rows enqueued
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-backpressure-and-observability
	 */
	public function enqueue(array $payload): int;

	/**
	 * All sinks (secrets never included in serialization).
	 *
	 * @return SiemSink[]
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
	 */
	public function listSinks(): array;

	/**
	 * Test-fire a sink with a synthetic payload (§6.1).
	 *
	 * @param string $adminUid The testing admin
	 * @param string $sinkId The sink UUID
	 *
	 * @return array{ok:bool, error:string|null}
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the sink is missing
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-backpressure-and-observability
	 */
	public function testSink(string $adminUid, string $sinkId): array;

	/**
	 * Update a sink; a blank HMAC secret preserves the stored one (§3.2).
	 *
	 * @param string $adminUid The updating admin
	 * @param string $sinkId The sink UUID
	 * @param array<string,mixed> $params Updatable fields
	 *
	 * @return SiemSink
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the sink is missing
	 * @throws \InvalidArgumentException On an invalid format, endpoint or connector option
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
	 */
	public function updateSink(string $adminUid, string $sinkId, array $params): SiemSink;
}//end interface
