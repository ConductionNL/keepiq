import { describe, expect, it } from 'vitest'
import { pickValid, type Validators } from './pick-valid'

interface Sample {
	name: string
	tags: string[]
}

const validators: Validators<Sample> = {
	name: (value): value is string => typeof value === 'string',
	tags: (value): value is string[] => Array.isArray(value) && value.every((tag) => typeof tag === 'string'),
}

describe('pickValid', () => {
	it('keeps the keys that pass and drops the rest and unknown keys', () => {
		expect(pickValid(validators, { name: 5, tags: ['a'], other: true })).toEqual({ tags: ['a'] })
	})

	it.each([null, undefined, 'text', 3])('gives nothing for %j', (source) => {
		expect(pickValid(validators, source)).toEqual({})
	})

	it('copies values, so the source can change without changing the result', () => {
		const tags = ['a']
		const picked = pickValid(validators, { tags })
		tags.push('b')
		expect(picked.tags).toEqual(['a'])
	})
})
