<?php

/**
 * Contract of MachineSecretEnvelopeService
 *
 * The public surface of MachineSecretEnvelopeService that other components call, so they depend
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

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Kernel\AppIdentity;

/**
 * Contract of MachineSecretEnvelopeService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MachineSecretEnvelopeServiceInterface {

	/**
	 * The current envelope format identifier.
	 *
	 * STILL `doriath-` AFTER THE doriath -> keepiq RENAME, and it stays that
	 * way until the first stable release. This string is a version tag on a published
	 * wire format, not an app id: it is advertised in the discovery
	 * document's `envelopeFormats`, and every machine consumer asserts on it
	 * before attempting decryption.
	 *
	 * IT CANNOT BE DUAL-VALUED THE WAY THE AUDIENCE AND THE DISCOVERY PATH
	 * CAN. Those are inbound — the consumer offers a value and this server
	 * decides whether to honour it, so accepting a second one costs nothing
	 * and no consumer notices. This is outbound: exactly one string goes into
	 * the envelope's `format` field, and whichever one it is, every consumer
	 * pinned to the other rejects the secret. There is no server-side change
	 * that makes a flip safe.
	 *
	 * So the compatibility work is on the consumer, and the only thing this
	 * server can usefully do is say what is coming. UPCOMING_FORMAT is
	 * published in discovery so consumers can be updated to accept BOTH names
	 * ahead of time; once they do, the switch is a non-event.
	 * The v1 envelope's BYTES do not change with the name — the successor
	 * differs in identifier only.
	 *
	 * @var string
	 */
	public const FORMAT = 'doriath-machine-secret-v1';

	/**
	 * The format identifier that replaces FORMAT at the first stable release.
	 *
	 * Published, not emitted. Nothing writes this value yet; it exists so a
	 * consumer can be taught to accept it before it starts arriving.
	 *
	 * @var string
	 */
	public const UPCOMING_FORMAT = 'keepiq-machine-secret-v1';

	/**
	 * The app version in which UPCOMING_FORMAT starts being emitted.
	 *
	 * @var string
	 */
	public const UPCOMING_FORMAT_APP_VERSION = AppIdentity::PRE_STABLE_COMPAT_REMOVED_IN;

	/**
	 * Build a candidate descriptor for the 409-ambiguity response body.
	 *
	 * Contains only non-sensitive metadata an operator needs to
	 * disambiguate (rename, or use a folder-scoped reference). No
	 * ciphertext, no encryption block.
	 *
	 * @param Secret $secret The secret to describe
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
	 */
	public function candidate(Secret $secret): array;

	/**
	 * Compute the sha256 fingerprint of the DER form of a suite's
	 * certificate, prefixed `sha256:`.
	 *
	 * Lets a consumer fail fast with a clear "wrong key" error (e.g. after
	 * re-registration) instead of a bare decrypt exception. Returns null
	 * when the suite or certificate cannot be resolved.
	 *
	 * @param string $suiteId The encryption-suite id
	 *
	 * @return string|null The `sha256:<hex>` fingerprint, or null
	 *
	 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
	 */
	public function certificateFingerprint(string $suiteId): ?string;

	/**
	 * Derive a strong ETag for a single secret.
	 *
	 * The tag changes whenever the secret's stored state changes — it is a
	 * hash over the id, the update timestamp, and a digest of the
	 * ciphertext fields. A no-op read yields a stable tag (304); any
	 * ciphertext or metadata-timestamp change yields a new tag.
	 *
	 * @param Secret $secret The secret
	 *
	 * @return string A quoted strong ETag value (e.g. `"<hex>"`)
	 *
	 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
	 */
	public function etag(Secret $secret): string;

	/**
	 * Whether an If-Match header allows a write to this secret.
	 *
	 * The header is a comma-separated list of quoted ETags, or `*`. A write is
	 * allowed when any listed tag is `*` or equals the secret's current ETag.
	 *
	 * @param Secret $secret The secret about to be written
	 * @param string $header The raw If-Match header value (not empty)
	 *
	 * @return bool True when the precondition holds
	 *
	 * @spec openspec/specs/secret-store-api/spec.md
	 */
	public function ifMatchHolds(Secret $secret, string $header): bool;

	/**
	 * Serialize a secret into the `doriath-machine-secret-v1` envelope.
	 *
	 * Returns plaintext-safe metadata (id, name, url, derived folder path,
	 * type, timestamps, expiry date), an `encryption` block (suite id, sha256
	 * certificate fingerprint, scheme identifier), and the base64
	 * ciphertext fields. No decrypted value can ever be produced here —
	 * the server holds only ciphertext.
	 *
	 * @param Secret $secret The secret to serialize
	 *
	 * @return array<string,mixed> The envelope
	 *
	 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
	 */
	public function serialize(Secret $secret): array;
}//end interface
