/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Only a web or mail address becomes a link; any other stored URL shows as
 * text, so a `javascript:` or `data:` value from someone else never runs.
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
 */

import { describe, expect, it } from 'vitest'
import { linkHref } from '../../src/utils/safeLink.js'

describe('linkHref', () => {
	it.each([
		'https://example.test/a',
		'http://example.test',
		'mailto:a@example.test',
	])('links %s', (url) => {
		expect(linkHref(url)).toBe(url)
	})

	it.each([
		'javascript:alert(1)',
		'JavaScript:alert(1)',
		'data:text/html,<b>x</b>',
		'ftp://example.test',
		'example.test',
		'',
		null,
		undefined,
	])('shows %s as text', (url) => {
		expect(linkHref(url)).toBeNull()
	})
})
