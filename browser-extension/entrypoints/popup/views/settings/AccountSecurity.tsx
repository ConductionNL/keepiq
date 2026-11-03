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
import { formatDuration, timeoutChoice, timeoutOf, timeoutOptions, type TimeoutChoice } from './timeout'

/** Bitwarden's Settings → Account security, without two-step login and the fingerprint phrase (proposal). */
export function AccountSecurity({ account }: { account: AccountSummary }) {
	const { accountId, refreshState } = useShell()
	const { snapshot, failed, update, reload } = useAccountSettings()
	const [pinDialog, setPinDialog] = useState(false)
	const [actionError, setActionError] = useState<string | null>(null)
	const logOut = useLogOutConfirm()

	if (!snapshot) return failed ? <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner> : <Loading />

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

	return (
		<div className="stack">
			{failed && <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner>}
			<div className="card form-card">
				<OptionCheckbox label={i18n.t('settings.pin')} checked={snapshot.pin} onChange={(on) => (on ? setPinDialog(true) : void removePin())} hint={i18n.t('settings.pinAfterRestart')} />
				<OptionCheckbox label={i18n.t('settings.biometrics')} checked={false} onChange={() => {}} lockedBy={i18n.t('settings.notAvailableYet')} />
			</div>
			<div className="card form-card">
				<TimeoutControl snapshot={snapshot} update={update} />
				<Select<VaultTimeoutAction>
					label={i18n.t('settings.timeoutAction')}
					value={snapshot.values.vaultTimeoutAction}
					options={[{ value: 'lock', label: i18n.t('settings.actionLock') }, { value: 'logout', label: i18n.t('common.logOut') }]}
					onChange={(vaultTimeoutAction) => void update({ vaultTimeoutAction })}
					hint={snapshot.values.vaultTimeoutAction === 'logout' ? i18n.t('settings.actionLogOutNote') : undefined}
				/>
			</div>
			<ErrorBanner>{actionError}</ErrorBanner>
			<ul className="card menu">
				<li>
					<button type="button" className="menu__item" onClick={() => void act({ kind: 'vault.lock', accountId })}>
						<Icon name="lock" />
						<span className="menu__label">{i18n.t('settings.lockNow')}</span>
					</button>
				</li>
				<li>
					{logOut.confirming
						? <LogOutConfirm account={account} onConfirm={() => void act({ kind: 'accounts.remove', accountId })} onCancel={logOut.cancel} />
						: (
							<button type="button" className="menu__item" onClick={logOut.ask} autoFocus={logOut.triggerAutoFocus}>
								<Icon name="logOut" />
								<span className="menu__label">{i18n.t('common.logOut')}</span>
							</button>
						)}
				</li>
				<li>
					<a className="menu__item" href={snapshot.webAppUrl} target="_blank" rel="noreferrer">
						<Icon name="key" />
						<span className="menu__label">{i18n.t('settings.changeMasterPassword')}</span>
						<Icon name="external" />
					</a>
				</li>
			</ul>
			{pinDialog && (
				<PinDialog
					onDone={() => {
						setPinDialog(false)
						void reload()
					}}
					onCancel={() => setPinDialog(false)}
				/>
			)}
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
	const [custom, setCustom] = useState<{ hours: string; minutes: string } | null>(null)
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
		const parts = [custom?.hours ?? '', custom?.minutes ?? ''].map((part) => part.trim() === '' ? 0 : Number(part))
		if (!parts.every((part) => Number.isInteger(part) && part >= 0)) return setCustomError(i18n.t('settings.customWhole'))
		const total = parts[0]! * 60 + parts[1]!
		if (total < 1) return setCustomError(i18n.t('settings.customAtLeastOne'))
		if (maxIdleMinutes !== null && total > maxIdleMinutes) return setCustomError(i18n.t('settings.customAtMost', { duration: formatDuration(maxIdleMinutes) }))
		setCustomError(null)
		if (await update({ vaultTimeout: total })) setCustom(null)
	}

	// One error under both parts, so each names it.
	const invalid = customError ? { 'aria-invalid': true, 'aria-describedby': errorId } as const : {}

	return (
		<>
			<Select<TimeoutChoice>
				label={i18n.t('settings.vaultTimeout')}
				value={choice}
				options={timeoutOptions(maxIdleMinutes, effectiveTimeout)}
				onChange={choose}
				hint={values.vaultTimeout !== effectiveTimeout ? i18n.t('settings.setByOrganisation') : undefined}
			/>
			{custom && (
				<form className="custom-timeout" noValidate onSubmit={(event) => void saveCustom(event)}>
					<div className="custom-timeout__fields">
						<TextField label={i18n.t('settings.hours')} type="number" inputMode="numeric" min={0} value={custom.hours} onChange={(hours) => setCustom({ ...custom, hours })} {...invalid} />
						<TextField label={i18n.t('settings.minutes')} type="number" inputMode="numeric" min={0} max={59} value={custom.minutes} onChange={(minutes) => setCustom({ ...custom, minutes })} {...invalid} />
					</div>
					{customError && <p className="field__error" id={errorId} role="alert">{customError}</p>}
					<Button type="submit" variant="secondary">{i18n.t('form.save')}</Button>
				</form>
			)}
			{confirmNever && (
				<ConfirmDialog
					title={i18n.t('settings.neverTitle')}
					actions={[{
						label: i18n.t('settings.neverConfirm'),
						variant: 'danger',
						onClick: () => {
							setConfirmNever(false)
							setCustom(null)
							void update({ vaultTimeout: 'never' })
						},
					}]}
					onCancel={() => setConfirmNever(false)}
					focusCancel
				>
					<p>{i18n.t('settings.neverWarning')}</p>
				</ConfirmDialog>
			)}
		</>
	)
}
