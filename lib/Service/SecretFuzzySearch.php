<?php

/**
 * Doriath Secret Fuzzy Search
 *
 * Two-stage fuzzy matcher (SQL substring pre-filter + PHP Levenshtein
 * tolerance) over a user's secrets, querying only unencrypted name/url.
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

/**
 * Fuzzy search over a user's secrets by name and url.
 */
class SecretFuzzySearch
{
    /**
     * Term-length boundary below which only a distance-1 typo is tolerated.
     *
     * @var int
     */
    private const SHORT_TERM_LENGTH = 5;

    /**
     * Constructor for SecretFuzzySearch.
     *
     * @param SecretMapper $mapper The secret mapper
     *
     * @return void
     */
    public function __construct(private SecretMapper $mapper)
    {
    }//end __construct()

    /**
     * Return the matching secrets for a search term, ordered by name.
     *
     * @param string $ownerType The owner type
     * @param string $ownerId   The owner ID
     * @param string $term      The (already-trimmed, non-empty) search term
     *
     * @return Secret[]
     */
    public function match(string $ownerType, string $ownerId, string $term): array
    {
        $matched = [];

        // Stage 1: SQL substring pre-filter.
        foreach ($this->mapper->searchByNameOrUrl($ownerType, $ownerId, $term) as $secret) {
            $matched[$secret->getId()] = $secret;
        }

        // Stage 2: PHP Levenshtein typo tolerance over the full vault.
        $tolerance = 2;
        if (mb_strlen($term) <= self::SHORT_TERM_LENGTH) {
            $tolerance = 1;
        }

        foreach ($this->mapper->findAllByOwner($ownerType, $ownerId) as $secret) {
            if (isset($matched[$secret->getId()]) === true) {
                continue;
            }

            if ($this->fuzzyMatches(term: $term, secret: $secret, tolerance: $tolerance) === true) {
                $matched[$secret->getId()] = $secret;
            }
        }

        $all = array_values($matched);
        usort($all, static fn (Secret $a, Secret $b) => strcasecmp($a->getName(), $b->getName()));

        return $all;
    }//end match()

    /**
     * Whether a term fuzzy-matches a secret's name or url within tolerance.
     *
     * @param string $term      The search term
     * @param Secret $secret    The secret
     * @param int    $tolerance The maximum Levenshtein distance
     *
     * @return bool
     */
    private function fuzzyMatches(string $term, Secret $secret, int $tolerance): bool
    {
        $candidates = [$secret->getName()];
        if ($secret->getUrl() !== null) {
            $candidates[] = $secret->getUrl();
        }

        $needle = mb_strtolower($term);
        foreach ($candidates as $candidate) {
            if ($this->candidateMatches(needle: $needle, candidate: $candidate, tolerance: $tolerance) === true) {
                return true;
            }
        }

        return false;
    }//end fuzzyMatches()

    /**
     * Whether a single candidate string matches the needle within tolerance,
     * checking both the whole string and each token.
     *
     * @param string $needle    The lowercased search term
     * @param string $candidate The candidate name or url
     * @param int    $tolerance The maximum Levenshtein distance
     *
     * @return bool
     */
    private function candidateMatches(string $needle, string $candidate, int $tolerance): bool
    {
        $haystack = mb_strtolower($candidate);

        if (levenshtein($needle, $haystack) <= $tolerance) {
            return true;
        }

        foreach (preg_split('/[\s\/.]+/', $haystack) as $token) {
            if ($token !== '' && levenshtein($needle, $token) <= $tolerance) {
                return true;
            }
        }

        return false;
    }//end candidateMatches()
}//end class
