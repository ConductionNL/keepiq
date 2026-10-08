import { vi } from 'vitest'
import type { AccountSummary, PopupState, Result } from '@/src/messages'

export function account(overrides: Partial<AccountSummary> = {}): AccountSummary {
	return {
		id: 'a1',
		origin: 'https://cloud.example.org',
		host: 'cloud.example.org',
		uid: 'alice',
		displayName: 'Alice Doe',
		avatarDataUrl: null,
		status: 'locked',
		active: true,
		...overrides,
	}
}

export function popupState(overrides: Partial<PopupState> = {}): PopupState {
	const active = overrides.active === undefined ? account() : overrides.active
	return {
		screen: 'unlock',
		accounts: active ? [active] : [],
		active,
		notice: null,
		canAddAccount: true,
		...overrides,
	}
}

export const ok = (state: PopupState = popupState()): Result => ({ ok: true, state })

/** A dispatch spy that answers every message with `result`. */
export function dispatchReturning(result: Result = ok()) {
	return vi.fn(async () => result)
}
