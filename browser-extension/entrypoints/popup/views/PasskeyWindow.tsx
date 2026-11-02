import { useCallback, useEffect, useId, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { i18n } from '#i18n'
import type {
	AccountSummary, ErrorCode, PasskeyFailure, PasskeyPopupRequest, PasskeyRow, PasskeySaveTarget, PasskeyView, PasskeyWindowReply, UnlockMethod, VaultWriteError,
} from '@/src/messages'
import { Button } from '../components/Button'
import { ConfirmDialog } from '../components/ConfirmDialog'
import { Banner, ErrorBanner } from '../components/ErrorBanner'
import { Header } from '../components/Header'
import { Identity } from '../components/Identity'
import { Loading } from '../components/Loading'
import { Select } from '../components/Select'
import { TextField } from '../components/TextField'
import { errorText, writeErrorText } from '../errors'
import { useBackgroundMessage } from '../hooks/useBackgroundMessage'
import { passkeyRequest, sendMessage } from '../hooks/useMessage'

type Command = PasskeyPopupRequest extends infer R ? (R extends unknown ? Omit<R, 'ceremonyId'> : never) : never
type Act = Extract<Command, { kind: 'passkey.pick' | 'passkey.save' }>
type Save = Extract<Command, { kind: 'passkey.save' }>['target']

const PICK_CODES: readonly PasskeyFailure[] = ['ended', 'locked', 'stale', 'write_failed']

/**
 * The window of one passkey ceremony (spec § The passkey window). The background decides what it may show and closes it when
 * the ceremony ends; `rpName` and `userName` are the page's own and only ever render as text.
 */
export function PasskeyWindow({ ceremonyId }: { ceremonyId: string }) {
	const [view, setView] = useState<PasskeyView | null>(null)
	const [account, setAccount] = useState<AccountSummary | null>(null)
	const [failure, setFailure] = useState<VaultWriteError | null>(null)
	const [notice, setNotice] = useState<string | null>(null)
	const [busy, setBusy] = useState<string | null>(null)
	/** A pick or save waiting on "Confirm it's you". */
	const [confirming, setConfirming] = useState<Act | null>(null)
	/** The inline list's pick the background holds for "Confirm it's you", and signs on `passkey.verify`. */
	const [held, setHeld] = useState<PasskeyRow | null>(null)
	const [replacing, setReplacing] = useState<PasskeySaveTarget | null>(null)
	const [name, setName] = useState<string | null>(null)
	const [folderId, setFolderId] = useState('')

	const send = useCallback((command: Command) => passkeyRequest({ ...command, ceremonyId } as PasskeyPopupRequest), [ceremonyId])

	const show = useCallback((next: PasskeyView) => {
		setView(next)
		setFailure(next.failure)
		setHeld(next.pending)
		// Nothing said about the vault before a lock stays on screen after it.
		if (next.locked) {
			setConfirming(null)
			setReplacing(null)
			setNotice(null)
		}
	}, [])

	// Passive: a window the page opened is no sign of the user, so it must not hold off the vault timeout.
	const load = useCallback(() => Promise.all([send({ kind: 'passkey.state' }), sendMessage({ kind: 'vault.status', passive: true })]), [send])

	const apply = useCallback(([reply, status]: Awaited<ReturnType<typeof load>>): PasskeyView | null => {
		if (status.ok) setAccount(status.state.active)
		if (reply?.ok && reply.view) {
			show(reply.view)
			return reply.view
		}
		if (reply?.ok === false && reply.code === 'ended') window.close()
		else setNotice(i18n.t('errors.notResponding'))
		return null
	}, [show])

	const refresh = useCallback(() => load().then(apply), [load, apply])

	useEffect(() => void load().then(apply), [load, apply])
	useBackgroundMessage('vault.locked', () => void refresh())
	useBackgroundMessage('vault.changed', () => void refresh())

	async function settle(reply: PasskeyWindowReply | undefined, command: Command): Promise<void> {
		if (!reply) return setNotice(i18n.t('errors.notResponding'))
		if (reply.ok) {
			if (reply.view) show(reply.view)
			else window.close()
			return
		}
		switch (reply.code) {
			case 'ended': return window.close()
			case 'write_failed': return setFailure(reply.error ?? { code: 'unknown' })
			case 'verify_required':
				if (command.kind === 'passkey.pick' || command.kind === 'passkey.save') setConfirming(command)
				return
			case 'stale':
				setNotice(view?.kind === 'create' ? i18n.t('passkey.staleSave') : i18n.t('passkey.stalePick'))
				await refresh()
				return
			case 'replace_required': {
				const next = await refresh()
				const itemId = command.kind === 'passkey.save' && 'itemId' in command.target ? command.target.itemId : null
				const target = next?.targets.find((candidate) => candidate.itemId === itemId)
				if (target?.replace) setReplacing(target)
				else setNotice(i18n.t('passkey.staleSave'))
				return
			}
			case 'locked':
			case 'already_saved':
				await refresh()
				return
			default: setNotice(errorText(reply.code, account?.host ?? '', reply.attemptsLeft))
		}
	}

	async function run(command: Command, control: string): Promise<void> {
		setBusy(control)
		setNotice(null)
		const reply = await send(command)
		setBusy(null)
		await settle(reply, command)
	}

	function act(command: Act, control: string) {
		if (view?.verify) setConfirming(command)
		else void run(command, control)
	}

	const pick = (row: PasskeyRow) => act({ kind: 'passkey.pick', itemId: row.itemId, credentialId: row.credentialId }, `pick:${row.itemId}:${row.credentialId}`)
	const save = (target: Save, control: string) => act({ kind: 'passkey.save', target }, control)

	async function verify(method: UnlockMethod): Promise<PasskeyWindowReply | undefined> {
		const reply = await send({ kind: 'passkey.verify', method })
		// The background signed the held pick, or answers its signing as it answers a pick.
		if (held && reply && (reply.ok ? !reply.view : PICK_CODES.includes(reply.code))) {
			setHeld(null)
			await settle(reply, { kind: 'passkey.pick', itemId: held.itemId, credentialId: held.credentialId })
			return { ok: true }
		}
		if (reply?.ok === false && reply.code === 'ended') window.close()
		if (!reply?.ok || !reply.view) return reply
		const pending = confirming
		setConfirming(null)
		show(reply.view)
		if (pending && !reply.view.locked && !reply.view.verify) await run(pending, 'confirm')
		return reply
	}

	// Any reply, or none: closing the window cancels too, so the page never waits on it.
	async function cancel() {
		setBusy('cancel')
		await send({ kind: 'passkey.cancel' })
		window.close()
	}

	async function fallback() {
		setBusy('fallback')
		const reply = await send({ kind: 'passkey.fallback' })
		setBusy(null)
		if (reply) window.close()
		else setNotice(i18n.t('errors.notResponding'))
	}

	const cancelRef = useRef(cancel)
	useEffect(() => {
		cancelRef.current = cancel
	})
	useEffect(() => {
		// The Replace question stops its own Escape, so this one is the window's.
		const onKey = (event: KeyboardEvent) => {
			if (event.key === 'Escape' && !event.defaultPrevented) void cancelRef.current()
		}
		document.addEventListener('keydown', onKey)
		return () => document.removeEventListener('keydown', onKey)
	}, [])

	const showFailure = view && !view.locked && !confirming && !held && !view.alreadySaved ? failure : null
	let title = ''
	let body: ReactNode
	let ways = true
	if (!view) {
		body = notice ? <ErrorBanner>{notice}</ErrorBanner> : <Loading />
	} else if (view.locked || confirming || held) {
		title = view.locked ? i18n.t('titles.unlock') : i18n.t('passkey.verifyTitle')
		const intro = view.locked
			? i18n.t(view.kind === 'get' ? 'passkey.unlockGet' : 'passkey.unlockCreate', { rpId: view.rpId })
			: undefined
		const picked = confirming?.kind === 'passkey.pick' ? confirming : held
		const subject = view.locked ? undefined : view.rows.find((row) => row.itemId === picked?.itemId && row.credentialId === picked.credentialId)
		body = account
			? <SecretForm key={view.locked ? 'unlock' : 'verify'} account={account} intro={intro} subject={subject} submitLabel={view.locked ? i18n.t('unlock.submit') : i18n.t('passkey.confirm')} onSubmit={verify} />
			: <Loading />
	} else if (view.alreadySaved) {
		title = i18n.t('passkey.alreadySavedTitle')
		ways = false
		body = (
			<div className="stack">
				<p className="passkey__text">{i18n.t('passkey.alreadySaved')}</p>
				<Button autoFocus busy={busy === 'cancel'} onClick={() => void cancel()}>{i18n.t('passkey.close')}</Button>
			</div>
		)
	} else if (view.kind === 'get') {
		title = i18n.t('passkey.signInTitle')
		body = <SignIn view={view} busy={busy} focus={!showFailure} onPick={pick} />
	} else {
		title = i18n.t('passkey.saveTitle')
		body = (
			<SaveTo
				view={view} busy={busy} focus={!showFailure} name={name ?? (view.rpName || view.rpId)} folderId={folderId} onName={setName} onFolder={setFolderId}
				onTarget={(target) => (target.replace ? setReplacing(target) : save({ itemId: target.itemId }, `save:${target.itemId}`))}
				onNew={() => save({ name: name ?? (view.rpName || view.rpId), folderId: folderId || null }, 'new')}
			/>
		)
	}

	useEffect(() => {
		if (title) document.title = title
	}, [title])

	return (
		<main className="popup popup--popout">
			<Header title={title} active={null} />
			<div className="popup__body stack">
				{showFailure && (
					<Banner tone="error" role="alert" action={<Button variant="link" autoFocus busy={busy === 'retry'} onClick={() => void run({ kind: 'passkey.retry' }, 'retry')}>{i18n.t('passkey.retry')}</Button>}>
						{writeErrorText(showFailure, 'passkey')}
					</Banner>
				)}
				{view && <ErrorBanner>{notice}</ErrorBanner>}
				{body}
			</div>
			{ways && (
				<div className="passkey__ways">
					<Button variant="secondary" busy={busy === 'fallback'} onClick={() => void fallback()}>{i18n.t('passkey.fallback')}</Button>
					<Button variant="secondary" busy={busy === 'cancel'} onClick={() => void cancel()}>{i18n.t('common.cancel')}</Button>
				</div>
			)}
			{replacing?.replace && (
				<ConfirmDialog
					title={i18n.t('passkey.replaceQuestion', { userName: replacing.replace.userName, name: replacing.name })}
					actions={[{
						label: i18n.t('passkey.replace'),
						onClick: () => {
							setReplacing(null)
							save({ itemId: replacing.itemId, replace: true }, `save:${replacing.itemId}`)
						},
					}]}
					onCancel={() => setReplacing(null)}
				/>
			)}
		</main>
	)
}

const rowLabel = (name: string, userName: string) => (userName ? i18n.t('passkey.row', { name, userName }) : name)

/** `focus`: the first row takes focus, unless Retry above it does. */
function SignIn({ view, busy, focus, onPick }: { view: PasskeyView; busy: string | null; focus: boolean; onPick: (row: PasskeyRow) => void }) {
	const id = useId()
	if (view.rows.length === 0) return <p className="section__empty">{i18n.t('passkey.noPasskey', { rpId: view.rpId })}</p>
	return (
		<section className="section">
			<p className="passkey__text" id={id}>{i18n.t('passkey.signInText', { rpId: view.rpId })}</p>
			<ul className="card" aria-labelledby={id}>
				{view.rows.map((row, index) => {
					const control = `pick:${row.itemId}:${row.credentialId}`
					return (
						<li key={`${row.itemId}:${row.credentialId}`}>
							<RowButton name={row.name} sub={row.userName} label={rowLabel(row.name, row.userName)} busy={busy === control} disabled={busy !== null} autoFocus={focus && index === 0} onClick={() => onPick(row)} />
						</li>
					)
				})}
			</ul>
		</section>
	)
}

interface SaveProps {
	view: PasskeyView
	busy: string | null
	focus: boolean
	name: string
	folderId: string
	onName: (name: string) => void
	onFolder: (folderId: string) => void
	onTarget: (target: PasskeySaveTarget) => void
	onNew: () => void
}

function SaveTo({ view, busy, focus, name, folderId, onName, onFolder, onTarget, onNew }: SaveProps) {
	const id = useId()
	function submit(event: FormEvent) {
		event.preventDefault()
		if (name.trim()) onNew()
	}
	return (
		<>
			<div className="passkey__site">
				<p className="passkey__rp">{view.rpId}</p>
				{view.userName && <p className="passkey__user">{view.userName}</p>}
			</div>
			{view.targets.length > 0 && (
				<section className="section" aria-labelledby={`${id}-to`}>
					<h2 className="section__title" id={`${id}-to`}>{i18n.t('passkey.saveTo')}</h2>
					<ul className="card" aria-labelledby={`${id}-to`}>
						{view.targets.map((target, index) => (
							<li key={target.itemId}>
								<RowButton
									name={target.name} sub={target.username} label={rowLabel(target.name, target.username)}
									note={target.replace ? i18n.t('passkey.replaces', { userName: target.replace.userName }) : undefined}
									busy={busy === `save:${target.itemId}`} disabled={busy !== null} autoFocus={focus && index === 0} onClick={() => onTarget(target)}
								/>
							</li>
						))}
					</ul>
				</section>
			)}
			<form className="section" aria-labelledby={`${id}-new`} onSubmit={submit}>
				<h2 className="section__title" id={`${id}-new`}>{i18n.t('passkey.newLogin')}</h2>
				<TextField label={i18n.t('passkey.name')} value={name} onChange={onName} autoFocus={focus && view.targets.length === 0} autoComplete="off" />
				<Select
					label={i18n.t('passkey.folder')} value={folderId} onChange={onFolder}
					options={[{ value: '', label: i18n.t('passkey.noFolder') }, ...view.folders.map((folder) => ({ value: folder.id, label: folder.name }))]}
				/>
				<Button type="submit" busy={busy === 'new'} disabled={busy !== null || !name.trim()}>{i18n.t('passkey.saveNew')}</Button>
			</form>
		</>
	)
}

interface RowProps {
	name: string
	sub: string
	label: string
	note?: string
	busy: boolean
	disabled: boolean
	autoFocus: boolean
	onClick: () => void
}

function RowButton({ name, sub, label, note, busy, disabled, autoFocus, onClick }: RowProps) {
	const noteId = useId()
	return (
		<button type="button" className="passkey__row" aria-label={label} aria-describedby={note ? noteId : undefined} aria-busy={busy} disabled={disabled} autoFocus={autoFocus} onClick={onClick}>
			<span className="passkey__name">{busy && <span className="spinner" aria-hidden="true" />}{name}</span>
			{sub && <span className="passkey__sub">{sub}</span>}
			{note && <span className="passkey__sub" id={noteId}>{note}</span>}
		</button>
	)
}

interface SecretProps {
	account: AccountSummary
	intro?: string
	/** The passkey "Confirm it's you" signs with. */
	subject?: PasskeyRow
	submitLabel: string
	onSubmit: (method: UnlockMethod) => Promise<PasskeyWindowReply | undefined>
}

/** Unlocking and "Confirm it's you" alike, as the Unlock view asks; the background keeps the PIN's five tries. */
function SecretForm({ account, intro, subject, submitLabel, onSubmit }: SecretProps) {
	const [secret, setSecret] = useState('')
	const [switched, setSwitched] = useState(false)
	const withPin = account.pin && !switched
	const afterRestart = account.pinAfterRestart && !account.pin
	const [error, setError] = useState<string | null>(null)
	const [busy, setBusy] = useState(false)
	const field = useRef<HTMLInputElement>(null)

	async function submit(event: FormEvent) {
		event.preventDefault()
		setBusy(true)
		setError(null)
		const method: UnlockMethod = withPin ? { type: 'pin', pin: secret } : { type: 'masterPassword', masterPassword: secret }
		const reply = await onSubmit(method)
		setBusy(false)
		setSecret('')
		if (reply?.ok) return
		// A code that is no `ErrorCode` reads as "Something went wrong".
		setError(reply ? errorText(reply.code as ErrorCode, account.host, reply.attemptsLeft) : i18n.t('errors.notResponding'))
		if (reply?.code === 'pin_forgotten' || reply?.code === 'pin_key_changed') setSwitched(true)
		else field.current?.focus()
	}

	return (
		<form className="stack" onSubmit={submit}>
			<Identity account={account} />
			{subject && (
				<div className="passkey__site">
					<p className="passkey__rp">{subject.name}</p>
					{subject.userName && <p className="passkey__user">{subject.userName}</p>}
				</div>
			)}
			<p className="passkey__text">{intro ?? i18n.t(withPin ? 'passkey.verifyPin' : 'passkey.verifyPassword')}</p>
			{withPin
				? <TextField key="pin" ref={field} label={i18n.t('unlock.pin')} type="password" value={secret} onChange={setSecret} autoFocus autoComplete="off" />
				: <TextField key="password" ref={field} label={i18n.t('unlock.masterPassword')} type="password" value={secret} onChange={setSecret} autoFocus autoComplete="current-password" hint={afterRestart ? i18n.t('settings.pinAfterRestart') : undefined} />}
			<ErrorBanner>{error}</ErrorBanner>
			{withPin && <Button variant="link" onClick={() => {
				setError(null)
				setSecret('')
				setSwitched(true)
			}}>{i18n.t('unlock.useMasterPassword')}</Button>}
			<Button type="submit" busy={busy} disabled={!secret}>{submitLabel}</Button>
		</form>
	)
}
