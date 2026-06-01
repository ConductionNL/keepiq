<?php

/**
 * Doriath Secret Search Provider
 *
 * Nextcloud unified-search provider that queries unencrypted secret
 * metadata (name, url). No master password or vault session is required —
 * these columns are stored in plaintext by design (ADR-005 search seam).
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

use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Db\SecretMapper;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Unified-search provider for Doriath secrets.
 */
class SecretSearchProvider implements IProvider
{
    /**
     * Constructor for SecretSearchProvider.
     *
     * @param SecretMapper  $mapper The secret mapper
     * @param IL10N         $l10n   The localization service
     * @param IURLGenerator $urlGen The URL generator
     *
     * @return void
     */
    public function __construct(
        private SecretMapper $mapper,
        private IL10N $l10n,
        private IURLGenerator $urlGen,
    ) {
    }//end __construct()

    /**
     * Get the provider ID.
     *
     * @return string
     */
    public function getId(): string
    {
        return 'doriath-secrets';
    }//end getId()

    /**
     * Get the provider display name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->l10n->t('Doriath secrets');
    }//end getName()

    /**
     * Order the provider relative to others. Highest priority when the
     * Doriath app itself is in focus.
     *
     * @param string               $route           The current route
     * @param array<string,string> $routeParameters The current route parameters
     *
     * @return int|null
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getOrder(string $route, array $routeParameters): ?int
    {
        if (str_starts_with($route, Application::APP_ID.'.') === true) {
            return -1;
        }

        return 35;
    }//end getOrder()

    /**
     * Search secrets by name and url for the given user.
     *
     * @param IUser        $user  The authenticated user
     * @param ISearchQuery $query The search query
     *
     * @return SearchResult
     *
     * @SuppressWarnings(PHPMD.StaticAccess) SearchResult::complete() is the
     *   framework's mandated factory for building a unified-search result.
     */
    public function search(IUser $user, ISearchQuery $query): SearchResult
    {
        $term    = trim($query->getTerm());
        $entries = [];

        if ($term !== '') {
            $secrets = $this->mapper->searchByNameOrUrl('user', $user->getUID(), $term);
            foreach ($secrets as $secret) {
                $subline  = ($secret->getUrl() ?? $this->l10n->t('Secret'));
                $deepLink = $this->urlGen->linkToRouteAbsolute(
                    Application::APP_ID.'.dashboard.page'
                ).'#/secrets/'.$secret->getId();

                $entries[] = new SearchResultEntry(
                    $this->urlGen->imagePath(Application::APP_ID, 'app.svg'),
                    $secret->getName(),
                    $subline,
                    $deepLink,
                    'icon-password'
                );
            }
        }

        return SearchResult::complete($this->getName(), $entries);
    }//end search()
}//end class
