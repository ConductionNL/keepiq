/**
 * The item last filled or captured in each tab, so a password change form without a username finds its item
 * (spec `login-capture`, Update bar). Background memory only; it outlives navigations and dies with the tab.
 */

const items = new Map<number, string>()

export function rememberItem(tabId: number, itemId: string): void {
	items.set(tabId, itemId)
}

export function rememberedItem(tabId: number): string | undefined {
	return items.get(tabId)
}

export function forgetItem(tabId: number): void {
	items.delete(tabId)
}

/** On lock and account change: which item a tab used is the vault's business. */
export function forgetAllItems(): void {
	items.clear()
}
