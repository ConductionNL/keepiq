/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Import parser registry (secret-export-gdpr tasks §0/§6.7).
 *
 * The dependency note records that the full import wizard ships with the
 * `secret-import` change; until it lands, this minimal registry provides the
 * extension point so the backup-restore parser registered by this change has a
 * stable home. When `secret-import` lands it adopts this registry (the API —
 * `registerParser` / `getParser` / `listParsers` — is the contract).
 *
 * A parser maps a raw input (file text / parsed JSON) plus an optional
 * passphrase into normalized rows ({ name, url, login, password, notes, folder,
 * type }) for the standard mapping/duplicate/commit steps.
 */

/** @type {Map<string, { id: string, label: string, parse: Function }>} */
const registry = new Map()

/**
 * Register an import parser.
 *
 * @param {object} parser The parser descriptor.
 * @param {string} parser.id Unique parser id (e.g. 'doriath-backup', 'csv').
 * @param {string} parser.label Human label for the wizard.
 * @param {Function} parser.parse (input, options) => Promise<Array<object>>.
 * @return {void}
 */
export function registerParser(parser) {
	if (!parser || !parser.id || typeof parser.parse !== 'function') {
		throw new Error('Invalid import parser')
	}
	registry.set(parser.id, parser)
}

/**
 * Get a registered parser by id.
 *
 * @param {string} id The parser id.
 * @return {object|undefined} The parser descriptor, or undefined.
 */
export function getParser(id) {
	return registry.get(id)
}

/**
 * List the registered parsers.
 *
 * @return {Array<object>} The parser descriptors.
 */
export function listParsers() {
	return [...registry.values()]
}

/**
 * Reset the registry (test helper).
 *
 * @return {void}
 */
export function _resetRegistry() {
	registry.clear()
}
