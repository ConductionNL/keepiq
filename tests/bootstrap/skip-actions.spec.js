/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Unit tests for the `#skip-actions` Teleport-target guard.
 *
 * The bug these cover was invisible to every existing test: the shell mounted
 * fine under jsdom in component specs (nothing teleports there in isolation)
 * and fine in the browser when authenticated (core's layout supplies the
 * element). It only appeared on the anonymous `/public` shell, where Vue's
 * null-Teleport error aborted the mount and left every public route blank.
 */

import { describe, expect, it } from 'vitest'
import { ensureSkipActionsTarget } from '../../src/bootstrap/skip-actions.js'

describe('ensureSkipActionsTarget', () => {
	it('creates the target when the layout did not supply one', () => {
		document.body.innerHTML = '<div id="keepiq-app"></div>'

		const created = ensureSkipActionsTarget(document)

		expect(created).toBe(true)
		expect(document.getElementById('skip-actions')).not.toBeNull()
	})

	it('leaves an existing target alone and never duplicates the id', () => {
		// What Nextcloud's authenticated layout already renders.
		document.body.innerHTML =
			'<div id="skip-actions">core skip link</div><div id="keepiq-app"></div>'

		const created = ensureSkipActionsTarget(document)

		expect(created).toBe(false)
		expect(document.querySelectorAll('#skip-actions')).toHaveLength(1)
		// Core's own content must survive — this guard supplements, never replaces.
		expect(document.getElementById('skip-actions').textContent).toBe(
			'core skip link',
		)
	})

	it('is idempotent across repeated calls', () => {
		document.body.innerHTML = '<div id="keepiq-app"></div>'

		expect(ensureSkipActionsTarget(document)).toBe(true)
		expect(ensureSkipActionsTarget(document)).toBe(false)
		expect(document.querySelectorAll('#skip-actions')).toHaveLength(1)
	})

	it('puts the target first in the body so the skip link stays reachable', () => {
		// A skip link that is not the first focusable element does satisfy the
		// Teleport but defeats its purpose, so position is asserted, not assumed.
		document.body.innerHTML = '<div id="keepiq-app"></div>'

		ensureSkipActionsTarget(document)

		expect(document.body.firstElementChild.id).toBe('skip-actions')
	})
})
