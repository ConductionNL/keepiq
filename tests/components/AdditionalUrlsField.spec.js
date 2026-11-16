/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The extra website addresses field and its helpers (vault-website-addresses):
 * the list is emitted as stored (trimmed, no empties or repeats, at most 20),
 * rows are added and removed, the add button stops at twenty, and only a web
 * address becomes a link in the detail view.
 *
 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AdditionalUrlsField from '../../src/components/AdditionalUrlsField.vue'
import {
	extraUrlLinks,
	MAX_URLS,
	normaliseUrls,
	sameUrls,
} from '../../src/utils/additionalUrls.js'

describe('additional url helpers', () => {
	it('normalises as the server stores the list', () => {
		expect(
			normaliseUrls([
				' https://a.test ',
				'',
				'https://b.test',
				'HTTPS://A.TEST',
				7,
				null,
			]),
		).toEqual(['https://a.test', 'https://b.test'])
		expect(normaliseUrls(null)).toEqual([])
		expect(
			normaliseUrls(Array.from({ length: 25 }, (_, i) => `https://${i}.test`)),
		).toHaveLength(MAX_URLS)
	})

	it('compares stored lists, order included', () => {
		expect(sameUrls(['https://a.test '], ['https://a.test'])).toBe(true)
		expect(
			sameUrls(
				['https://a.test', 'https://b.test'],
				['https://b.test', 'https://a.test'],
			),
		).toBe(false)
		expect(sameUrls(undefined, [])).toBe(true)
	})

	it('links only web addresses, a bare host over https', () => {
		expect(
			extraUrlLinks([
				'login.example.com',
				'https://example.net/x',
				'javascript:alert(1)',
				'ftp://files.test',
			]),
		).toEqual([
			{ url: 'login.example.com', href: 'https://login.example.com/' },
			{ url: 'https://example.net/x', href: 'https://example.net/x' },
			{ url: 'javascript:alert(1)', href: null },
			{ url: 'ftp://files.test', href: null },
		])
	})
})

describe('AdditionalUrlsField', () => {
	it('emits the stored list as rows are typed and removed', async () => {
		const wrapper = mount(AdditionalUrlsField, {
			props: { modelValue: ['https://a.test'] },
		})

		await wrapper.find('[data-testid="additional-url-add"]').trigger('click')
		const inputs = wrapper.findAllComponents(
			'[data-testid="additional-url-input"]',
		)
		expect(inputs).toHaveLength(2)
		inputs[1].vm.$emit('update:modelValue', ' https://b.test ')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([
			['https://a.test', 'https://b.test'],
		])

		await wrapper
			.findAll('[data-testid="additional-url-remove"]')[0]
			.trigger('click')
		expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([
			['https://b.test'],
		])
		expect(
			wrapper.find('[data-testid="additional-urls-help"]').text(),
		).toContain('not encrypted')
	})

	it('stops adding rows at twenty', async () => {
		const full = Array.from({ length: MAX_URLS }, (_, i) => `https://${i}.test`)
		const wrapper = mount(AdditionalUrlsField, { props: { modelValue: full } })

		expect(
			wrapper
				.find('[data-testid="additional-url-add"]')
				.attributes('disabled'),
		).toBeDefined()
	})
})
