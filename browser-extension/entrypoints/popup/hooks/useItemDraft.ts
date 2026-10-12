import { useMemo, useState } from 'react'
import type { ItemDraft, ItemPatch, TypeMeta } from '@/src/messages'
import { diffDraft, isLiveError, toDraft, validate, type FormErrors, type FormValues } from '@/src/vault/fields'

/** The form's values in popup memory only, compared with what it opened with. */
export function useItemDraft(initial: FormValues, types: TypeMeta[]) {
	const [values, setValues] = useState(initial)
	const [attempted, setAttempted] = useState(false)
	const typeName = (typeId: string) => types.find((type) => type.id === typeId)?.name
	const currentType = typeName(values.typeId)

	const errors = useMemo(() => validate(values, currentType), [values, currentType])
	const draft = useMemo(() => toDraft(values, currentType), [values, currentType])
	const [before] = useState(() => toDraft(initial, typeName(initial.typeId)))
	const patch = useMemo(() => diffDraft(before, draft), [before, draft])

	const shown: FormErrors = attempted ? errors : Object.fromEntries(Object.entries(errors).filter(([, error]) => isLiveError(error)))

	return {
		values,
		typeName: currentType,
		update: (change: Partial<FormValues>) => setValues((current) => ({ ...current, ...change })),
		errors: shown,
		/** A refusal that shows while typing keeps Save disabled. */
		blocked: Object.values(errors).some(isLiveError),
		dirty: Object.keys(patch).length > 0,
		patch: patch as ItemPatch,
		/** The draft when it may be saved; otherwise every error starts to show. */
		submit: (): ItemDraft | null => {
			setAttempted(true)
			return Object.keys(errors).length === 0 ? draft : null
		},
	}
}
