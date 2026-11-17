// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/** The registrable domain of a host; see registrableDomain.js. */
export function registrableDomainOf(
	host: string | null | undefined,
	getDomain: (host: string, options: { allowPrivateDomains: boolean }) => string | null,
): string | null
