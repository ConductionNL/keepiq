#!/usr/bin/env node
/**
 * Dutch coverage gate: every t('keepiq', ...) / n('keepiq', ...) key used in
 * src/ has an entry in l10n/nl.json, and the run names each key that has not.
 *
 * Dutch is the one language Keepiq keeps complete at all times, so a Dutch
 * user never meets an English string (the language follows the user's
 * Nextcloud setting; Keepiq keeps no language setting of its own). This is
 * the extraction check of check-l10n.js pointed at nl.json instead of the
 * English source, so both gates agree on what "used" means.
 *
 *   node tests/l10n/check-l10n-nl.js        (npm run test:l10n:nl)
 *
 * Exit codes: 0 every used key is in nl.json, 1 one or more are missing.
 *
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-the-language-follows-the-users-nextcloud-setting
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

if (process.argv.includes('--write')) {
	// --write seeds English values; Dutch needs a translator, not extraction.
	console.error('l10n-check-nl: --write is not supported; add the Dutch translations to l10n/nl.json by hand')
	process.exit(2)
}

process.env.L10N_FILE = process.env.L10N_FILE || 'l10n/nl.json'
require('./check-l10n.js')
