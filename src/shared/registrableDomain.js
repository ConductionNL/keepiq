/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The registrable domain of a host, the one rule the web app and the browser
 * extension both match sites by (health-site-security-checks D4). The public
 * suffix lookup is passed in, so this module imports nothing: the web app and
 * the extension are separate npm packages and each brings its own copy of
 * `tldts`.
 *
 * Private suffixes count, so one `github.io` site never matches another; IPs,
 * `localhost` and single-label hosts match only themselves.
 *
 * @spec openspec/changes/health-site-security-checks/tasks.md#task-3.1
 */

/**
 * The registrable domain of a host by the public suffix list, so
 * `login.example.co.uk` gives `example.co.uk`.
 *
 * @param {string|null|undefined} host A hostname, as URL#hostname gives it
 * @param {(host: string, options: {allowPrivateDomains: boolean}) => (string|null)} getDomain The public suffix lookup (`tldts` getDomain)
 * @return {string|null} The registrable domain, the host itself when it has none, null without a host
 * @spec openspec/changes/health-site-security-checks/tasks.md#task-3.1
 */
export function registrableDomainOf(host, getDomain) {
	if (!host) {
		return null
	}
	return getDomain(host, { allowPrivateDomains: true }) ?? host
}
