/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The site images admin section and the helpers behind the preview and the
 * change-password link (vault-website-addresses): both templates start
 * empty, the section says what leaves the instance, a bad template is not
 * sent, no preview is built without a template, and Change password opens
 * the host's well-known address.
 *
 * @spec openspec/specs/website-addresses/spec.md#requirement-site-icons-and-previews-are-opt-in-per-instance
 * @spec openspec/specs/website-addresses/spec.md#requirement-open-the-sites-change-password-page
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import HealthCategory from '../../src/components/HealthCategory.vue'
import SiteImagesSection from '../../src/components/settings/SiteImagesSection.vue'
import { changePasswordUrl, previewImageUrl, templateProblem } from '../../src/utils/siteImages.js'

const ROUTE = '/apps/keepiq/api/settings/admin/general'

const stubs = {
	CnSettingsSection: { template: '<section><slot /></section>' },
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue', 'blur'],
		template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" @blur="$emit(\'blur\')">',
	},
}

async function mountSection(stored = {}) {
	vi.spyOn(axios, 'get').mockResolvedValue({ data: stored })
	const wrapper = mount(SiteImagesSection, { global: { stubs } })
	await flushPromises()
	return wrapper
}

async function type(wrapper, testid, value) {
	const input = wrapper.find(`[data-testid="${testid}"]`)
	await input.setValue(value)
	await input.trigger('blur')
	await flushPromises()
}

describe('site image helpers', () => {
	it('builds no preview without a valid template or a web address', () => {
		expect(previewImageUrl('', 'https://example.com')).toBeNull()
		expect(previewImageUrl('http://p.test/{url}', 'https://example.com')).toBeNull()
		expect(previewImageUrl('https://p.test/{url}', 'javascript:alert(1)')).toBeNull()
		expect(previewImageUrl('https://p.test/shot?u={url}', 'example.com/login')).toBe('https://p.test/shot?u=https%3A%2F%2Fexample.com%2Flogin')
	})

	it('opens the main address host\'s well-known change-password page', () => {
		expect(changePasswordUrl('https://login.example.com:8443/a?b=c')).toBe('https://login.example.com:8443/.well-known/change-password')
		expect(changePasswordUrl('example.com')).toBe('https://example.com/.well-known/change-password')
		expect(changePasswordUrl('')).toBeNull()
		expect(changePasswordUrl('ftp://files.test')).toBeNull()
	})

	it('accepts an empty template and an https one with its placeholder only', () => {
		expect(templateProblem('', '{url}')).toBeNull()
		expect(templateProblem('https://p.test/{url}', '{url}')).toBeNull()
		expect(templateProblem('https://p.test/{domain}', '{url}')).toBe('placeholder')
		expect(templateProblem('http://p.test/{url}', '{url}')).toBe('https')
	})
})

describe('SiteImagesSection', () => {
	afterEach(() => vi.restoreAllMocks())

	it('starts empty and says what leaves the instance', async () => {
		const wrapper = await mountSection()

		expect(axios.get).toHaveBeenCalledWith(ROUTE)
		expect(wrapper.find('[data-testid="site-images-favicon"]').element.value).toBe('')
		expect(wrapper.find('[data-testid="site-images-preview"]').element.value).toBe('')
		expect(wrapper.find('[data-testid="site-images-privacy"]').text()).toContain('full website address')
	})

	it('saves both templates trimmed, and refuses a bad one without a request', async () => {
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountSection({ favicon_service_url: 'https://icons.test/{domain}' })

		await type(wrapper, 'site-images-preview', ' https://p.test/{url} ')
		expect(put).toHaveBeenLastCalledWith(ROUTE, {
			favicon_service_url: 'https://icons.test/{domain}',
			site_preview_service_url: 'https://p.test/{url}',
		})

		put.mockClear()
		await type(wrapper, 'site-images-preview', 'http://p.test/{url}')
		expect(put).not.toHaveBeenCalled()
		expect(wrapper.find('.note[data-type="error"]').exists()).toBe(true)
	})
})

describe('HealthCategory change password', () => {
	const findings = [
		{ id: 'a', name: 'A', url: 'https://example.com/login' },
		{ id: 'b', name: 'B', url: null },
	]

	it('links each finding with a web address to its change-password page, in a new tab', () => {
		const wrapper = mount(HealthCategory, { props: { title: 'Weak', findings, changePassword: true } })
		const link = wrapper.find('[data-testid="finding-change-password-a"]')

		expect(link.attributes('href')).toBe('https://example.com/.well-known/change-password')
		expect(link.attributes('target')).toBe('_blank')
		expect(link.attributes('rel')).toContain('noopener')
		expect(wrapper.find('[data-testid="finding-change-password-b"]').exists()).toBe(false)
	})

	it('offers no link unless asked', () => {
		const wrapper = mount(HealthCategory, { props: { title: 'Old', findings } })

		expect(wrapper.find('[data-testid="finding-change-password-a"]').exists()).toBe(false)
	})
})
