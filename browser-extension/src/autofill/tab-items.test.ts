import { describe, expect, it } from 'vitest'
import { forgetAllItems, forgetItem, rememberedItem, rememberItem } from './tab-items'

describe('tab items', () => {
	it('remembers the last item per tab until the tab is forgotten', () => {
		rememberItem(1, 'a')
		rememberItem(1, 'b')
		rememberItem(2, 'c')
		expect(rememberedItem(1)).toBe('b')
		expect(rememberedItem(2)).toBe('c')
		forgetItem(1)
		expect(rememberedItem(1)).toBeUndefined()
		expect(rememberedItem(2)).toBe('c')
		forgetAllItems()
		expect(rememberedItem(2)).toBeUndefined()
	})
})
