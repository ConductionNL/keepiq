import { useEffect, useRef, useState, type FormEvent } from 'react'
import { flushSync } from 'react-dom'
import { i18n } from '#i18n'
import { Button, IconButton } from '../../components/Button'
import { ErrorBanner } from '../../components/ErrorBanner'
import { Icon } from '../../components/Icon'
import { Loading } from '../../components/Loading'
import { TextField } from '../../components/TextField'
import { errorText } from '../../errors'
import { useAccountSettings } from '../../hooks/useAccountSettings'
import { useCurrentTab } from '../../hooks/useCurrentTab'
import type { DomainResult } from './domains'

const REFUSALS: Record<Extract<DomainResult, { ok: false }>['error'], () => string> = {
	empty: () => i18n.t('settings.domainEmpty'),
	unreadable: () => i18n.t('settings.domainUnreadable'),
	duplicate: () => i18n.t('settings.domainDuplicate'),
}

/** Sites the save prompts stay away from, as base domains (ext-autofill matches the same way). */
export function ExcludedDomains() {
	const { snapshot, failed, update } = useAccountSettings()
	const tab = useCurrentTab()
	const field = useRef<HTMLInputElement>(null)
	const [text, setText] = useState('')
	const [fieldError, setFieldError] = useState<string | null>(null)
	const [siteError, setSiteError] = useState<string | null>(null)
	// A ref, not state: a second press in the same tick must see the first.
	const busy = useRef(false)
	const rows = useRef<HTMLUListElement>(null)
	/** The removed row's index, whose successor takes focus once the shorter list renders. */
	const removedAt = useRef<number | null>(null)
	const list = snapshot?.values.excludedDomains

	useEffect(() => {
		// A stored change frees the page only once its list renders, so the next press never reads the old one.
		busy.current = false
		const at = removedAt.current
		if (at === null) return
		removedAt.current = null
		const buttons = rows.current?.querySelectorAll('button') ?? []
		if (buttons.length === 0) field.current?.focus()
		else buttons[Math.min(at, buttons.length - 1)]?.focus()
	}, [list])

	if (!snapshot || !list) return failed ? <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner> : <Loading />

	/**
	 * One change at a time, so a second never drops what the first added; refused rather than disabled, so focus stays.
	 * `change` resolves whether it stored a new list, whose render frees the page.
	 */
	async function exclusive(change: () => Promise<boolean>) {
		if (busy.current) return
		busy.current = true
		let stored = false
		try {
			stored = await change()
		} finally {
			if (!stored) busy.current = false
		}
	}

	const add = (input: string, from: 'site' | 'field') => exclusive(async () => {
		setFieldError(null)
		setSiteError(null)
		// The public suffix list is large, so only this page loads it.
		const { excludedDomain } = await import('./domains')
		const result = excludedDomain(list, input)
		if (!result.ok) {
			if (from === 'site') setSiteError(REFUSALS[result.error]())
			else {
				// Rendered before focus lands, so the field names it.
				flushSync(() => setFieldError(REFUSALS[result.error]()))
				field.current?.focus()
			}
			return false
		}
		const stored = await update({ excludedDomains: [...list, result.domain] })
		if (stored && from === 'field') setText('')
		return stored
	})

	const remove = (index: number) => exclusive(async () => {
		removedAt.current = index
		const stored = await update({ excludedDomains: list.filter((_, i) => i !== index) })
		if (!stored) removedAt.current = null
		return stored
	})

	function submit(event: FormEvent) {
		event.preventDefault()
		void add(text, 'field')
	}

	return (
		<div className="stack">
			{failed && <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner>}
			<div className="stack stack--tight">
				<div>
					<Button variant="secondary" disabled={!tab?.host} onClick={() => void add(tab?.url ?? '', 'site')}><Icon name="plus" />{i18n.t('settings.addCurrentSite')}</Button>
				</div>
				{siteError && <p className="field__error" role="alert">{siteError}</p>}
			</div>
			<form className="domain-form" onSubmit={submit}>
				<TextField ref={field} label={i18n.t('settings.domain')} value={text} onChange={setText} error={fieldError} announce verbatim autoComplete="off" />
				<Button type="submit" variant="secondary">{i18n.t('settings.addDomain')}</Button>
			</form>
			{list.length > 0 && (
				<ul className="card" ref={rows}>
					{list.map((domain, index) => (
						<li key={domain} className="domain-row">
							<span className="domain-row__name">{domain}</span>
							<IconButton icon="trash" label={i18n.t('settings.removeDomain', { domain })} onClick={() => void remove(index)} />
						</li>
					))}
				</ul>
			)}
		</div>
	)
}
