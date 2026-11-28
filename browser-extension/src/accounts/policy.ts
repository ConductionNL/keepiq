import { createQueue } from '@/src/queue'

/** The organisation's rules for this account, as last read (ADR-002). Kept through a logout, so a new login is never unclamped. */
export interface AccountPolicy {
	/** `null` until the first read. */
	maxIdleMinutes: number | null
	exportDisabled: boolean
}

export const accountPolicyKey = (accountId: string) => `accountPolicy.${accountId}`

/** Any positive whole number of minutes, so a maximum Keepiq adds later needs no extension release. */
export const isMaximum = (value: unknown): value is number => typeof value === 'number' && Number.isInteger(value) && value > 0

export async function readAccountPolicy(accountId: string): Promise<AccountPolicy> {
	const stored = (await browser.storage.local.get(accountPolicyKey(accountId)))[accountPolicyKey(accountId)] as Partial<Record<keyof AccountPolicy, unknown>> | undefined
	return {
		maxIdleMinutes: isMaximum(stored?.maxIdleMinutes) ? stored.maxIdleMinutes : null,
		exportDisabled: stored?.exportDisabled === true,
	}
}

const queued = createQueue()

/** Values that fail validation keep what is stored. */
export function patchAccountPolicy(accountId: string, patch: Partial<AccountPolicy>): Promise<void> {
	return queued(async () => {
		const policy = await readAccountPolicy(accountId)
		if (isMaximum(patch.maxIdleMinutes)) policy.maxIdleMinutes = patch.maxIdleMinutes
		if (typeof patch.exportDisabled === 'boolean') policy.exportDisabled = patch.exportDisabled
		await browser.storage.local.set({ [accountPolicyKey(accountId)]: policy })
	})
}

export async function clearAccountPolicy(accountId: string): Promise<void> {
	await browser.storage.local.remove(accountPolicyKey(accountId))
}
