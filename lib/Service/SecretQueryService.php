<?php

/**
 * Doriath Secret Query Service
 *
 * Read-side queries over secrets: list (filter/sort/paginate), fuzzy search,
 * and presentation with revoked-suite blocking. Kept separate from the
 * write-side SecretService to bound each class's complexity and coupling.
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

use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Exception\ForbiddenException;

/**
 * Read-side queries over secrets.
 */
class SecretQueryService
{
    /**
     * The default page size.
     *
     * @var int
     */
    private const DEFAULT_LIMIT = 50;

    /**
     * The maximum page size.
     *
     * @var int
     */
    private const MAX_LIMIT = 100;

    /**
     * Constructor for SecretQueryService.
     *
     * @param SecretMapper      $mapper      The secret mapper
     * @param SecretSuiteGuard  $suiteGuard  The encryption-suite guard
     * @param SecretFuzzySearch $fuzzySearch The fuzzy search helper
     *
     * @return void
     */
    public function __construct(
        private SecretMapper $mapper,
        private SecretSuiteGuard $suiteGuard,
        private SecretFuzzySearch $fuzzySearch,
    ) {
    }//end __construct()

    /**
     * Get a single secret, asserting ownership and suite accessibility.
     *
     * @param string $id     The secret ID
     * @param string $userId The user UID
     *
     * @return Secret
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException When the secret does not exist
     * @throws ForbiddenException                         When not owned or the suite is blocked
     */
    public function get(string $id, string $userId): Secret
    {
        $secret = $this->mapper->findById(id: $id);

        if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $userId) {
            throw new ForbiddenException(message: 'Access denied: secret belongs to another user');
        }

        if ($this->suiteGuard->isSecretBlocked(secret: $secret) === true) {
            throw new ForbiddenException(message: 'The encryption suite for this secret is revoked or compromised');
        }

        return $secret;
    }//end get()

    /**
     * List secrets with optional folder filter, sorting and pagination.
     *
     * @param string              $userId  The user UID
     * @param array<string,mixed> $filters Filter map (folder_id)
     * @param string|null         $sort    Sort field
     * @param string|null         $dir     Sort direction
     * @param int                 $page    1-based page number
     * @param int                 $limit   Page size
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function list(string $userId, array $filters, ?string $sort, ?string $dir, int $page, int $limit): array
    {
        $limit  = $this->clampLimit(limit: $limit);
        $page   = max(1, $page);
        $offset = (($page - 1) * $limit);

        $folderId = $this->resolveFolderFilter(filters: $filters);

        $secrets = $this->mapper->findByOwner('user', $userId, $folderId, $sort, $dir, $limit, $offset);
        $total   = $this->mapper->countByOwner('user', $userId, $folderId);

        return [
            'items' => array_map(fn (Secret $item) => $this->present(secret: $item), $secrets),
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ];
    }//end list()

    /**
     * Fuzzy-search secrets by name and url.
     *
     * @param string $userId The user UID
     * @param string $term   The search term
     * @param int    $page   1-based page number
     * @param int    $limit  Page size
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function search(string $userId, string $term, int $page, int $limit): array
    {
        $limit = $this->clampLimit(limit: $limit);
        $page  = max(1, $page);
        $term  = trim($term);

        if ($term === '') {
            return ['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
        }

        $all = $this->fuzzySearch->match('user', $userId, $term);

        $total     = count($all);
        $offset    = (($page - 1) * $limit);
        $pageItems = array_slice($all, $offset, $limit);

        return [
            'items' => array_map(fn (Secret $item) => $this->present(secret: $item), $pageItems),
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ];
    }//end search()

    /**
     * Present a secret for a list response, withholding encrypted blobs when blocked.
     *
     * @param Secret $secret The secret
     *
     * @return array<string,mixed>
     */
    private function present(Secret $secret): array
    {
        $data = $secret->jsonSerialize();

        if ($this->suiteGuard->isSecretBlocked(secret: $secret) === true) {
            unset($data['key'], $data['login'], $data['additionalFields']);
            $data['blocked']        = true;
            $data['blocked_reason'] = 'The encryption suite for this secret is revoked or compromised';
            return $data;
        }

        $data['blocked'] = false;
        return $data;
    }//end present()

    /**
     * Resolve the folder filter sentinel from the filter map.
     *
     * @param array<string,mixed> $filters The filter map
     *
     * @return string|null
     */
    private function resolveFolderFilter(array $filters): ?string
    {
        if (array_key_exists('folder_id', $filters) === false) {
            return null;
        }

        $value = $filters['folder_id'];
        if ($value === null || $value === '' || $value === 'null' || $value === 'root') {
            return SecretMapper::FILTER_ROOT;
        }

        return (string) $value;
    }//end resolveFolderFilter()

    /**
     * Clamp the requested limit into the allowed range.
     *
     * @param int $limit The requested limit
     *
     * @return int
     */
    private function clampLimit(int $limit): int
    {
        if ($limit <= 0) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }//end clampLimit()
}//end class
