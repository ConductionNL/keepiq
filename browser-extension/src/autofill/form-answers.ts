// The popup's and the inline list's answers to a card's or identity's questions; apart from `wallet.ts`, so the list page does not load the public suffix list.
import type { FormConfirmation, FormQuestion } from '@/src/messages'

/**
 * `previous` with Fill to every question in `question`, as the context menu's `confirm()` answers. Answers for another origin are dropped.
 * An earlier "Only {host}" stays, so a later insecure question cannot widen it.
 */
export function answered(previous: FormConfirmation | undefined, question: FormQuestion): FormConfirmation {
	const prior = previous?.origin === question.origin ? previous : undefined
	const insecure = prior?.insecure === true || question.insecure
	const frameHosts = [...new Set([...(prior?.frameHosts ?? []), ...question.frameHosts])]
	return { ...(insecure && { insecure: true }), frames: prior?.frames ?? 'all', frameHosts, origin: question.origin }
}

/** As `answered`, with "Only {host}" to the cross-site question: no frame on another site takes part. */
export function answeredOnlyHost(previous: FormConfirmation | undefined, question: FormQuestion): FormConfirmation {
	return { ...answered(previous, question), frames: 'sameSite' }
}
