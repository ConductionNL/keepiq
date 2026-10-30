export type Validators<T> = { [K in keyof T]: (value: unknown) => value is T[K] }

function copyValid<T, K extends keyof T>(target: Partial<T>, validators: Validators<T>, source: Record<PropertyKey, unknown>, key: K): void {
	const value = source[key]
	if (validators[key](value)) target[key] = structuredClone(value)
}

/** A copy of each key of `source` that passes its validator; everything else is dropped. */
export function pickValid<T extends object>(validators: Validators<T>, source: unknown): Partial<T> {
	const valid: Partial<T> = {}
	if (typeof source !== 'object' || source === null) return valid
	for (const key of Object.keys(validators) as Array<keyof T>) copyValid(valid, validators, source as Record<PropertyKey, unknown>, key)
	return valid
}
