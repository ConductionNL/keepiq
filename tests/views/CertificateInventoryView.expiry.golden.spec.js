/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the expiry column of the certificate inventory's three tables
 * (stored certificates, vault certificates, CA): the expiry date, marked
 * critical under a week and warning under a month, and the fallback when a
 * certificate has no parsed expiry. The clock is frozen.
 *
 * @spec openspec/specs/certificate-lifecycle/spec.md
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import CertificateInventoryView from '../../src/views/CertificateInventoryView.vue'
import { useCertificateStore } from '../../src/store/modules/certificate.js'

const NOW = new Date('2026-06-01T12:00:00Z')
const DAY = 86400000
const at = (days) => new Date(NOW.getTime() + days * DAY).toISOString()

const stubs = {
	NcButton: { template: '<button><slot /></button>' },
	NcEmptyContent: { props: ['name'], template: '<div class="empty">{{ name }}</div>' },
	NcLoadingIcon: { template: '<span />' },
	NcNoteCard: { template: '<div><slot /></div>' },
	CertificateRenewalChecklistDialog: true,
}

/**
 * @param {string} html Cell markup.
 * @return {string} The markup with each expiry date, as the browser formats it, named by its day offset.
 */
function tokenDates(html) {
	return [3, 20, 90].reduce(
		(out, days) => out.split(new Date(at(days)).toLocaleDateString()).join(`<date +${days}d>`),
		html,
	)
}

/**
 * @param {string} selector The table rows.
 * @param {object} wrapper The mounted view.
 * @return {Array<string>} The expiry cell markup of each row.
 */
function expiryCells(wrapper, selector) {
	return wrapper.findAll(selector).map((row) =>
		row
			.findAll('td')
			.filter((td) => td.html().includes('span'))
			.map((td) => tokenDates(td.html().replace(/ data-v-[0-9a-f]+=""/g, '').replace(/>\s+</g, '><').replace(/\s+/g, ' ')))
			.join(''),
	)
}

beforeEach(() => {
	vi.useFakeTimers()
	vi.setSystemTime(NOW)
	setActivePinia(createPinia())
	const store = useCertificateStore()
	store.fetchInventory = vi.fn()
	const rows = (prefix) => [
		{ id: `${prefix}1`, caRole: `${prefix}-root`, metadata: { subject: 'CN=a', notAfter: at(3) } },
		{ id: `${prefix}2`, caRole: `${prefix}-int`, metadata: { subject: 'CN=b', notAfter: at(20) } },
		{ id: `${prefix}3`, caRole: `${prefix}-leaf`, metadata: { subject: 'CN=c', notAfter: at(90) } },
		{ id: `${prefix}4`, caRole: `${prefix}-none`, metadata: null },
	]
	store.inventory = { stored: rows('s'), suites: rows('v'), ca: rows('c') }
})

afterEach(() => {
	vi.useRealTimers()
})

describe('certificate inventory expiry column (golden)', () => {
	it('marks the expiry of every table the same way, with each table its own fallback', () => {
		const wrapper = mount(CertificateInventoryView, { global: { stubs } })
		const cells = {
			stored: expiryCells(wrapper, 'tr[data-testid^="cert-stored-"]'),
			suites: expiryCells(wrapper, 'tr[data-testid^="cert-suite-"]'),
			ca: expiryCells(wrapper, 'tr[data-testid^="cert-ca-"]'),
		}
		expect(cells.stored).toHaveLength(4)
		expect(cells.suites).toHaveLength(4)
		expect(cells.ca).toHaveLength(4)
		expect(cells).toMatchSnapshot()
	})
})
