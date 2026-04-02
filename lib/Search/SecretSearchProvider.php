<?php

/**
 * Doriath Secret Search Provider
 *
 * Provides unified search results for secrets in Doriath.
 *
 * @category Search
 * @package  OCA\Doriath\Search
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

namespace OCA\Doriath\Search;

use OCA\Doriath\Db\SecretMapper;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Unified search provider that returns matching secrets for the current user.
 */
class SecretSearchProvider implements IProvider
{
    /**
     * Constructor for SecretSearchProvider.
     *
     * @param SecretMapper  $secretMapper The secret mapper
     * @param IURLGenerator $urlGenerator The URL generator service
     *
     * @return void
     */
    public function __construct(
        private SecretMapper $secretMapper,
        private IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Get the unique identifier of this search provider.
     *
     * @return string
     */
    public function getId(): string
    {
        return 'doriath-secrets';
    }//end getId()

    /**
     * Get the translated name of this search provider.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Doriath Secrets';
    }//end getName()

    /**
     * Get the order of this search provider.
     *
     * @param string  $route           The current route
     * @param mixed[] $routeParameters The current route parameters
     *
     * @return int|null
     */
    public function getOrder(string $route, array $routeParameters): ?int
    {
        return 10;
    }//end getOrder()

    /**
     * Search for secrets matching the query term for the given user.
     *
     * @param IUser        $user  The current user
     * @param ISearchQuery $query The search query
     *
     * @return SearchResult
     */
    public function search(IUser $user, ISearchQuery $query): SearchResult
    {
        $term    = $query->getTerm();
        $secrets = $this->secretMapper->searchByNameOrUrl(userId: $user->getUID(), term: $term);

        $entries = [];
        foreach ($secrets as $secret) {
            $resourceUrl = $this->urlGenerator->linkToRoute('doriath.dashboard.page').'#/secrets/'.$secret->getId();

            $entries[] = new SearchResultEntry(
                thumbnailUrl: '',
                title: $secret->getName(),
                subline: ($secret->getUrl() ?? 'Secret'),
                resourceUrl: $resourceUrl,
            );
        }

        return SearchResult::complete(
            name: $this->getName(),
            entries: $entries,
        );
    }//end search()
}//end class
