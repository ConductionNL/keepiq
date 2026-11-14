// A pick from the inline list on a code field (spec `totp-autofill`): the login's code, computed at the pick, to the list's frame alone.
import { readSettings } from '@/src/accounts/settings'
import type { OpenSecretRow } from '@/src/api/types'
import { recordLastUsed } from '@/src/last-used'
import type { FillExecute, FillResult } from '@/src/messages'
import { getKey } from '@/src/vault/key-store'
import { readSnapshot } from '@/src/vault/store'
import type { VaultSnapshot } from '@/src/vault/types'
import { activeUnlockedId, combine, fits, isInsecure, NONE } from './fill'
import { currentCode, hasCode, seedOf } from './login-codes'

export interface CodeTarget {
	itemId: string
	tabId: number
	confirmedInsecure: boolean
	/** The list's frame, its URL from the session, and the list's key. */
	only: { frameId: number; url: string; anchorKey: string }
}

/** One pick of a code: the unlocked account, its vault, the login and the list's frame. */
interface CodePick {
	accountId: string
	vault: VaultSnapshot
	row: OpenSecretRow
	frame: { frameId: number; url: string }
	target: CodeTarget
}

/** No copy: the user picked the code to have it filled here. */
export async function pickCode(target: CodeTarget): Promise<FillResult> {
	const pick = await codePick(target)
	if (!pick) return NONE
	const refusal = await refusalOf(pick)
	if (refusal) return refusal
	const code = await codeNow(pick)
	if (code === null) return NONE
	return fillCode(pick, code)
}

/** The login the list offered, when the account is unlocked and the login is there, readable and holds a code. */
async function codePick(target: CodeTarget): Promise<CodePick | null> {
	const accountId = await activeUnlockedId()
	const vault = accountId ? await readSnapshot(accountId) : undefined
	if (!accountId || !vault) return null
	const row = codeRowOf(vault, target.itemId)
	if (!row) return null
	return { accountId, vault, row, frame: { frameId: target.only.frameId, url: target.only.url }, target }
}

function codeRowOf(vault: VaultSnapshot, itemId: string): OpenSecretRow | null {
	const row = vault.secrets.find((secret) => secret.id === itemId)
	return row && !row.blocked && hasCode(row) ? row : null
}

/** A login that no longer matches the frame fills nothing; an insecure frame asks first. */
async function refusalOf({ accountId, vault, row, frame, target }: CodePick): Promise<FillResult | null> {
	if (!fits(row, vault.types, (await readSettings(accountId)).uriMatch, frame.url)) return NONE
	if (!target.confirmedInsecure && isInsecure(row, [frame])) return { ...NONE, needsInsecureConfirm: true }
	return null
}

/** The login's code at this moment, or `null` without the key or a seed that reads. */
async function codeNow({ accountId, row }: CodePick): Promise<string | null> {
	const key = await getKey(accountId)
	if (!key) return null
	const seed = await seedOf(row, key)
	return seed === null ? null : (await currentCode(seed))?.digits ?? null
}

async function fillCode({ accountId, row, frame, target }: CodePick, code: string): Promise<FillResult> {
	const message: FillExecute = {
		kind: 'fill.execute', username: null, password: null, code, origin: new URL(frame.url).origin, anchorKey: target.only.anchorKey,
	}
	const result = combine([await browser.tabs.sendMessage(target.tabId, message, { frameId: frame.frameId }).catch(() => null)])
	if (result.code) await recordLastUsed(accountId, row.id)
	return result
}
