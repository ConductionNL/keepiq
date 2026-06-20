<?php

/**
 * Doriath GdprService
 *
 * Collects the server-readable half of a user's GDPR Art. 15 personal-data
 * export (secret-export-gdpr D3): encryption-suite records (excluding the
 * encrypted private-key blob), shares given and received, delegations,
 * link-share metadata (no encrypted snapshots), secret requests, and user
 * settings.
 *
 * The decrypted vault half cannot be produced server-side under the always-E2E
 * model (ADR-003); the browser assembles the full package. Every collector here
 * is strictly scoped to the session user passed in — there is no parameter that
 * could select another user (fail-closed, no IDOR).
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

use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\LinkShareMapper;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretRequestMapper;
use OCA\Doriath\Db\ShareTargetMapper;

/**
 * Assembles the server-readable half of a GDPR personal-data export.
 */
class GdprService
{
    /**
     * The metadata document format identifier.
     *
     * @var string
     */
    private const FORMAT = 'doriath-gdpr-metadata';

    /**
     * The metadata document version.
     *
     * @var int
     */
    private const VERSION = 1;

    /**
     * Constructor for GdprService.
     *
     * @param SecretMapper           $secretMapper     The secret mapper
     * @param ShareTargetMapper      $shareMapper      The share-target mapper
     * @param SecretDelegationMapper $delegationMapper The delegation mapper
     * @param LinkShareMapper        $linkShareMapper  The link-share mapper
     * @param SecretRequestMapper    $requestMapper    The secret-request mapper
     * @param EncryptionSuiteMapper  $suiteMapper      The encryption-suite mapper
     * @param SettingsService        $settingsService  The settings service
     *
     * @return void
     */
    public function __construct(
        private SecretMapper $secretMapper,
        private ShareTargetMapper $shareMapper,
        private SecretDelegationMapper $delegationMapper,
        private LinkShareMapper $linkShareMapper,
        private SecretRequestMapper $requestMapper,
        private EncryptionSuiteMapper $suiteMapper,
        private SettingsService $settingsService,
    ) {
    }//end __construct()

    /**
     * Collect all server-readable personal data for a user.
     *
     * Strictly self-scoped: the userId is the only selector. The encrypted
     * private-key blob is deliberately excluded from suite records — it is
     * unreadable to the data subject without the master password they already
     * hold, and shipping it in an unprotected JSON file only widens the attack
     * surface. The exclusion is documented inside the returned package.
     *
     * @param string $userId The session user
     *
     * @return array<string,mixed> The versioned, self-describing metadata document
     *
     * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
     */
    public function collectMetadata(string $userId): array
    {
        $ownedSecrets = $this->secretMapper->findByOwner(
            ownerType: 'user',
            ownerId: $userId,
            limit: 100000,
        );

        return [
            'format'         => self::FORMAT,
            'version'        => self::VERSION,
            'subject'        => $userId,
            'generated'      => (new \DateTime())->format('c'),
            'notes'          => [
                'privateKeyExcluded' => 'Encryption-suite private-key blobs are '
                    .'excluded: they are end-to-end encrypted and unreadable to '
                    .'the data subject without the master password they already '
                    .'hold; including them would only widen the attack surface.',
                'vaultHalf'          => 'Decrypted secret values and folder contents form '
                    .'the client-assembled vault half of the full export package '
                    .'and are never produced server-side (ADR-003).',
            ],
            'suites'         => $this->collectSuites($userId),
            'sharesGiven'    => $this->collectSharesGiven($ownedSecrets),
            'sharesReceived' => $this->collectSharesReceived($userId),
            'delegations'    => $this->collectDelegations($userId),
            'linkShares'     => $this->collectLinkShares($userId),
            'requests'       => $this->collectRequests($userId),
            'settings'       => $this->settingsService->getUserPreferences(userId: $userId),
        ];
    }//end collectMetadata()

    /**
     * Collect suite records with the private-key blob omitted.
     *
     * @param string $userId The user ID
     *
     * @return array<int,array<string,mixed>>
     */
    private function collectSuites(string $userId): array
    {
        $rows = [];
        foreach ($this->suiteMapper->findByOwner(ownerType: 'user', ownerId: $userId) as $suite) {
            $rows[] = [
                'id'            => $suite->getId(),
                'status'        => $suite->getStatus(),
                'certificate'   => $suite->getCertificate(),
                'revokedAt'     => $suite->getRevokedAt()?->format('c'),
                'revokedReason' => $suite->getRevokedReason(),
                'revokedBy'     => $suite->getRevokedBy(),
                'reinstatedAt'  => $suite->getReinstatedAt()?->format('c'),
                'reinstatedBy'  => $suite->getReinstatedBy(),
                'createdAt'     => $suite->getCreatedAt()?->format('c'),
                // privateKey deliberately excluded — see package notes.
            ];
        }

        return $rows;
    }//end collectSuites()

    /**
     * Collect the shares the user granted, across all secrets they own.
     *
     * @param array<int,\OCA\Doriath\Db\Secret> $ownedSecrets The user's secrets
     *
     * @return array<int,array<string,mixed>>
     */
    private function collectSharesGiven(array $ownedSecrets): array
    {
        $rows = [];
        foreach ($ownedSecrets as $secret) {
            foreach ($this->shareMapper->findBySourceSecret(sourceSecretId: $secret->getId()) as $share) {
                $rows[] = [
                    'sourceSecretId' => $share->getSourceSecretId(),
                    'targetUserId'   => $share->getTargetUserId(),
                    'groupShareId'   => $share->getGroupShareId(),
                    'createdAt'      => $share->getCreatedAt()?->format('c'),
                ];
            }
        }

        return $rows;
    }//end collectSharesGiven()

    /**
     * Collect the shares the user received.
     *
     * @param string $userId The user ID
     *
     * @return array<int,array<string,mixed>>
     */
    private function collectSharesReceived(string $userId): array
    {
        $rows = [];
        foreach ($this->shareMapper->findByTargetUser(targetUserId: $userId) as $share) {
            $rows[] = [
                'sourceSecretId'  => $share->getSourceSecretId(),
                'recipientCopyId' => $share->getSecretId(),
                'createdAt'       => $share->getCreatedAt()?->format('c'),
            ];
        }

        return $rows;
    }//end collectSharesReceived()

    /**
     * Collect the delegations the user granted as original owner.
     *
     * @param string $userId The user ID
     *
     * @return array<int,array<string,mixed>>
     */
    private function collectDelegations(string $userId): array
    {
        $rows = [];
        foreach ($this->delegationMapper->findByOriginalOwner(originalOwnerId: $userId) as $delegation) {
            $rows[] = [
                'secretId'    => $delegation->getSecretId(),
                'delegatedTo' => $delegation->getDelegatedTo(),
                'isPermanent' => $delegation->getIsPermanent(),
                'delegatedAt' => $delegation->getDelegatedAt()?->format('c'),
            ];
        }

        return $rows;
    }//end collectDelegations()

    /**
     * Collect link-share metadata (no encrypted snapshots).
     *
     * @param string $userId The user ID
     *
     * @return array<int,array<string,mixed>>
     */
    private function collectLinkShares(string $userId): array
    {
        $rows = [];
        foreach ($this->linkShareMapper->findByCreatedBy(userId: $userId) as $link) {
            $rows[] = [
                'id'         => $link->getId(),
                'secretId'   => $link->getSecretId(),
                'usageLimit' => $link->getUsageLimit(),
                'usageCount' => $link->getUsageCount(),
                'createdAt'  => $link->getCreatedAt()?->format('c'),
                'expiresAt'  => $link->getExpiresAt()?->format('c'),
                // token + encryptedSecretSnapshot deliberately omitted.
            ];
        }

        return $rows;
    }//end collectLinkShares()

    /**
     * Collect secret requests the user created.
     *
     * @param string $userId The user ID
     *
     * @return array<int,array<string,mixed>>
     */
    private function collectRequests(string $userId): array
    {
        $rows = [];
        foreach ($this->requestMapper->findByCreatedBy(userId: $userId) as $request) {
            $rows[] = [
                'id'          => $request->getId(),
                'secretId'    => $request->getSecretId(),
                'status'      => $request->getStatus(),
                'isReRequest' => $request->getIsReRequest(),
                'createdAt'   => $request->getCreatedAt()?->format('c'),
                'fulfilledAt' => $request->getFulfilledAt()?->format('c'),
                // token + requestedFields content omitted (request token is a secret).
            ];
        }

        return $rows;
    }//end collectRequests()
}//end class
