// A pick from the inline list on a code field (spec `totp-autofill`): the login's code, computed at the pick, to the list's frame alone.
import { readSettings } from '@/src/accounts/settings'
import { recordLastUsed } from '@/src/last-used'
import type { FillExecute, FillResult } from '@/src/messages'
import { getKey } from '@/src/vault/key-store'
import { readSnapshot } from '@/src/vault/store'
import { activeUnlockedId, combine, fits, isInsecure, NONE } from './fill'
import { currentCode, hasCode, seedOf } from './login-codes'

export interface CodeTarget {
	itemId: string
	tabId: number
	confirmedInsecure: boolean
	/** The list's frame, its URL from the session, and the list's key. */
	only: { frameId: number; url: string; anchorKey: string }
}

/** No copy: the user picked the code to have it filled here. */
export async function pickCode(target: CodeTarget): Promise<FillResult> {
	const accountId = await activeUnlockedId()
	const vault = accountId ? await readSnapshot(accountId) : undefined
	const row = vault?.secrets.find((secret) => secret.id === target.itemId)
	if (!accountId || !vault || !row || row.blocked || !hasCode(row)) return NONE
	const frame = { frameId: target.only.frameId, url: target.only.url }
	if (!fits(row, vault.types, (await readSettings(accountId)).uriMatch, frame.url)) return NONE
	if (!target.confirmedInsecure && isInsecure(row, [frame])) return { ...NONE, needsInsecureConfirm: true }
	const key = await getKey(accountId)
	if (!key) return NONE
	const seed = await seedOf(row, key)
	const code = seed === null ? null : (await currentCode(seed))?.digits ?? null
	if (code === null) return NONE
	const message: FillExecute = {
		kind: 'fill.execute', username: null, password: null, code, origin: new URL(frame.url).origin, anchorKey: target.only.anchorKey,
	}
	const result = combine([await browser.tabs.sendMessage(target.tabId, message, { frameId: frame.frameId }).catch(() => null)])
	if (result.code) await recordLastUsed(accountId, row.id)
	return result
}
