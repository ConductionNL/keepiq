/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Rendered markup reduced to what a golden-markup spec compares: no scope ids,
 * no comments and no layout whitespace.
 */

/**
 * @param {string} html Rendered markup.
 * @return {string} The markup without scope ids, comments or layout whitespace.
 */
export function goldenMarkup(html) {
	let previous
	let out = html.replace(/ data-v-[0-9a-f]+=""/g, '')
	// Repeated until stable, so removing one comment cannot leave another behind.
	do {
		previous = out
		out = out.replace(/<!--[\s\S]*?-->/g, '')
	} while (out !== previous)
	return out.replace(/>\s+</g, '><')
}
