import { useCallback, useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import { i18n } from '#i18n'
import { answered, answeredOnlyHost } from '@/src/autofill/form-answers'
import { GUARD_MS } from '@/src/autofill/visibility'
import type { FillResult, FormConfirmation, FormQuestion } from '@/src/messages'
import { ConfirmDialog } from '../components/ConfirmDialog'
import { RepromptDialog } from '../components/RepromptDialog'
import { useShell } from '../shell-context'
import { isPopout, type CurrentTab } from './useCurrentTab'
import { request } from './useMessage'

/**
 * The answers a `fill.request` carries: a login's insecure page, or every answer so far for a card or identity;
 * a flagged item's master password rides along to the end of this one fill, and is asked again for the next.
 */
type Answers = { confirmedInsecure?: true; confirmed?: FormConfirmation; masterPassword?: string }

/** A card's or identity's open question; `previous` the answers it builds on, since the background keeps none. */
interface FormAsk {
	itemId: string
	question: FormQuestion
	previous: FormConfirmation | undefined
	insecureAnswered: boolean
	masterPassword?: string
}

/** The master password as a spreadable field, so a fill without one sends none. */
const withPassword = (masterPassword: string | undefined): { masterPassword?: string } => masterPassword === undefined ? {} : { masterPassword }

// A second-step page fills only the code.
const filledSomething = (reply: FillResult | undefined) => reply != null && (reply.filled !== 'none' || reply.code)

/**
 * `fill(itemId)` fills the tab and closes the popup, but not a popout; `dialog` is the open question, for the caller to render.
 * The tab rides along because in a popout the active tab is the popout itself.
 */
export function useFill(tab: CurrentTab | undefined): { fill: (itemId: string) => void; dialog: ReactNode } {
	const { toast, refreshState } = useShell()
	// `savedSecure` false: the login was saved with http, so the warning does not claim an https origin.
	const [insecure, setInsecure] = useState<{ itemId: string; savedSecure: boolean; masterPassword?: string } | null>(null)
	const [form, setForm] = useState<FormAsk | null>(null)
	const [reprompt, setReprompt] = useState<{ itemId: string; answers: Answers; wrong: boolean } | null>(null)
	// A ref, not a disabled button: a focused button that becomes disabled drops focus to `<body>`.
	const inFlight = useRef(false)
	const tabId = tab?.id

	const send = useCallback(async (itemId: string, answers: Answers) => {
		if (inFlight.current) return
		inFlight.current = true
		try {
			const reply = await request({ kind: 'fill.request', itemId, tabId, ...answers })
			if (reply?.needsReprompt) return setReprompt({ itemId, answers, wrong: reply.wrongMasterPassword === true })
			const { masterPassword } = answers
			if (reply?.needsInsecureConfirm) return setInsecure({ itemId, savedSecure: reply.savedSecure !== false, ...withPassword(masterPassword) })
			if (reply?.needsFormConfirm) return setForm({ itemId, question: reply.needsFormConfirm, previous: answers.confirmed, insecureAnswered: false, ...withPassword(masterPassword) })
			if (filledSomething(reply)) {
				if (!isPopout) window.close()
				return
			}
			toast(i18n.t('vault.fillFailed'))
			// The reply can't say "locked"; a re-read shows Unlock if that was why.
			refreshState()
		} finally {
			inFlight.current = false
		}
	}, [tabId, toast, refreshState])

	const fill = useCallback((itemId: string) => void send(itemId, {}), [send])

	let dialog: ReactNode = null
	if (reprompt !== null) {
		dialog = (
			<RepromptDialog
				wrong={reprompt.wrong}
				onSubmit={(masterPassword) => {
					setReprompt(null)
					void send(reprompt.itemId, { ...reprompt.answers, masterPassword })
				}}
				onCancel={() => setReprompt(null)}
			/>
		)
	} else if (insecure !== null) {
		dialog = (
			<ConfirmDialog
				title={i18n.t('item.insecureTitle')}
				actions={[{
					label: i18n.t('item.fill'),
					onClick: () => {
						setInsecure(null)
						void send(insecure.itemId, { confirmedInsecure: true, ...withPassword(insecure.masterPassword) })
					},
				}]}
				onCancel={() => setInsecure(null)}
				focusCancel
				describeBody
			>
				<p>{insecure.savedSecure ? i18n.t('item.insecureWarning') : i18n.t('inlineList.insecureDetail')}</p>
			</ConfirmDialog>
		)
	} else if (form !== null) {
		const answer = (confirmed: FormConfirmation) => {
			setForm(null)
			void send(form.itemId, { confirmed, ...withPassword(form.masterPassword) })
		}
		dialog = <FormDialog ask={form} onNext={() => setForm({ ...form, insecureAnswered: true })} onAnswer={answer} onCancel={() => setForm(null)} />
	}

	return { fill, dialog }
}

interface FormDialogProps {
	ask: FormAsk
	/** On to the cross-site question. */
	onNext: () => void
	onAnswer: (confirmed: FormConfirmation) => void
	onCancel: () => void
}

/** Ignores `act` until `GUARD_MS` after `step` last changed, so the second click of a double click does not answer the step it opened. */
function useSettled(step: string): (act: () => void) => () => void {
	const shownAt = useRef(0)
	useLayoutEffect(() => {
		shownAt.current = Date.now()
	}, [step])
	return (act) => () => {
		if (Date.now() - shownAt.current >= GUARD_MS) act()
	}
}

/** The insecure question first, for a card; then the cross-site one. Keyed apart, so each opens on Cancel. */
function FormDialog({ ask, onNext, onAnswer, onCancel }: FormDialogProps) {
	const { question, previous } = ask
	const asksInsecure = question.insecure && !ask.insecureAnswered
	const settled = useSettled(asksInsecure ? 'insecure' : 'hosts')
	const fillAll = () => onAnswer(answered(previous, question))
	if (asksInsecure) {
		return (
			<ConfirmDialog key="insecure" title={i18n.t('item.cardInsecureTitle')} actions={[{ label: i18n.t('item.fill'), onClick: question.frameHosts.length ? onNext : fillAll }]} onCancel={onCancel} focusCancel describeBody>
				<p>{i18n.t('item.cardInsecureWarning')}</p>
			</ConfirmDialog>
		)
	}
	const hosts = question.frameHosts.join(', ')
	const actions = [
		{ label: i18n.t('item.fill'), onClick: settled(fillAll) },
		{ label: i18n.t('item.onlyHost', { host: question.host }), variant: 'secondary' as const, onClick: settled(() => onAnswer(answeredOnlyHost(previous, question))) },
	]
	return (
		<ConfirmDialog key="hosts" title={i18n.t('item.crossSiteTitle', { hosts })} actions={actions} onCancel={onCancel} focusCancel describeBody>
			<p>{i18n.t('item.crossSiteWarning', { hosts, host: question.host })}</p>
		</ConfirmDialog>
	)
}
