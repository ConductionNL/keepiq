import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defaultOptions } from '@/src/generator/options'
import { editableItem, fakeBackground, sendRow, snapshotReply } from './testing'

// From the package root, as vitest runs; `RECORD_GOLDEN=1` rewrites the record.
const GOLDEN = resolve(process.cwd(), 'entrypoints/popup/testing.golden.json')

type Message = Record<string, unknown> & { kind: string }

const generatorEntry = { kind: 'password', value: 'pw-1' }
const sendInput = (password?: string) => ({ payloadType: 'text', text: 'hi', maxViews: 3, ttlSeconds: 3600, ...(password !== undefined && { password }) })

/** Every kind the fake knows, in an order that shows each change it keeps, plus kinds it does not know. */
const SEQUENCE: Message[] = [
	{ kind: 'settings.get' }, { kind: 'settings.set', patch: { theme: 'dark', quickCopy: false } }, { kind: 'settings.get' },
	{ kind: 'account.settings.get' }, { kind: 'account.settings.set', patch: { vaultTimeout: 5 } }, { kind: 'account.settings.get' },
	{ kind: 'pin.set' }, { kind: 'account.settings.get' }, { kind: 'pin.remove' }, { kind: 'account.settings.get' },
	{ kind: 'defaultManager.get' }, { kind: 'defaultManager.enable' }, { kind: 'defaultManager.enable' }, { kind: 'defaultManager.get' },
	{ kind: 'defaultManager.disable' }, { kind: 'defaultManager.get' },
	{ kind: 'vault.snapshot' }, { kind: 'account.settings.set', patch: { defaultItemType: 'note' } }, { kind: 'vault.snapshot' },
	{ kind: 'account.settings.set', patch: { defaultItemType: 'login' } }, { kind: 'vault.snapshot' }, { kind: 'vault.sync' },
	{ kind: 'item.fetch', id: 'i1' }, { kind: 'item.fetch', id: 'missing' }, { kind: 'item.create', draft: {} }, { kind: 'item.update', id: 'i9' },
	{ kind: 'item.editable', id: 'i1' }, { kind: 'item.delete', id: 'i1' }, { kind: 'folder.rename', id: 'f1' }, { kind: 'folder.delete', id: 'f1' },
	{ kind: 'folder.create' }, { kind: 'folder.children', id: 'f1' }, { kind: 'folder.dismissNotice' },
	{ kind: 'item.decrypt', ids: ['i1', 'i2'], fields: ['login', 'key'] },
	{ kind: 'generator.context' }, { kind: 'generator.saveOptions', options: { ...defaultOptions(), length: 30 } }, { kind: 'generator.context' },
	{ kind: 'generator.history.list' }, { kind: 'generator.history.add', entry: generatorEntry }, { kind: 'generator.history.list' },
	{ kind: 'generator.history.clear' }, { kind: 'generator.history.list' },
	{ kind: 'send.list' }, { kind: 'send.create', input: sendInput('secret') }, { kind: 'send.create', input: sendInput() }, { kind: 'send.list' },
	{ kind: 'send.delete', id: 's-new-1' }, { kind: 'send.list' },
	{ kind: 'popup.lastTab.set' }, { kind: 'popup.popout' }, { kind: 'clipboard.copied' },
	{ kind: 'state.get' }, { kind: 'a.kind.nobody.sends' }, { kind: '__proto__' }, { kind: 'toString' }, { kind: 'constructor' }, { kind: 'hasOwnProperty' },
]

let snapshotReads = 0
const WORLDS: Record<string, () => Parameters<typeof fakeBackground>[0]> = {
	'defaults': () => ({}),
	'every option set': () => ({
		unanswered: ['settings.get', 'send.list'],
		replies: { 'vault.sync': { syncing: true }, 'item.create': (message: Message) => ({ ok: false, echoed: message.kind }), 'folder.create': null },
		items: { i1: editableItem() },
		decrypted: { i1: { login: 'alice', key: 'pw' }, i2: { key: 'only key' } },
		snapshot: () => snapshotReply({ defaultSecretType: (snapshotReads++ % 2) === 0 ? null : 'card' }),
		settings: { compactMode: true },
		generator: { options: { ...defaultOptions(), length: 12 }, policy: null, accountEmail: null, history: [{ ...generatorEntry, createdAt: '2026-01-01T00:00:00.000Z' } as never] },
		send: { sends: [sendRow()], passwordProtection: false },
		accountSettings: { maxIdleMinutes: 3, values: { vaultTimeout: 15 } },
		defaultManager: 'on',
		lastTab: 'send',
	}),
	'decrypt fails': () => ({ decryptError: 'use_only', generator: { policy: { minLength: 20 } as never, accountEmail: 'bob@example.org' } }),
	'locked vault': () => ({ snapshot: { state: 'locked' } as never, accountSettings: { values: { defaultItemType: 'note' } } }),
	'browser manages the default': () => ({ defaultManager: 'managed' }),
	'unlocked with a default type': () => ({ snapshot: snapshotReply({ defaultSecretType: 'identity' }), accountSettings: { values: { defaultItemType: 'card' } } }),
}

async function trace(world: () => Parameters<typeof fakeBackground>[0]): Promise<unknown[]> {
	snapshotReads = 0
	const reply = fakeBackground(world()) as unknown as (message: Message) => Promise<unknown>
	const out: unknown[] = []
	for (const message of SEQUENCE) {
		try {
			out.push([message.kind, await reply(message)])
		} catch (error) {
			out.push([message.kind, { threw: String(error) }])
		}
	}
	return JSON.parse(JSON.stringify(out, (_key, value: unknown) => (value === undefined ? '(undefined)' : value)))
}

beforeEach(() => {
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(new Date('2026-10-23T08:00:00Z'))
})
afterEach(() => {
	vi.useRealTimers()
})

describe('fakeBackground golden', () => {
	if (process.env.RECORD_GOLDEN === '1') {
		it('records every world', async () => {
			const record: Record<string, unknown> = {}
			for (const [name, world] of Object.entries(WORLDS)) record[name] = await trace(world)
			writeFileSync(GOLDEN, JSON.stringify(record, null, '\t') + '\n')
		})
		return
	}

	const golden = JSON.parse(readFileSync(GOLDEN, 'utf8')) as Record<string, unknown[]>

	it('covers every recorded world', () => {
		expect(Object.keys(golden).sort()).toEqual(Object.keys(WORLDS).sort())
	})

	for (const [name, world] of Object.entries(WORLDS)) {
		it(`answers as recorded: ${name}`, async () => {
			const answers = await trace(world)
			SEQUENCE.forEach((message, index) => expect([index, answers[index]]).toEqual([index, golden[name]![index]]))
		})
	}
})
