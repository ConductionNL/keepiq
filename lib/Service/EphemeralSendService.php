<?php

/**
 * Doriath Ephemeral Send Service
 *
 * Burn-after-reading ad-hoc shares (ephemeral-send §2). No SecretService
 * or ShareService dependency — a send never creates a vault secret. The
 * server stores AES-256-GCM ciphertext only; with a password only the
 * Argon2id-wrapped content key + salt are stored; with no password the
 * content key rides the URL fragment and never reaches the server.
 *
 * Access protocol (mirrors the link-share two-phase design): `peek`
 * returns metadata; `access` returns the ciphertext (and wrapped key)
 * WITHOUT consuming a view; `confirmView` — called after a successful
 * client-side decrypt — consumes a view and burns at the cap;
 * `reportFailure` counts a failed password attempt and permanently
 * burns the send at 5.
 *
 * @category Service
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use DateInterval;
use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\EphemeralSend;
use OCA\Doriath\Db\EphemeralSendMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for the ephemeral-send lifecycle.
 */
class EphemeralSendService
{
    /**
     * Maximum permitted views per send (unlimited is not allowed).
     *
     * @var int
     */
    public const MAX_VIEWS_CAP = 100;

    /**
     * Maximum permitted TTL in seconds (30 days).
     *
     * @var int
     */
    public const TTL_CAP_SECONDS = 2592000;

    /**
     * Failed password attempts after which a send permanently burns.
     *
     * @var int
     */
    public const FAILED_ATTEMPTS_CAP = 5;

    /**
     * Token bytes (32 bytes = 256 bits, above the 128-bit floor).
     *
     * @var int
     */
    private const TOKEN_BYTES = 32;

    /**
     * Constructor for EphemeralSendService.
     *
     * @param EphemeralSendMapper $mapper The send mapper
     *
     * @return void
     */
    public function __construct(
        private EphemeralSendMapper $mapper,
    ) {
    }//end __construct()

    /**
     * Create a send from client-encrypted material.
     *
     * @param string              $ownerId The creating user
     * @param array<string,mixed> $params  {encryptedPayload, payloadType, maxViews?, ttlSeconds?, hasPassword?, wrappedKey?, argon2idSalt?}
     *
     * @return EphemeralSend
     *
     * @throws InvalidArgumentException On invalid parameters
     *
     * @spec openspec/specs/ephemeral-send/spec.md#requirement-create-a-standalone-ephemeral-send
     */
    public function create(string $ownerId, array $params): EphemeralSend
    {
        $encryptedPayload = (string) ($params['encryptedPayload'] ?? '');
        if ($encryptedPayload === '') {
            throw new InvalidArgumentException('encryptedPayload is required');
        }

        $payloadType  = $this->resolvePayloadType(params: $params);
        $maxViews     = $this->resolveMaxViews(params: $params);
        $expiresAt    = $this->resolveExpiry(params: $params);
        $hasPassword  = (bool) ($params['hasPassword'] ?? false);
        $wrappedKey   = $this->optionalString(value: ($params['wrappedKey'] ?? null));
        $argon2idSalt = $this->optionalString(value: ($params['argon2idSalt'] ?? null));
        $this->assertPasswordFieldsConsistent(
            hasPassword: $hasPassword,
            wrappedKey: $wrappedKey,
            argon2idSalt: $argon2idSalt
        );

        $send = new EphemeralSend();
        $send->setId(Uuid::uuid4()->toString());
        $send->setOwnerId($ownerId);
        $send->setToken(bin2hex(random_bytes(self::TOKEN_BYTES)));
        $send->setEncryptedPayload($encryptedPayload);
        $send->setPayloadType($payloadType);
        $send->setHasPassword($hasPassword);
        $send->setWrappedKey($wrappedKey);
        $send->setArgon2idSalt($argon2idSalt);
        $send->setMaxViews($maxViews);
        $send->setExpiresAt($expiresAt);
        $send->setCreatedAt(new DateTime());

        return $this->mapper->insert($send);
    }//end create()

    /**
     * The requested payload type, defaulting to `text`.
     *
     * @param array<string,mixed> $params The create params
     *
     * @return string
     *
     * @throws InvalidArgumentException On an unsupported payload type.
     */
    private function resolvePayloadType(array $params): string
    {
        $payloadType = (string) ($params['payloadType'] ?? 'text');
        if (in_array($payloadType, ['text', 'credential'], true) === false) {
            throw new InvalidArgumentException('payloadType must be text or credential');
        }

        return $payloadType;
    }//end resolvePayloadType()

    /**
     * The requested view budget. Unlimited views are deliberately not an
     * option — a send always burns down to zero.
     *
     * @param array<string,mixed> $params The create params
     *
     * @return int
     *
     * @throws InvalidArgumentException When the budget is out of bounds.
     */
    private function resolveMaxViews(array $params): int
    {
        $maxViews = (int) ($params['maxViews'] ?? 1);
        if ($maxViews < 1 || $maxViews > self::MAX_VIEWS_CAP) {
            throw new InvalidArgumentException(
                'maxViews must be between 1 and '.self::MAX_VIEWS_CAP.' — unlimited views are not allowed'
            );
        }

        return $maxViews;
    }//end resolveMaxViews()

    /**
     * The absolute expiry derived from the requested TTL, or null when the
     * send has no time limit.
     *
     * @param array<string,mixed> $params The create params
     *
     * @return DateTime|null
     *
     * @throws InvalidArgumentException When the TTL exceeds the cap.
     */
    private function resolveExpiry(array $params): ?DateTime
    {
        $ttlSeconds = (int) ($params['ttlSeconds'] ?? 0);
        if ($ttlSeconds <= 0) {
            return null;
        }

        if ($ttlSeconds > self::TTL_CAP_SECONDS) {
            throw new InvalidArgumentException('ttlSeconds exceeds the cap of '.self::TTL_CAP_SECONDS);
        }

        return (new DateTime())->add(new DateInterval('PT'.$ttlSeconds.'S'));
    }//end resolveExpiry()

    /**
     * The password-protection fields travel together: a protected send needs
     * both the wrapped key and the salt, an unprotected one may carry neither.
     *
     * @param bool        $hasPassword  Whether the send is password-protected
     * @param string|null $wrappedKey   The password-wrapped payload key
     * @param string|null $argon2idSalt The Argon2id salt
     *
     * @return void
     *
     * @throws InvalidArgumentException When the fields disagree.
     */
    private function assertPasswordFieldsConsistent(
        bool $hasPassword,
        ?string $wrappedKey,
        ?string $argon2idSalt,
    ): void {
        if ($hasPassword === true && ($wrappedKey === null || $argon2idSalt === null)) {
            throw new InvalidArgumentException('A password-protected send requires wrappedKey and argon2idSalt');
        }

        if ($hasPassword === false && ($wrappedKey !== null || $argon2idSalt !== null)) {
            throw new InvalidArgumentException('wrappedKey/argon2idSalt are only valid with hasPassword');
        }
    }//end assertPasswordFieldsConsistent()

    /**
     * The owner's sends, newest first.
     *
     * @param string $ownerId The owner
     *
     * @return EphemeralSend[]
     */
    public function listForOwner(string $ownerId): array
    {
        return $this->mapper->findByOwner(ownerId: $ownerId);
    }//end listForOwner()

    /**
     * Revoke (delete) one of the owner's sends. A foreign send throws
     * the SAME not-found as a missing one.
     *
     * @param string $id      The send UUID
     * @param string $ownerId The claiming owner
     *
     * @return void
     *
     * @throws DoesNotExistException When missing or foreign
     */
    public function revoke(string $id, string $ownerId): void
    {
        $send = $this->mapper->findById($id);
        if ($send->getOwnerId() !== $ownerId) {
            throw new DoesNotExistException('Send not found');
        }

        $this->mapper->delete($send);
    }//end revoke()

    /**
     * Public metadata for the access page — never the ciphertext.
     *
     * @param string $token The URL token
     *
     * @return array<string,mixed>
     *
     * @throws DoesNotExistException When missing, expired, or burned
     */
    public function peek(string $token): array
    {
        $send = $this->loadLive(token: $token);

        return [
            'payloadType'    => $send->getPayloadType(),
            'hasPassword'    => $send->getHasPassword(),
            'argon2idSalt'   => $send->getArgon2idSalt(),
            'remainingViews' => max(0, ($send->getMaxViews() - $send->getViewCount())),
        ];
    }//end peek()

    /**
     * The ciphertext (+ wrapped key) for client-side decryption. Does
     * NOT consume a view — a wrong password on a one-view send must not
     * burn it; the client confirms a successful decrypt separately.
     *
     * @param string $token The URL token
     *
     * @return array<string,mixed>
     *
     * @throws DoesNotExistException When missing, expired, or burned
     *
     * @spec openspec/specs/ephemeral-send/spec.md#requirement-burn-after-read-and-optional-expiry
     */
    public function access(string $token): array
    {
        $send = $this->loadLive(token: $token);

        return [
            'encryptedPayload' => $send->getEncryptedPayload(),
            'payloadType'      => $send->getPayloadType(),
            'hasPassword'      => $send->getHasPassword(),
            'wrappedKey'       => $send->getWrappedKey(),
            'argon2idSalt'     => $send->getArgon2idSalt(),
        ];
    }//end access()

    /**
     * Confirm a successful client-side decrypt: consume one view and
     * delete the row once the cap is reached (burn).
     *
     * @param string $token The URL token
     *
     * @return array{burned:bool, remainingViews:int}
     *
     * @throws DoesNotExistException When missing, expired, or burned
     */
    public function confirmView(string $token): array
    {
        $send = $this->loadLive(token: $token);
        $send->setViewCount($send->getViewCount() + 1);

        if ($send->getViewCount() >= $send->getMaxViews()) {
            $this->mapper->delete($send);

            return [
                'burned'         => true,
                'remainingViews' => 0,
            ];
        }

        $this->mapper->update($send);

        return [
            'burned'         => false,
            'remainingViews' => ($send->getMaxViews() - $send->getViewCount()),
        ];
    }//end confirmView()

    /**
     * Count a failed password attempt; the send permanently burns at 5.
     *
     * @param string $token The URL token
     *
     * @return array{burned:bool, attemptsLeft:int}
     *
     * @throws DoesNotExistException When missing, expired, or burned
     *
     * @spec openspec/specs/ephemeral-send/spec.md#requirement-burn-after-read-and-optional-expiry
     */
    public function reportFailure(string $token): array
    {
        $send = $this->loadLive(token: $token);
        if ($send->getHasPassword() === false) {
            return [
                'burned'       => false,
                'attemptsLeft' => self::FAILED_ATTEMPTS_CAP,
            ];
        }

        $send->setFailedAttempts($send->getFailedAttempts() + 1);
        if ($send->getFailedAttempts() >= self::FAILED_ATTEMPTS_CAP) {
            $this->mapper->delete($send);

            return [
                'burned'       => true,
                'attemptsLeft' => 0,
            ];
        }

        $this->mapper->update($send);

        return [
            'burned'       => false,
            'attemptsLeft' => (self::FAILED_ATTEMPTS_CAP - $send->getFailedAttempts()),
        ];
    }//end reportFailure()

    /**
     * Delete TTL-elapsed and fully-burned sends (cron).
     *
     * @return int Rows purged
     */
    public function purge(): int
    {
        $count = 0;
        foreach ($this->mapper->findPurgeable(now: new DateTime()) as $send) {
            $this->mapper->delete($send);
            ++$count;
        }

        return $count;
    }//end purge()

    /**
     * Load a live (unexpired, unburned) send by token; anything else is
     * the SAME not-found (no oracle between missing/expired/burned).
     *
     * @param string $token The URL token
     *
     * @return EphemeralSend
     *
     * @throws DoesNotExistException When missing, expired, or burned
     */
    private function loadLive(string $token): EphemeralSend
    {
        $send = $this->mapper->findByToken(token: $token);

        $expired = $send->getExpiresAt() !== null && $send->getExpiresAt() <= new DateTime();
        $burned  = $send->getViewCount() >= $send->getMaxViews();
        if ($expired === true || $burned === true) {
            $this->mapper->delete($send);
            throw new DoesNotExistException('Send not found');
        }

        return $send;
    }//end loadLive()

    /**
     * Normalise an optional value to a non-empty string or null.
     *
     * @param mixed $value The raw value
     *
     * @return string|null
     */
    private function optionalString(mixed $value): ?string
    {
        if (is_string($value) === true && $value !== '') {
            return $value;
        }

        return null;
    }//end optionalString()
}//end class
