/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The star and the tag chips on a list row (vault-favourites-tags-and-last-used):
 * the star says what a click does and stars through the store without
 * opening the secret; a trashed row has no star; tags show as chips.
 *
 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-favourite-items-per-holder
 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretListItem from '../../src/components/SecretListItem.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'

describe('SecretListItem: star and tags', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('stars an unstarred row through the store and does not open it', async () => {
		const store = useSecretStore()
		const setFavourite = vi.spyOn(store, 'setFavourite').mockResolvedValue()
		const wrapper = mount(SecretListItem, {
			props: { secret: { id: 's-1', name: 'Router', favourite: false } },
		})

		const star = wrapper.find('[data-testid="secret-star-s-1"]')
		expect(star.attributes('aria-label')).toBe('Add {name} to favourites')
		await star.trigger('click')

		expect(setFavourite).toHaveBeenCalledWith('s-1', true)
		expect(wrapper.emitted('open')).toBeUndefined()
	})

	it('offers to unstar a starred row', async () => {
		const store = useSecretStore()
		const setFavourite = vi.spyOn(store, 'setFavourite').mockResolvedValue()
		const wrapper = mount(SecretListItem, {
			props: { secret: { id: 's-2', name: 'Bank', favourite: true } },
		})

		const star = wrapper.find('[data-testid="secret-star-s-2"]')
		expect(star.attributes('aria-label')).toBe('Remove {name} from favourites')
		// NcButton is stubbed in the suite; its `pressed` prop is what the real
		// component renders as aria-pressed.
		expect(star.attributes('pressed')).toBe('true')
		await star.trigger('click')

		expect(setFavourite).toHaveBeenCalledWith('s-2', false)
	})

	it('shows no star on a trashed row', () => {
		const wrapper = mount(SecretListItem, {
			props: {
				secret: { id: 's-3', name: 'Old', trashedAt: '2026-10-01T00:00:00+00:00' },
			},
		})

		expect(wrapper.find('[data-testid="secret-star-s-3"]').exists()).toBe(false)
	})

	it('shows the tags as chips, and nothing when there are none', () => {
		const tagged = mount(SecretListItem, {
			props: { secret: { id: 's-4', name: 'Pager', tags: ['finance', 'on call'] } },
		})
		const chips = tagged.findAll('.secret-list-item__tag').map((c) => c.text())
		expect(chips).toEqual(['finance', 'on call'])

		const plain = mount(SecretListItem, {
			props: { secret: { id: 's-5', name: 'Plain' } },
		})
		expect(plain.find('[data-testid="secret-tags"]').exists()).toBe(false)
	})
})
