import { createQueue } from '@/src/queue'
import { CLEAR_CLIPBOARD_OPTIONS, URI_MATCHES, type AutofillFeature, type UriMatch } from '@/src/messages'
import { pickValid, type Validators } from '@/src/settings/pick-valid'
import { baseDomain } from '@/src/vault/match'
import { readAccountPolicy } from './policy'

/** Minutes, or one of the untimed options. Custom is any other number of minutes. */
export type VaultTimeout = number | 'immediately' | 'onSystemLock' | 'onRestart' | 'never'
export type VaultTimeoutAction = 'lock' | 'logout'
export type DefaultAutofill = 'onPageLoad' | 'never'

export interface AccountSettings {
	vaultTimeout: VaultTimeout
	vaultTimeoutAction: VaultTimeoutAction
	clearClipboardMs: number
	autofillOnPageLoad: boolean
	defaultAutofill: DefaultAutofill
	uriMatch: UriMatch
	inlineSuggestions: boolean
	contextMenu: boolean
	askToAdd: boolean
	askToUpdate: boolean
	/** Base domains (`baseDomain` in `src/vault/match.ts`). */
	excludedDomains: string[]
	/** A secret type name; one the account's cached types lack reads as `login` where it is used. */
	defaultItemType: string
}

export const DEFAULT_SETTINGS: AccountSettings = {
	vaultTimeout: 15,
	vaultTimeoutAction: 'lock',
	clearClipboardMs: 30_000,
	autofillOnPageLoad: false,
	defaultAutofill: 'onPageLoad',
	uriMatch: 'baseDomain',
	inlineSuggestions: true,
	contextMenu: true,
	askToAdd: true,
	askToUpdate: true,
	excludedDomains: [],
	defaultItemType: 'login',
}

/** The delivered `AutofillFeature`s, sent to Settings in the snapshot. */
export const AUTOFILL_FEATURES: AutofillFeature[] = ['pageLoad', 'uriMatch', 'inlineSuggestions', 'contextMenu', 'savePrompts']

export const settingsKey = (accountId: string) => `settings.${accountId}`

const isBoolean = (value: unknown): value is boolean => typeof value === 'boolean'
const oneOf = <T>(options: readonly T[]) => (value: unknown): value is T => options.includes(value as T)

function isTimeout(value: unknown): value is VaultTimeout {
	return (typeof value === 'number' && Number.isInteger(value) && value > 0)
		|| value === 'immediately' || value === 'onSystemLock' || value === 'onRestart' || value === 'never'
}

/** Distinct base domains, by the rule ext-autofill matches and excludes with. */
function isDomainList(value: unknown): value is string[] {
	return Array.isArray(value) && value.every((d) => typeof d === 'string' && baseDomain(`https://${d}`) === d) && new Set(value).size === value.length
}

const VALIDATORS: Validators<AccountSettings> = {
	vaultTimeout: isTimeout,
	vaultTimeoutAction: oneOf(['lock', 'logout'] as const),
	clearClipboardMs: oneOf<number>(CLEAR_CLIPBOARD_OPTIONS),
	autofillOnPageLoad: isBoolean,
	defaultAutofill: oneOf(['onPageLoad', 'never'] as const),
	uriMatch: oneOf(URI_MATCHES),
	inlineSuggestions: isBoolean,
	contextMenu: isBoolean,
	askToAdd: isBoolean,
	askToUpdate: isBoolean,
	excludedDomains: isDomainList,
	defaultItemType: (value): value is string => typeof value === 'string' && value !== '',
}

async function readStored(accountId: string): Promise<Record<string, unknown>> {
	const stored = (await browser.storage.local.get(settingsKey(accountId)))[settingsKey(accountId)]
	return typeof stored === 'object' && stored !== null ? stored as Record<string, unknown> : {}
}

/** Invalid stored values read as their default. */
export async function readSettings(accountId: string): Promise<AccountSettings> {
	return { ...structuredClone(DEFAULT_SETTINGS), ...pickValid(VALIDATORS, await readStored(accountId)) }
}

/** One write at a time, so two patches never undo each other. */
const queued = createQueue()

/**
 * Merges the valid part of `patch` into the stored record; the rest is dropped.
 * Once an account may be unlocked, go through `updateSettings` in the store so the "Never" key follows.
 */
export function writeSettings(accountId: string, patch: Partial<AccountSettings>): Promise<void> {
	return queued(async () => {
		await browser.storage.local.set({ [settingsKey(accountId)]: { ...await readStored(accountId), ...pickValid(VALIDATORS, patch) } })
	})
}

/**
 * The save bar's "Never for this site": read and written in the same queue as every other patch, so a popup edit
 * at the same moment is not lost. The excluded list has no bearing on the "Never" key, so no `updateSettings`.
 */
export function addExcludedDomain(accountId: string, domain: string): Promise<void> {
	return queued(async () => {
		const stored = await readStored(accountId)
		const current = pickValid(VALIDATORS, stored).excludedDomains ?? []
		if (current.includes(domain)) return
		await browser.storage.local.set({ [settingsKey(accountId)]: { ...stored, ...pickValid(VALIDATORS, { excludedDomains: [...current, domain] }) } })
	})
}

/** The timeout under the organisation's idle maximum (ADR-002). Immediately and On system lock stay, and `enforce()` also locks them at the maximum. */
export function clampTimeout(timeout: VaultTimeout, maxIdleMinutes: number | null): VaultTimeout {
	if (maxIdleMinutes === null || timeout === 'immediately' || timeout === 'onSystemLock') return timeout
	return typeof timeout === 'number' ? Math.min(timeout, maxIdleMinutes) : maxIdleMinutes
}

/** The settings after the organisation maximum; everything that acts on a timeout reads these. */
export async function effectiveSettings(accountId: string): Promise<AccountSettings & { maxIdleMinutes: number | null }> {
	const settings = await readSettings(accountId)
	const { maxIdleMinutes } = await readAccountPolicy(accountId)
	return { ...settings, vaultTimeout: clampTimeout(settings.vaultTimeout, maxIdleMinutes), maxIdleMinutes }
}
