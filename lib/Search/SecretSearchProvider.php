<?php

/**
 * Doriath Secret Search Provider
 *
 * Registers Doriath secrets with Nextcloud's unified search. The provider
 * queries the plaintext name and url columns only — no master password or
 * vault session is required (ADR-003 keeps name/url unencrypted for exactly
 * this purpose). Results deep-link into the app; if the vault is locked, the
 * in-app route guard redirects through the lock screen with a returnUrl.
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
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Unified search provider for Doriath secrets.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) OCP\Search\SearchResult::complete() is the
 *   Nextcloud-mandated factory for building a provider result; there is no
 *   instance API.
 */
class SecretSearchProvider implements IProvider {
	/**
	 * The maximum number of results returned per query.
	 *
	 * @var int
	 */
	private const RESULT_LIMIT = 20;

	/**
	 * Constructor for SecretSearchProvider.
	 *
	 * @param SecretMapper $mapper The secret mapper
	 * @param IURLGenerator $urlGenerator The URL generator
	 * @param IL10N $l10n The localisation helper
	 *
	 * @return void
	 */
	public function __construct(
		private SecretMapper $mapper,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Get the provider ID.
	 *
	 * @return string
	 */
	public function getId(): string {
		return 'doriath-secrets';
	}//end getId()

	/**
	 * Get the human-readable provider name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return $this->l10n->t('Secrets');
	}//end getName()

	/**
	 * Get the provider order relative to other providers.
	 *
	 * @param string $route The current route
	 * @param array $routeParameters The route parameters
	 *
	 * @return int
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $routeParameters is mandated by
	 *   OCP\Search\IProvider::getOrder(); this provider's ranking depends only on
	 *   whether $route belongs to this app, never on the route's parameters.
	 */
	public function getOrder(string $route, array $routeParameters): int {
		if (str_starts_with($route, Application::APP_ID . '.') === true) {
			return -1;
		}

		return 45;
	}//end getOrder()

	/**
	 * Run the search, scoped to the current user's secrets.
	 *
	 * @param IUser $user The authenticated user
	 * @param ISearchQuery $query The search query
	 *
	 * @return SearchResult
	 */
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$term = trim($query->getTerm());
		if ($term === '') {
			return SearchResult::complete($this->getName(), []);
		}

		$secrets = $this->mapper->findForUnifiedSearch($user->getUID(), $term, self::RESULT_LIMIT);

		$entries = array_map(
			fn (Secret $secret): SearchResultEntry => $this->toEntry(secret: $secret),
			$secrets
		);

		return SearchResult::complete($this->getName(), $entries);
	}//end search()

	/**
	 * Convert a secret into a unified-search result entry.
	 *
	 * @param Secret $secret The secret
	 *
	 * @return SearchResultEntry
	 */
	private function toEntry(Secret $secret): SearchResultEntry {
		$url = $secret->getUrl();
		$subtitle = $this->l10n->t('Secret');
		if ($url !== null && $url !== '') {
			$subtitle = $url;
		}

		$deepLink = $this->urlGenerator->linkToRouteAbsolute(
			'doriath.dashboard.page'
		) . '#/secrets/' . $secret->getId();

		$icon = $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg');

		return new SearchResultEntry(
			$icon,
			$secret->getName(),
			$subtitle,
			$deepLink,
			'',
			false
		);
	}//end toEntry()
}//end class
