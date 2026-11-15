import { useId, useState, type FormEvent } from 'react'
import { i18n } from '#i18n'
import type { VaultTimeoutAction } from '@/src/accounts/settings'
import type { AccountSettingsSnapshot, AccountSummary } from '@/src/messages'
import { Button } from '../../components/Button'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { ErrorBanner } from '../../components/ErrorBanner'
import { Icon } from '../../components/Icon'
import { Loading } from '../../components/Loading'
import { LogOutConfirm, useLogOutConfirm } from '../../components/LogOutConfirm'
import { OptionCheckbox } from '../../components/OptionCheckbox'
import { PinDialog } from '../../components/PinDialog'
import { Select } from '../../components/Select'
import { TextField } from '../../components/TextField'
import { errorText } from '../../errors'
import { useAccountSettings } from '../../hooks/useAccountSettings'
import { request, sendMessage } from '../../hooks/useMessage'
import { useShell } from '../../shell-context'
import { openVaultPasskeyWindow, supportText, usePasskeySupport } from '../../vault-passkey'
import { formatDuration, timeoutChoice, timeoutOf, timeoutOptions, type TimeoutChoice } from './timeout'

type AccountSettings = ReturnType<typeof useAccountSettings>
type PasskeySupport = ReturnType<typeof usePasskeySupport>

/** Lock now and Log out, with the reason the last one failed. */
function useAccountActions(account: AccountSummary, reload: AccountSettings['reload']) {
	const { accountId, refreshState } = useShell()
	const [actionError, setActionError] = useState<string | null>(null)

	async function removePin() {
		await request({ kind: 'pin.remove', accountId })
		await reload()
	}

	async function act(message: Parameters<typeof sendMessage>[0]) {
		setActionError(null)
		const result = await sendMessage(message)
		if (result.ok) refreshState()
		else setActionError(errorText(result.code, account.host))
	}

	return { accountId, actionError, removePin, act }
}

/** Why passkey unlock cannot be switched here: enrolled ones are revoked in the web app, and some browsers cannot run it. */
function passkeyLockedBy(enrolled: boolean, support: PasskeySupport): string | undefined {
	if (enrolled) return i18n.t('vaultPasskey.revokeInWebApp')
	return support === null ? undefined : supportText(support) ?? undefined
}

interface UnlockProps {
	account: AccountSummary
	accountId: string
	pin: boolean
	support: PasskeySupport
	onPin: (on: boolean) => void
}

function UnlockOptions({ account, accountId, pin, support, onPin }: UnlockProps) {
	return (
		<div className="card form-card">
			<OptionCheckbox label={i18n.t('settings.pin')} checked={pin} onChange={onPin} hint={i18n.t('settings.pinAfterRestart')} />
			<OptionCheckbox
				label={i18n.t('vaultPasskey.unlock')}
				checked={account.passkeyUnlock}
				onChange={(on) => on && void openVaultPasskeyWindow(accountId, 'enrol')}
				lockedBy={passkeyLockedBy(account.passkeyUnlock, support)}
				disabled={support === null}
			/>
		</div>
	)
}

function TimeoutActionSelect({ snapshot, update }: TimeoutProps) {
	return (
		<Select<VaultTimeoutAction>
			label={i18n.t('settings.timeoutAction')}
			value={snapshot.values.vaultTimeoutAction}
			options={[{ value: 'lock', label: i18n.t('settings.actionLock') }, { value: 'logout', label: i18n.t('common.logOut') }]}
			onChange={(vaultTimeoutAction) => void update({ vaultTimeoutAction })}
			hint={snapshot.values.vaultTimeoutAction === 'logout' ? i18n.t('settings.actionLogOutNote') : undefined}
		/>
	)
}

interface AccountMenuProps {
	account: AccountSummary
	webAppUrl: string
	actions: ReturnType<typeof useAccountActions>
	logOut: ReturnType<typeof useLogOutConfirm>
}

function LogOutItem({ account, actions, logOut }: Omit<AccountMenuProps, 'webAppUrl'>) {
	if (logOut.confirming) return <LogOutConfirm account={account} onConfirm={() => void actions.act({ kind: 'accounts.remove', accountId: actions.accountId })} onCancel={logOut.cancel} />
	return (
		<button type="button" className="menu__item" onClick={logOut.ask} autoFocus={logOut.triggerAutoFocus}>
			<Icon name="logOut" />
			<span className="menu__label">{i18n.t('common.logOut')}</span>
		</button>
	)
}

function AccountMenu({ account, webAppUrl, actions, logOut }: AccountMenuProps) {
	return (
		<ul className="card menu">
			<li>
				<button type="button" className="menu__item" onClick={() => void actions.act({ kind: 'vault.lock', accountId: actions.accountId })}>
					<Icon name="lock" />
					<span className="menu__label">{i18n.t('settings.lockNow')}</span>
				</button>
			</li>
			<li><LogOutItem account={account} actions={actions} logOut={logOut} /></li>
			<li>
				<a className="menu__item" href={webAppUrl} target="_blank" rel="noreferrer">
					<Icon name="key" />
					<span className="menu__label">{i18n.t('settings.changeMasterPassword')}</span>
					<Icon name="external" />
				</a>
			</li>
		</ul>
	)
}

/** Bitwarden's Settings → Account security, without two-step login and the fingerprint phrase (proposal). */
export function AccountSecurity({ account }: { account: AccountSummary }) {
	const { snapshot, failed, update, reload } = useAccountSettings()
	const [pinDialog, setPinDialog] = useState(false)
	const actions = useAccountActions(account, reload)
	const logOut = useLogOutConfirm()
	const support = usePasskeySupport()

	if (!snapshot) return failed ? <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner> : <Loading />
	const onPin = (on: boolean) => (on ? setPinDialog(true) : void actions.removePin())
	const pinDone = () => {
		setPinDialog(false)
		void reload()
	}

	return (
		<div className="stack">
			{failed && <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner>}
			<UnlockOptions account={account} accountId={actions.accountId} pin={snapshot.pin} support={support} onPin={onPin} />
			<div className="card form-card">
				<TimeoutControl snapshot={snapshot} update={update} />
				<TimeoutActionSelect snapshot={snapshot} update={update} />
			</div>
			<ErrorBanner>{actions.actionError}</ErrorBanner>
			<AccountMenu account={account} webAppUrl={snapshot.webAppUrl} actions={actions} logOut={logOut} />
			{pinDialog && <PinDialog onDone={pinDone} onCancel={() => setPinDialog(false)} />}
		</div>
	)
}

interface TimeoutProps {
	snapshot: AccountSettingsSnapshot
	update: ReturnType<typeof useAccountSettings>['update']
}

/** The choice under the organisation maximum; one above it stays stored and returns when the maximum rises. */
function TimeoutControl({ snapshot, update }: TimeoutProps) {
	const { values, effectiveTimeout, maxIdleMinutes } = snapshot
	const [custom, setCustom] = useState<CustomTimeout | null>(null)
	const [customError, setCustomError] = useState<string | null>(null)
	const [confirmNever, setConfirmNever] = useState(false)
	const errorId = useId()
	const choice = custom ? 'custom' : timeoutChoice(effectiveTimeout)

	function choose(next: TimeoutChoice) {
		setCustomError(null)
		if (next === 'never') return setConfirmNever(true)
		if (next === 'custom') {
			const minutes = typeof effectiveTimeout === 'number' ? effectiveTimeout : 0
			return setCustom({ hours: String(Math.floor(minutes / 60)), minutes: String(minutes % 60) })
		}
		setCustom(null)
		void update({ vaultTimeout: timeoutOf(next) })
	}

	async function saveCustom(event: FormEvent) {
		event.preventDefault()
		const read = readCustom(custom, maxIdleMinutes)
		if ('error' in read) return setCustomError(read.error)
		setCustomError(null)
		if (await update({ vaultTimeout: read.total })) setCustom(null)
	}

	function confirmedNever() {
		setConfirmNever(false)
		setCustom(null)
		void update({ vaultTimeout: 'never' })
	}

	return (
		<>
			<Select<TimeoutChoice>
				label={i18n.t('settings.vaultTimeout')}
				value={choice}
				options={timeoutOptions(maxIdleMinutes, effectiveTimeout)}
				onChange={choose}
				hint={values.vaultTimeout !== effectiveTimeout ? i18n.t('settings.setByOrganisation') : undefined}
			/>
			{custom && <CustomTimeoutForm custom={custom} setCustom={setCustom} error={customError} errorId={errorId} onSubmit={(event) => void saveCustom(event)} />}
			{confirmNever && <NeverConfirm onConfirm={confirmedNever} onCancel={() => setConfirmNever(false)} />}
		</>
	)
}

interface CustomTimeout {
	hours: string
	minutes: string
}

function customParts(custom: CustomTimeout | null): number[] {
	return [custom?.hours ?? '', custom?.minutes ?? ''].map((part) => part.trim() === '' ? 0 : Number(part))
}

/** The custom timeout in minutes, or why it is not one. */
function readCustom(custom: CustomTimeout | null, maxIdleMinutes: number | null): { total: number } | { error: string } {
	const parts = customParts(custom)
	if (!parts.every((part) => Number.isInteger(part) && part >= 0)) return { error: i18n.t('settings.customWhole') }
	const total = parts[0]! * 60 + parts[1]!
	if (total < 1) return { error: i18n.t('settings.customAtLeastOne') }
	if (maxIdleMinutes !== null && total > maxIdleMinutes) return { error: i18n.t('settings.customAtMost', { duration: formatDuration(maxIdleMinutes) }) }
	return { total }
}

interface CustomFormProps {
	custom: CustomTimeout
	setCustom: (custom: CustomTimeout) => void
	error: string | null
	errorId: string
	onSubmit: (event: FormEvent) => void
}

function CustomTimeoutForm({ custom, setCustom, error, errorId, onSubmit }: CustomFormProps) {
	// One error under both parts, so each names it.
	const invalid = error ? { 'aria-invalid': true, 'aria-describedby': errorId } as const : {}
	return (
		<form className="custom-timeout" noValidate onSubmit={onSubmit}>
			<div className="custom-timeout__fields">
				<TextField label={i18n.t('settings.hours')} type="number" inputMode="numeric" min={0} value={custom.hours} onChange={(hours) => setCustom({ ...custom, hours })} {...invalid} />
				<TextField label={i18n.t('settings.minutes')} type="number" inputMode="numeric" min={0} max={59} value={custom.minutes} onChange={(minutes) => setCustom({ ...custom, minutes })} {...invalid} />
			</div>
			{error && <p className="field__error" id={errorId} role="alert">{error}</p>}
			<Button type="submit" variant="secondary">{i18n.t('form.save')}</Button>
		</form>
	)
}

function NeverConfirm({ onConfirm, onCancel }: { onConfirm: () => void; onCancel: () => void }) {
	return (
		<ConfirmDialog title={i18n.t('settings.neverTitle')} actions={[{ label: i18n.t('settings.neverConfirm'), variant: 'danger', onClick: onConfirm }]} onCancel={onCancel} focusCancel>
			<p>{i18n.t('settings.neverWarning')}</p>
		</ConfirmDialog>
	)
}
