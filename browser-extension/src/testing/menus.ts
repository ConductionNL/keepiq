import type { Browser } from 'wxt/browser'

type ClickListener = (info: Browser.contextMenus.OnClickData, tab?: Browser.tabs.Tab) => void

/** WXT's fake browser leaves `contextMenus` and `commands` unimplemented; installs in-memory ones. */
export function installMenuFakes() {
	const entries: Browser.contextMenus.CreateProperties[] = []
	const clicks: ClickListener[] = []
	const commands: Array<(command: string, tab?: Browser.tabs.Tab) => void> = []
	Object.assign(browser, {
		contextMenus: {
			create: (properties: Browser.contextMenus.CreateProperties) => {
				entries.push(properties)
				return properties.id
			},
			removeAll: async () => {
				entries.length = 0
			},
			onClicked: { addListener: (listener: ClickListener) => void clicks.push(listener) },
		},
		commands: {
			getAll: async () => [],
			onCommand: { addListener: (listener: (typeof commands)[number]) => void commands.push(listener) },
		},
	})
	return {
		entries,
		click: (menuItemId: string, tab?: Browser.tabs.Tab) => clicks.forEach((listener) => listener({ menuItemId, editable: true, pageUrl: tab?.url ?? '' }, tab)),
		command: (command: string, tab?: Browser.tabs.Tab) => commands.forEach((listener) => listener(command, tab)),
	}
}
