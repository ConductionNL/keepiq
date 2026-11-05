import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState, type FormEvent, type KeyboardEvent as ReactKeyboardEvent, type ReactNode } from 'react'
import { i18n } from '#i18n'
import { GUARD_MS } from '@/src/autofill/visibility'
import type {
	AccountSummary, PasskeyFailure, PasskeyPopupRequest, PasskeyRow, PasskeySaveTarget, PasskeyView, PasskeyWindowReply, UnlockMethod, VaultWriteError,
} from '@/src/messages'
import { Button, IconButton } from '../components/Button'
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
import { useSecretEntry } from '../hooks/useSecretEntry'

type Command = PasskeyPopupRequest extends infer R ? (R extends unknown ? Omit<R, 'ceremonyId'> : never) : never
type Act = Extract<Command, { kind: 'passkey.pick' | 'passkey.save' }>
type Save = Extract<Command, { kind: 'passkey.save' }>['target']
type Refusal = Extract<PasskeyWindowReply, { ok: false }>

const PICK_CODES: readonly PasskeyFailure[] = ['ended', 'locked', 'stale', 'write_failed']

/** Whether `passkey.verify` signed the held pick, or answers its signing as it answers a pick. */
const answersPick = (reply: PasskeyWindowReply | undefined) => reply !== undefined && (reply.ok ? !reply.view : PICK_CODES.includes(reply.code))

/** The inline list's pick the background holds for "Confirm it's you", and signs on `passkey.verify`, unless the user left it. */
function heldPick(view: PasskeyView | null, dismissed: string | null): PasskeyRow | null {
	return view && !view.locked && view.pending && rowKey(view.pending) !== dismissed ? view.pending : null
}

/**
 * The New login step, given the save step's targets or null outside it: `opened` from its button, which focuses Name; `only`
 * as shown for want of a matching login, which a login arriving later must not take away, or Enter meant for Name would save
 * to it; `left` returns focus to its button.
 */
function useNewLogin(targets: PasskeySaveTarget[] | null) {
	const [newLogin, setNewLogin] = useState<'opened' | 'only' | 'left' | null>(null)
	const onlyNew = targets !== null && targets.length === 0
	if (onlyNew && newLogin !== 'opened' && newLogin !== 'only') setNewLogin('only')
	const adding = onlyNew || (targets !== null && (newLogin === 'opened' || newLogin === 'only'))
	return { newLogin, setNewLogin, adding, canLeaveNew: adding && !onlyNew }
}

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
	/** The held pick the user left with Back or Escape, or that `passkey.verify` answered. */
	const [dismissed, setDismissed] = useState<string | null>(null)
	const [replacing, setReplacing] = useState<PasskeySaveTarget | null>(null)
	const [name, setName] = useState<string | null>(null)
	const [folderId, setFolderId] = useState('')
	/**
	 * The step of the last failed save, so its Retry, which repeats that save, shows only there. The view does not say which
	 * step a failure it opens with came from, so that one shows on both.
	 */
	const [savedFrom, setSavedFrom] = useState<'list' | 'new' | null>(null)
	const held = heldPick(view, dismissed)
	const saving = view !== null && !view.locked && view.kind === 'create' && !view.alreadySaved && !confirming && !held
	const { newLogin, setNewLogin, adding, canLeaveNew } = useNewLogin(saving ? view.targets : null)

	const send = useCallback((command: Command) => passkeyRequest({ ...command, ceremonyId } as PasskeyPopupRequest), [ceremonyId])

	const show = useCallback((next: PasskeyView) => {
		setView(next)
		setFailure(next.failure)
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
		await refused(reply, command)
	}

	async function refused(reply: Refusal, command: Command): Promise<void> {
		switch (reply.code) {
			case 'ended': return window.close()
			case 'write_failed': return writeFailed(reply.error, command)
			case 'verify_required': return confirmFirst(command)
			case 'stale':
				setNotice(view?.kind === 'create' ? i18n.t('passkey.staleSave') : i18n.t('passkey.stalePick'))
				await refresh()
				return
			case 'replace_required':
				await askReplace(command)
				return
			case 'locked':
			case 'already_saved':
				await refresh()
				return
			default: setNotice(errorText(reply.code, account?.host ?? '', reply.attemptsLeft))
		}
	}

	// Only a write that failed replaces the background's failed step; a save refused before it keeps the earlier one.
	function writeFailed(error: VaultWriteError | undefined, command: Command) {
		if (command.kind === 'passkey.save') setSavedFrom('name' in command.target ? 'new' : 'list')
		setFailure(error ?? { code: 'unknown' })
	}

	function confirmFirst(command: Command) {
		if (command.kind === 'passkey.pick' || command.kind === 'passkey.save') setConfirming(command)
	}

	/** Asks Replace for the save's login while the fresh view still offers it, or says the save went stale. */
	async function askReplace(command: Command) {
		const next = await refresh()
		const itemId = command.kind === 'passkey.save' && 'itemId' in command.target ? command.target.itemId : null
		const target = next?.targets.find((candidate) => candidate.itemId === itemId)
		if (target?.replace) setReplacing(target)
		else setNotice(i18n.t('passkey.staleSave'))
	}

	async function run(command: Command, control: string): Promise<void> {
		setBusy(control)
		setNotice(null)
		const reply = await send(command)
		setBusy(null)
		await settle(reply, command)
	}

	// While the background still holds a pick the user went Back from, a pick goes there first to replace it.
	function act(command: Act, control: string) {
		if (view?.verify && !(command.kind === 'passkey.pick' && view.pending)) setConfirming(command)
		else void run(command, control)
	}

	// Leaving mid-save would show that save's failure, and the Retry that repeats it, on the list.
	function leaveNew() {
		if (busy === null) setNewLogin('left')
	}

	function back() {
		setConfirming(null)
		if (held) setDismissed(rowKey(held))
	}

	const pick = (row: PasskeyRow) => act({ kind: 'passkey.pick', itemId: row.itemId, credentialId: row.credentialId }, `pick:${row.itemId}:${row.credentialId}`)
	const save = (target: Save, control: string) => act({ kind: 'passkey.save', target }, control)

	async function verify(method: UnlockMethod): Promise<PasskeyWindowReply | undefined> {
		const reply = await send({ kind: 'passkey.verify', method })
		if (held && answersPick(reply)) {
			setDismissed(rowKey(held))
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

	const elsewhere = saving && savedFrom !== null && savedFrom !== (adding ? 'new' : 'list')
	const showFailure = view && !view.locked && !confirming && !held && !view.alreadySaved && !elsewhere ? failure : null
	// A failure banner from a reply moves focus to Retry, so it counts as a step too.
	const ready = useSettled(JSON.stringify([view, confirming, held, replacing?.itemId, showFailure, adding]))
	const guarded = <A extends unknown[]>(action: (...args: A) => void) => (...args: A) => {
		if (ready()) action(...args)
	}

	const confirmStep = view !== null && !view.locked && (confirming !== null || held !== null)
	const escapeRef = useRef(() => {})
	useEffect(() => {
		escapeRef.current = confirmStep ? back : canLeaveNew ? leaveNew : () => void cancel()
	})
	useEffect(() => {
		// The Replace question's Escape stops propagating, so it never reaches this listener.
		const onKey = (event: KeyboardEvent) => {
			if (event.key === 'Escape' && !event.defaultPrevented) escapeRef.current()
		}
		document.addEventListener('keydown', onKey)
		return () => document.removeEventListener('keydown', onKey)
	}, [])

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
			? <SecretForm key={view.locked ? 'unlock' : 'verify'} account={account} intro={intro} subject={subject} submitLabel={view.locked ? i18n.t('unlock.submit') : i18n.t('passkey.confirm')} ready={ready} onSubmit={verify} />
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
		body = <SignIn view={view} busy={busy} focus={!showFailure} onPick={guarded(pick)} />
	} else {
		title = i18n.t('passkey.saveTitle')
		body = (
			<SaveTo
				view={view} busy={busy} adding={adding} name={name ?? (view.rpName || view.rpId)} folderId={folderId} onName={setName} onFolder={setFolderId}
				// Opening New login focuses its Name even under a failure; coming back from it focuses its button instead.
				focus={adding ? newLogin === 'opened' || !showFailure : !showFailure && newLogin !== 'left'}
				onTarget={guarded((target: PasskeySaveTarget) => {
					// A later return to the list, as from "Confirm it's you", focuses its first row again.
					setNewLogin(null)
					if (target.replace) setReplacing(target)
					else save({ itemId: target.itemId }, `save:${target.itemId}`)
				})}
				onNew={guarded(() => save({ name: name ?? (view.rpName || view.rpId), folderId: folderId || null }, 'new'))}
			/>
		)
	}

	useEffect(() => {
		if (title) document.title = title
	}, [title])

	return (
		<main className="popup popup--popout" onKeyDownCapture={ignoreHeld}>
			<Header title={title} active={null} busy={busy !== null} onBack={confirmStep ? back : canLeaveNew ? leaveNew : undefined}>
				{saving && !adding && (
					<IconButton
						icon="plus" label={i18n.t('passkey.newLogin')} aria-disabled={busy !== null} autoFocus={newLogin === 'left'}
						onClick={guarded(() => {
							if (busy === null) setNewLogin('opened')
						})}
					/>
				)}
			</Header>
			<div className="popup__body stack">
				{showFailure && (
					<Banner tone="error" role="alert" action={<Button variant="link" autoFocus={newLogin !== 'left'} focusable busy={busy === 'retry'} onClick={guarded(() => void run({ kind: 'passkey.retry' }, 'retry'))}>{i18n.t('passkey.retry')}</Button>}>
						{writeErrorText(showFailure, 'passkey')}
					</Banner>
				)}
				{view && <ErrorBanner>{notice}</ErrorBanner>}
				{body}
			</div>
			{ways && (
				<div className="passkey__ways">
					{/* No fallback for a conditional request: spec § Sign in with a passkey. */}
					{!view?.conditional && <Button variant="secondary" busy={busy === 'fallback'} onClick={() => void fallback()}>{i18n.t('passkey.fallback')}</Button>}
					<Button variant="secondary" busy={busy === 'cancel'} onClick={() => void cancel()}>{i18n.t('common.cancel')}</Button>
				</div>
			)}
			{replacing?.replace && (
				<ConfirmDialog
					title={i18n.t('passkey.replaceQuestion', { userName: replacing.replace.userName, name: replacing.name })}
					actions={[{
						label: i18n.t('passkey.replace'),
						onClick: guarded(() => {
							setReplacing(null)
							save({ itemId: replacing.itemId, replace: true }, `save:${replacing.itemId}`)
						}),
					}]}
					onCancel={() => setReplacing(null)}
					focusCancel
				/>
			)}
		</main>
	)
}

/**
 * The page opens the window and changes its step without the user, so a click, Enter or Space meant for the page must
 * not sign, save or replace: those count only `GUARD_MS` after `step` last changed or the window last got focus.
 */
function useSettled(step: string): () => boolean {
	const shownAt = useRef<number | null>(null)
	useLayoutEffect(() => {
		shownAt.current = Date.now()
	}, [step])
	useEffect(() => {
		const reshow = () => {
			if (document.visibilityState === 'visible') shownAt.current = Date.now()
		}
		window.addEventListener('focus', reshow)
		document.addEventListener('visibilitychange', reshow)
		return () => {
			window.removeEventListener('focus', reshow)
			document.removeEventListener('visibilitychange', reshow)
		}
	}, [])
	return useCallback(() => shownAt.current !== null && Date.now() - shownAt.current >= GUARD_MS, [])
}

/** A held Enter or Space repeats past that guard, so only its first press counts. */
function ignoreHeld(event: ReactKeyboardEvent) {
	if (event.repeat && (event.key === 'Enter' || event.key === ' ')) event.preventDefault()
}

const rowKey = (row: Pick<PasskeyRow, 'itemId' | 'credentialId'>) => `${row.itemId}:${row.credentialId}`

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
	adding: boolean
	focus: boolean
	name: string
	folderId: string
	onName: (name: string) => void
	onFolder: (folderId: string) => void
	onTarget: (target: PasskeySaveTarget) => void
	onNew: () => void
}

function SaveTo({ view, busy, adding, focus, name, folderId, onName, onFolder, onTarget, onNew }: SaveProps) {
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
			{!adding && (
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
			{adding && (
				<form className="section" aria-labelledby={`${id}-new`} onSubmit={submit}>
					<h2 className="section__title" id={`${id}-new`}>{i18n.t('passkey.newLogin')}</h2>
					<TextField label={i18n.t('passkey.name')} value={name} onChange={onName} autoFocus={focus} autoComplete="off" />
					<Select
						label={i18n.t('passkey.folder')} value={folderId} onChange={onFolder}
						options={[{ value: '', label: i18n.t('passkey.noFolder') }, ...view.folders.map((folder) => ({ value: folder.id, label: folder.name }))]}
					/>
					<Button type="submit" focusable busy={busy === 'new'} disabled={busy !== null || !name.trim()}>{i18n.t('passkey.saveNew')}</Button>
				</form>
			)}
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

/** `disabled` stays focusable, so focus is still on the row when a busy pick or save comes back. */
function RowButton({ name, sub, label, note, busy, disabled, autoFocus, onClick }: RowProps) {
	const noteId = useId()
	return (
		<button
			type="button" className="passkey__row" aria-label={label} aria-describedby={note ? noteId : undefined} aria-busy={busy} aria-disabled={disabled} autoFocus={autoFocus}
			onClick={() => !disabled && onClick()}
		>
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
	/** False while a submit would come from typing meant for the page, which could spend a PIN try. */
	ready: () => boolean
	onSubmit: (method: UnlockMethod) => Promise<PasskeyWindowReply | undefined>
}

/** Unlocking and "Confirm it's you" alike, as the Unlock view asks; the background keeps the PIN's five tries. */
function SecretForm({ account, intro, subject, submitLabel, ready, onSubmit }: SecretProps) {
	const { secret, setSecret, withPin, afterRestart, error, busy, field, switchToMasterPassword, submit: unlock } = useSecretEntry(account)

	function submit(event: FormEvent) {
		event.preventDefault()
		if (ready()) void unlock(onSubmit)
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
			{withPin && <Button variant="link" onClick={switchToMasterPassword}>{i18n.t('unlock.useMasterPassword')}</Button>}
			<Button type="submit" busy={busy} disabled={!secret}>{submitLabel}</Button>
		</form>
	)
}
