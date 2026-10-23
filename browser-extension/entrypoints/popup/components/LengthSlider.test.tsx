// @vitest-environment happy-dom
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { LengthSlider } from './LengthSlider'

afterEach(cleanup)

const slider = () => screen.getByRole<HTMLInputElement>('slider', { name: 'Length' })
const number = () => screen.getByRole<HTMLInputElement>('spinbutton', { name: 'Length in characters' })

describe('LengthSlider', () => {
	it('pairs a range with a number input over the same bounds', async () => {
		const user = userEvent.setup()
		const onChange = vi.fn()
		render(<LengthSlider label="Length" value={14} min={8} max={128} onChange={onChange} />)
		expect([slider().value, slider().min, slider().max]).toEqual(['14', '8', '128'])
		expect([number().value, number().min, number().max]).toEqual(['14', '8', '128'])
		fireEvent.change(slider(), { target: { value: '20' } })
		expect(onChange).toHaveBeenLastCalledWith(20)
		await user.clear(number())
		await user.type(number(), '32')
		expect(onChange).toHaveBeenLastCalledWith(32)
	})

	it('sends the range steps to onSlide and typed values to onChange', async () => {
		const user = userEvent.setup()
		const onChange = vi.fn()
		const onSlide = vi.fn()
		render(<LengthSlider label="Length" value={14} min={8} max={128} onChange={onChange} onSlide={onSlide} />)
		fireEvent.change(slider(), { target: { value: '20' } })
		expect(onSlide).toHaveBeenLastCalledWith(20)
		expect(onChange).not.toHaveBeenCalled()
		await user.clear(number())
		await user.type(number(), '32')
		expect(onChange).toHaveBeenLastCalledWith(32)
	})

	it('explains a raised minimum on both inputs and keeps the range above it usable', () => {
		render(<LengthSlider label="Length" value={20} min={20} max={128} lockedBy="Set by your organisation" onChange={vi.fn()} />)
		expect(slider().disabled).toBe(false)
		expect(slider().min).toBe('20')
		for (const input of [slider(), number()]) {
			expect(document.getElementById(input.getAttribute('aria-describedby')!)!.textContent).toBe('Set by your organisation')
		}
	})
})
