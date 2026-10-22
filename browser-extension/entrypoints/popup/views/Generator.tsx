import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import { i18n } from '#i18n'
import { applyPolicy, generate, passphraseAllowed, type GenerateResult } from '@/src/generator/generate'
import {
	GENERATOR_KINDS, MIN_COUNT, PASSPHRASE_WORDS, PASSWORD_LENGTH,
	type EmailLabelMode, type GeneratorFailure, type GeneratorKind, type GeneratorOptions, type GeneratorPolicy,
	type PassphraseOptions, type PasswordOptions, type UsernameOptions, type UsernameType,
} from '@/src/generator/options'
import { webUrl } from '@/src/vault/url'
import { Button } from '../components/Button'
import { ErrorBanner } from '../components/ErrorBanner'
import { GeneratedValue } from '../components/GeneratedValue'
import { Icon } from '../components/Icon'
import { LengthSlider } from '../components/LengthSlider'
import { Loading } from '../components/Loading'
import { NumberField } from '../components/NumberField'
import { OptionCheckbox } from '../components/OptionCheckbox'
import { PageFooter } from '../components/PageFooter'
import { Select } from '../components/Select'
import { SubTabs, subTabId } from '../components/SubTabs'
import { TextField } from '../components/TextField'
import { errorText } from '../errors'
import { useCurrentTab, type CurrentTab } from '../hooks/useCurrentTab'
import { useGeneratorHistory } from '../hooks/useGeneratorHistory'
import { useGeneratorOptions } from '../hooks/useGeneratorOptions'

export interface GeneratorPick {
	target: 'password' | 'username'
	onUse: (value: string) => void
}

export function kindLabel(kind: GeneratorKind): string {
	switch (kind) {
		case 'password': return i18n.t('generator.password')
		case 'passphrase': return i18n.t('generator.passphrase')
		case 'username': return i18n.t('generator.username')
	}
}

function failureText(failure: GeneratorFailure): string {
	switch (failure) {
		case 'wordlist_unavailable': return i18n.t('generator.wordlistUnavailable')
		case 'needs_email': return i18n.t('generator.needsEmail')
		case 'needs_domain': return i18n.t('generator.needsDomain')
		case 'no_website': return i18n.t('generator.noWebsite')
		case 'passphrase_off': return i18n.t('generator.passphraseOff')
	}
}

/** The bare hostname of an http(s) tab, without port. */
const hostname = (tab: CurrentTab): string | null => (tab.host ? webUrl(tab.url)?.hostname ?? null : null)

/** `takeFocus` when the control that opened it is gone, as on the lock screen; pick mode always takes focus. */
export function Generator({ pick, onOpenHistory, takeFocus = false }: { pick?: GeneratorPick; onOpenHistory?: () => void; takeFocus?: boolean }) {
	const { options, policy, accountEmail, failed, update } = useGeneratorOptions()
	const { add } = useGeneratorHistory()
	const tab = useCurrentTab()
	const website = tab && hostname(tab)
	const panelId = useId()
	// Tagged with its kind, so Copy and Use never act on the previous sub-tab's value.
	const [shown, setShown] = useState<{ kind: GeneratorKind; result: GenerateResult } | null>(null)
	const [announcement, setAnnouncement] = useState('')
	const [nonce, setNonce] = useState(0)
	// Once typed in, an emptied email stays empty instead of falling back to the account's.
	const [emailEdited, setEmailEdited] = useState(false)
	const recordNext = useRef(false)
	const announceNext = useRef(false)
	const lastRecorded = useRef<string | null>(null)

	const allowed = passphraseAllowed(policy)
	const offered: GeneratorKind[] = pick ? (pick.target === 'username' ? ['username'] : ['password', 'passphrase']) : [...GENERATOR_KINDS]
	// Pick mode drops a turned-off Passphrase; the tab shows it disabled with the reason.
	const kinds = pick ? offered.filter((kind) => kind !== 'passphrase' || allowed) : offered
	const usable = kinds.filter((kind) => kind !== 'passphrase' || allowed)
	const kind = options && usable.includes(options.tab) ? options.tab : usable[0]!

	const email = options && (emailEdited || options.username.email !== '' ? options.username.email : accountEmail ?? '')
	const generated = useMemo((): GeneratorOptions | undefined => {
		if (!options || website === undefined || email === undefined) return undefined
		const mode = website === null ? 'random' : options.username.mode
		return { ...options, password: applyPolicy(options.password, policy).options, username: { ...options.username, email, mode } }
	}, [options, policy, website, email])

	const record = useCallback((value: string, of: GeneratorKind) => {
		if (value === lastRecorded.current) return
		lastRecorded.current = value
		void add({ value, kind: of })
	}, [add])

	useEffect(() => {
		if (!generated) return
		// Taken now, so a Regenerate overtaken by an option change records nothing.
		const recording = recordNext.current
		const announcing = announceNext.current
		recordNext.current = false
		announceNext.current = false
		let live = true
		void generate(kind, generated, policy, { website: website ?? null, accountEmail: emailEdited ? null : accountEmail }).then((result) => {
			if (!live) return
			setShown({ kind, result })
			if (recording && result.ok) record(result.value, kind)
			if (announcing) setAnnouncement(result.ok ? result.value : failureText(result.failure))
		})
		return () => {
			live = false
		}
	}, [kind, generated, policy, website, accountEmail, emailEdited, nonce, record])

	if (!options || !generated) return failed ? <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner> : <Loading />

	const result = shown?.kind === kind ? shown.result : null
	const value = result?.ok ? result.value : null
	const tabbed = kinds.length > 1

	function changeKind(next: GeneratorKind) {
		if (value) record(value, kind)
		announceNext.current = true
		update((current) => ({ ...current, tab: next }))
	}

	function use(used: string) {
		record(used, kind)
		pick!.onUse(used)
	}

	return (
		<div className="stack generator">
			{failed && <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner>}
			{/* Only Regenerate and a sub-tab change speak; an option step would read out every value. */}
			<p className="sr-only generator__announcer" aria-live="polite" aria-atomic="true">{announcement}</p>
			{tabbed && (
				<SubTabs
					label={i18n.t('generator.kinds')}
					panelId={panelId}
					value={kind}
					onChange={changeKind}
					tabs={kinds.map((id) => ({ id, label: kindLabel(id), disabled: !usable.includes(id) }))}
					hint={usable.length < kinds.length ? i18n.t('generator.passphraseOff') : undefined}
				/>
			)}
			<div className="stack" id={panelId} role={tabbed ? 'tabpanel' : undefined} aria-labelledby={tabbed ? subTabId(panelId, kind) : undefined}>
				<GeneratedValue
					value={value}
					failure={result && !result.ok ? failureText(result.failure) : null}
					label={kindLabel(kind)}
					// Pick mode hid the button that opened it, so focus would fall to the page.
					focusOnMount={pick !== undefined || takeFocus}
					onRegenerate={() => {
						recordNext.current = true
						announceNext.current = true
						setNonce((n) => n + 1)
					}}
					onCopied={(copied) => record(copied, kind)}
				/>
				<div className="card form-card">
					{kind === 'password' && (
						<PasswordPanel
							stored={options.password}
							policy={policy}
							onChange={(password, debounce) => update((current) => ({ ...current, password }), { debounce })}
						/>
					)}
					{kind === 'passphrase' && (
						<PassphrasePanel options={options.passphrase} onChange={(passphrase) => update((current) => ({ ...current, passphrase }))} />
					)}
					{kind === 'username' && (
						<UsernamePanel
							stored={options.username}
							shown={generated.username}
							website={website ?? null}
							onChange={(username) => update((current) => ({ ...current, username }))}
							onEmail={(typed) => {
								setEmailEdited(true)
								update((current) => ({ ...current, username: { ...current.username, email: typed } }))
							}}
						/>
					)}
				</div>
			</div>
			{onOpenHistory && (
				<ul className="card menu">
					<li>
						<button type="button" className="menu__item" onClick={onOpenHistory}>
							<Icon name="history" />
							<span className="menu__label">{i18n.t('generator.history')}</span>
							<Icon name="chevronRight" />
						</button>
					</li>
				</ul>
			)}
			{pick && (
				<PageFooter>
					<div className="footer-actions">
						<Button disabled={!value} onClick={() => value && use(value)}>
							{i18n.t(pick.target === 'username' ? 'generator.useUsername' : 'generator.usePassword')}
						</Button>
					</div>
				</PageFooter>
			)}
		</div>
	)
}

const CLASSES = [
	['includeUppercase', 'uppercase', 'generator.uppercase', 'generator.uppercaseShort'],
	['includeLowercase', 'lowercase', 'generator.lowercase', 'generator.lowercaseShort'],
	['includeDigits', 'digits', 'generator.digits', 'generator.digitsShort'],
	['includeSpecialCharacters', 'special', 'generator.special', 'generator.specialShort'],
] as const

interface PasswordPanelProps {
	stored: PasswordOptions
	policy: GeneratorPolicy | null
	/** `debounce` for the range input's steps. */
	onChange: (options: PasswordOptions, debounce: boolean) => void
}

function PasswordPanel({ stored, policy, onChange }: PasswordPanelProps) {
	const { options, minLength, locked } = applyPolicy(stored, policy)
	const lockedBy = i18n.t('generator.lockedByPolicy')
	const lockedHint = useId()
	const lastHint = useId()
	const set = (patch: Partial<PasswordOptions>, debounce = false) => onChange({ ...stored, ...patch }, debounce)
	const enabled = CLASSES.filter(([key]) => options[key]).length
	const classLocked = CLASSES.some(([, lock]) => locked[lock])
	// The last enabled class stays on; a locked one already says why.
	const lastOn = enabled === 1 ? CLASSES.find(([key, lock]) => options[key] && !locked[lock]) : undefined
	return (
		<>
			<LengthSlider
				label={i18n.t('generator.length')}
				value={options.length}
				min={minLength}
				max={PASSWORD_LENGTH.max}
				lockedBy={locked.length ? lockedBy : undefined}
				onChange={(length) => set({ length })}
				onSlide={(length) => set({ length }, true)}
			/>
			<div className="field">
				<div className="check-row" role="group" aria-label={i18n.t('generator.characterTypes')}>
					{CLASSES.map(([key, lock, label, short]) => (
						<OptionCheckbox
							key={key}
							label={i18n.t(label)}
							short={i18n.t(short)}
							checked={options[key]}
							reasonId={locked[lock] ? lockedHint : lastOn?.[0] === key ? lastHint : undefined}
							onChange={(checked) => set({ [key]: checked })}
						/>
					))}
				</div>
				{classLocked && <p className="field__hint" id={lockedHint}>{lockedBy}</p>}
				{lastOn && <p className="field__hint" id={lastHint}>{i18n.t('generator.lastClassOn')}</p>}
			</div>
			<div className="field-pair">
				<NumberField
					label={i18n.t('generator.minNumbers')}
					value={options.minDigits}
					min={locked.digits ? 1 : MIN_COUNT.min}
					max={MIN_COUNT.max}
					disabled={!options.includeDigits}
					hint={locked.digits ? lockedBy : undefined}
					onChange={(minDigits) => set({ minDigits })}
				/>
				<NumberField
					label={i18n.t('generator.minSpecial')}
					value={options.minSpecial}
					min={locked.special ? 1 : MIN_COUNT.min}
					max={MIN_COUNT.max}
					disabled={!options.includeSpecialCharacters}
					hint={locked.special ? lockedBy : undefined}
					onChange={(minSpecial) => set({ minSpecial })}
				/>
			</div>
			<OptionCheckbox label={i18n.t('generator.avoidAmbiguous')} checked={options.avoidAmbiguous} onChange={(avoidAmbiguous) => set({ avoidAmbiguous })} />
		</>
	)
}

function PassphrasePanel({ options, onChange }: { options: PassphraseOptions; onChange: (options: PassphraseOptions) => void }) {
	const set = (patch: Partial<PassphraseOptions>) => onChange({ ...options, ...patch })
	return (
		<>
			<div className="field-pair">
				<NumberField label={i18n.t('generator.words')} value={options.words} min={PASSPHRASE_WORDS.min} max={PASSPHRASE_WORDS.max} onChange={(words) => set({ words })} />
				<TextField
					label={i18n.t('generator.separator')}
					value={options.separator}
					verbatim
					// One character is stored; the last one typed replaces it.
					onChange={(typed) => set({ separator: [...typed].at(-1) ?? '' })}
				/>
			</div>
			<div className="check-row">
				<OptionCheckbox label={i18n.t('generator.capitalizeWords')} checked={options.capitalise} onChange={(capitalise) => set({ capitalise })} />
				<OptionCheckbox label={i18n.t('generator.includeNumber')} checked={options.includeNumber} onChange={(includeNumber) => set({ includeNumber })} />
			</div>
		</>
	)
}

interface UsernamePanelProps {
	stored: UsernameOptions
	/** As generated: the email seeded from the account, Website name off without a website. */
	shown: UsernameOptions
	website: string | null
	onChange: (options: UsernameOptions) => void
	onEmail: (email: string) => void
}

function UsernamePanel({ stored, shown: options, website, onChange, onEmail }: UsernamePanelProps) {
	const set = (patch: Partial<UsernameOptions>) => onChange({ ...stored, ...patch })
	return (
		<>
			<Select<UsernameType>
				label={i18n.t('generator.usernameType')}
				value={options.type}
				options={[
					{ value: 'word', label: i18n.t('generator.typeWord') },
					{ value: 'plus', label: i18n.t('generator.typePlus') },
					{ value: 'catchall', label: i18n.t('generator.typeCatchall') },
				]}
				onChange={(type) => set({ type })}
			/>
			{options.type === 'word' && (
				<div className="check-row">
					<OptionCheckbox label={i18n.t('generator.capitalize')} checked={options.capitalize} onChange={(capitalize) => set({ capitalize })} />
					<OptionCheckbox label={i18n.t('generator.includeNumber')} checked={options.includeNumber} onChange={(includeNumber) => set({ includeNumber })} />
				</div>
			)}
			{options.type === 'plus' && (
				<TextField label={i18n.t('generator.email')} type="email" autoComplete="off" placeholder={i18n.t('generator.emailPlaceholder')} verbatim value={options.email} onChange={onEmail} />
			)}
			{options.type === 'catchall' && (
				<TextField label={i18n.t('generator.domain')} autoComplete="off" placeholder={i18n.t('generator.domainPlaceholder')} verbatim value={options.domain} onChange={(domain) => set({ domain })} />
			)}
			{options.type !== 'word' && <ModeChoice value={options.mode} website={website} onChange={(mode) => set({ mode })} />}
		</>
	)
}

function ModeChoice({ value, website, onChange }: { value: EmailLabelMode; website: string | null; onChange: (mode: EmailLabelMode) => void }) {
	const name = useId()
	return (
		<fieldset className="field radio-group">
			<legend className="field__label">{i18n.t('generator.basedOn')}</legend>
			<div className="check-row">
				<label className="check">
					<input type="radio" name={name} className="check__input" checked={value === 'random'} onChange={() => onChange('random')} />
					{i18n.t('generator.random')}
				</label>
				<label className="check">
					<input
						type="radio"
						name={name}
						className="check__input"
						checked={value === 'website'}
						// Focusable while unavailable, so its reason is heard.
						aria-disabled={website === null || undefined}
						aria-describedby={website === null ? `${name}-none` : undefined}
						onChange={() => website !== null && onChange('website')}
					/>
					{i18n.t('generator.websiteName')}
				</label>
			</div>
			{website === null && <p className="field__hint" id={`${name}-none`}>{i18n.t('generator.noWebsite')}</p>}
		</fieldset>
	)
}
