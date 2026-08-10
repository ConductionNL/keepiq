/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * FIDO CXF import parser (cxf-import-export §2).
 *
 * A thin registry adapter around the CXF mapping module: validates the
 * document structure (strict, format-specific error on mismatch) and
 * produces the standard normalized rows so every existing wizard step
 * (mapping preview, folder mapping, duplicate detection, chunked
 * encrypted commit, rejected rows, summary) applies unchanged. Accepts
 * `.cxf` and `.json` by CONTENT structure, never by extension.
 *
 * All parsing is in-browser; plaintext never leaves the page.
 *
 * @spec openspec/specs/cxf-import-export/spec.md#requirement-client-side-cxf-import
 */

import { parseCxfDocument, cxfToRows } from '../../cxf/cxf.js'

/**
 * Parse a CXF export into normalized rows.
 *
 * @param {string|object} input The CXF document text or object.
 * @return {Array<object>} Normalized rows.
 */
export function parseCxf(input) {
	return cxfToRows(parseCxfDocument(input))
}

/** The CXF parser descriptor. */
export const cxfParser = {
	id: 'cxf',
	label: 'FIDO Credential Exchange (CXF)',
	requiresPassphrase: false,
	adjustableMapping: false,
	/**
	 * Parse entry point matching the registry contract.
	 *
	 * @param {string} input The export text.
	 * @return {Promise<Array<object>>} Normalized rows.
	 */
	parse: async (input) => parseCxf(input),
}
