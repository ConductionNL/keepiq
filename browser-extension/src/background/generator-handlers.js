/**
 * Worker handlers for the Generator tab: its context (policy, options,
 * history, the active site and the account's email), saving options, and
 * the history. None needs the vault key, so the tab works while locked and,
 * with the cached policy, while the server is unreachable.
 *
 * Options live in `storage.local` per account and go when the account goes.
 * History lives in `storage.session` (memory on browsers without it) and goes
 * on lock, on account removal and when the browser or extension restarts.
 *
 * @spec openspec/specs/extension-generator/spec.md#requirement-generator-history
 */

import { addToHistory, sanitizeOptions } from '../lib/generator-state.js'

const OPTIONS_KEY = (id) => 'generator-options:' + id
const POLICY_KEY = (id) => 'generator-policy:' + id
const EMAIL_KEY = (id) => 'generator-email:' + id
const HISTORY_KEY = (id) => 'generator-history:' + id

/**
 * A storage area with get/set/remove, falling back to memory.
 *
 * @param {object|null} area A chrome.storage area, or null.
 * @return {{get: Function, set: Function, remove: Function}}
 */
export function areaOrMemory(area) {
	if (area) return area
	const memory = new Map()
	return {
		get: async (key) => (memory.has(key) ? { [key]: memory.get(key) } : {}),
		set: async (items) => {
			for (const [k, v] of Object.entries(items)) memory.set(k, v)
		},
		remove: async (key) => {
			memory.delete(key)
		},
	}
}

/**
 * Build the handlers.
 *
 * @param {object} deps The collaborators.
 * @param {object} deps.api The API client (fetchPolicy, fetchAccountEmail).
 * @param {() => Promise<object>} deps.activeAccount The active, paired account.
 * @param {(payload?: {tabId?: number}) => Promise<string>} deps.activeHost The target tab's host name, or ''.
 * @param {object} deps.local The persistent storage area.
 * @param {object} deps.session The session storage area.
 * @return {{handlers: Record<string, Function>, forget: (accountId: string) => Promise<void>, clearHistory: (accountId: string) => Promise<void>}}
 */
export function buildGeneratorHandlers({
	api,
	activeAccount,
	activeHost,
	local,
	session,
}) {
	/**
	 * The org policy: live when the server answers, else the last one seen.
	 *
	 * @param {object} account The account.
	 * @return {Promise<object|null>}
	 */
	async function policyFor(account) {
		try {
			const policy = await api.fetchPolicy(account)
			await local.set({ [POLICY_KEY(account.id)]: policy ?? null })
			return policy ?? null
		} catch {
			return (
				(await local.get(POLICY_KEY(account.id)))[POLICY_KEY(account.id)]
				?? null
			)
		}
	}

	/**
	 * The account's email, fetched once and kept.
	 *
	 * @param {object} account The account.
	 * @return {Promise<string>}
	 */
	async function emailFor(account) {
		const cached = (await local.get(EMAIL_KEY(account.id)))[
			EMAIL_KEY(account.id)
		]
		if (typeof cached === 'string') return cached
		try {
			const email = await api.fetchAccountEmail(account)
			await local.set({ [EMAIL_KEY(account.id)]: email })
			return email
		} catch {
			return ''
		}
	}

	/** @param {string} id The account id. @return {Promise<Array<object>>} */
	const historyOf = async (id) =>
		(await session.get(HISTORY_KEY(id)))[HISTORY_KEY(id)] || []

	// The pending history write per account, so writes run in order.
	const historyWrites = new Map()

	const clearHistory = async (accountId) => {
		await session.remove(HISTORY_KEY(accountId))
	}

	return {
		clearHistory,
		async forget(accountId) {
			await Promise.all([
				local.remove(OPTIONS_KEY(accountId)),
				local.remove(POLICY_KEY(accountId)),
				local.remove(EMAIL_KEY(accountId)),
				clearHistory(accountId),
			])
		},
		handlers: {
			/**
			 * Everything the Generator tab needs to open.
			 *
			 * @spec openspec/specs/extension-generator/spec.md#requirement-works-while-locked-and-offline
			 */
			'generator-context': async (payload = {}) => {
				const account = await activeAccount()
				const policy = await policyFor(account)
				const stored = (await local.get(OPTIONS_KEY(account.id)))[
					OPTIONS_KEY(account.id)
				]
				const options = sanitizeOptions(stored, policy)
				if (!options.username.email) {
					options.username.email = await emailFor(account)
				}
				return {
					policy,
					options,
					history: await historyOf(account.id),
					website: await activeHost(payload).catch(() => ''),
				}
			},

			/**
			 * Keep the options for the next time.
			 *
			 * @spec openspec/specs/extension-generator/spec.md#requirement-options-remembered-per-account
			 */
			'generator-options-save': async (payload) => {
				const account = await activeAccount()
				const policy =
					(await local.get(POLICY_KEY(account.id)))[POLICY_KEY(account.id)]
					?? null
				const options = sanitizeOptions(payload.options, policy)
				await local.set({ [OPTIONS_KEY(account.id)]: options })
				return { options }
			},

			/**
			 * Add a generated value to the history.
			 *
			 * @spec openspec/specs/extension-generator/spec.md#requirement-generator-history
			 */
			'generator-history-add': async (payload) => {
				const account = await activeAccount()
				// One write at a time per account: values generated in quick
				// succession would otherwise read the same list and drop each
				// other.
				const previous = historyWrites.get(account.id) || Promise.resolve()
				const next = previous.then(async () => {
					const history = addToHistory(
						await historyOf(account.id),
						payload,
					)
					await session.set({ [HISTORY_KEY(account.id)]: history })
					return history
				})
				historyWrites.set(
					account.id,
					next.catch(() => {}),
				)
				return { history: await next }
			},

			/** @spec openspec/specs/extension-generator/spec.md#requirement-generator-history */
			'generator-history-clear': async () => {
				const account = await activeAccount()
				await clearHistory(account.id)
				return { history: [] }
			},
		},
	}
}
