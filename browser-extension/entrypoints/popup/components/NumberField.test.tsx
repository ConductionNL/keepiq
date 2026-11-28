// @vitest-environment happy-dom
import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { NumberField } from './NumberField'

afterEach(cleanup)

const input = (name = 'Minimum numbers') => screen.getByRole<HTMLInputElement>('spinbutton', { name })

describe('NumberField', () => {
	it('reports a whole number in range as it is typed', async () => {
		const user = userEvent.setup()
		const onChange = vi.fn()
		render(<NumberField label="Minimum numbers" value={1} min={0} max={9} onChange={onChange} />)
		await user.clear(input())
		await user.type(input(), '7')
		expect(onChange).toHaveBeenLastCalledWith(7)
		expect([input().min, input().max]).toEqual(['0', '9'])
	})

	it('waits on a value out of range and shows the last valid one on blur', async () => {
		const user = userEvent.setup()
		const onChange = vi.fn()
		render(<NumberField label="Minimum numbers" value={3} min={0} max={9} onChange={onChange} />)
		await user.clear(input())
		await user.type(input(), '12')
		expect(onChange).not.toHaveBeenCalledWith(12)
		await user.tab()
		expect(input().value).toBe('3')
	})

	it('names the input without a visible label when asked, and shows a hint', () => {
		const { rerender } = render(<NumberField label="Length in characters" hideLabel value={14} min={8} max={128} onChange={vi.fn()} />)
		expect(input('Length in characters').value).toBe('14')
		expect(document.querySelector('label')).toBeNull()
		rerender(<NumberField label="Minimum special" value={1} min={1} max={9} hint="Set by your organisation" onChange={vi.fn()} />)
		expect(document.getElementById(input('Minimum special').getAttribute('aria-describedby')!)!.textContent).toBe('Set by your organisation')
	})
})
