// @vitest-environment happy-dom
import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { OptionCheckbox } from './OptionCheckbox'

afterEach(cleanup)

const checkbox = (name = 'Numbers') => screen.getByRole<HTMLInputElement>('checkbox', { name })

describe('OptionCheckbox', () => {
	it('is a labelled checkbox that reports the new state', async () => {
		const user = userEvent.setup()
		const onChange = vi.fn()
		render(<OptionCheckbox label="Numbers" checked onChange={onChange} />)
		expect(checkbox().checked).toBe(true)
		expect(checkbox().getAttribute('aria-disabled')).toBeNull()
		await user.click(screen.getByText('Numbers'))
		expect(onChange).toHaveBeenCalledWith(false)
	})

	it('describes itself with a hint beside a lock reason', () => {
		render(<OptionCheckbox label="Autofill on page load" checked onChange={vi.fn()} hint="Careful" lockedBy="Coming later" />)
		const ids = checkbox('Autofill on page load').getAttribute('aria-describedby')!.split(' ')
		expect(ids.map((id) => document.getElementById(id)!.textContent)).toEqual(['Coming later', 'Careful'])
	})

	it('shows the short text while keeping the full label as its name', () => {
		render(<OptionCheckbox label="Numbers (0-9)" short="0-9" checked onChange={vi.fn()} />)
		expect(checkbox('Numbers (0-9)')).toBeTruthy()
		expect(screen.getByText('0-9')).toBeTruthy()
		expect(screen.queryByText('Numbers (0-9)')).toBeNull()
	})

	it('stays focusable when locked, refusing the change and describing the reason', async () => {
		const user = userEvent.setup()
		const onChange = vi.fn()
		render(<OptionCheckbox label="Numbers" checked onChange={onChange} lockedBy="Set by your organisation" />)
		expect(checkbox().disabled).toBe(false)
		expect(checkbox().getAttribute('aria-disabled')).toBe('true')
		expect(document.getElementById(checkbox().getAttribute('aria-describedby')!)!.textContent).toBe('Set by your organisation')
		await user.tab()
		expect(document.activeElement).toBe(checkbox())
		await user.keyboard(' ')
		await user.click(checkbox())
		expect(onChange).not.toHaveBeenCalled()
		expect(checkbox().checked).toBe(true)
	})

	it('describes itself with a note without refusing the change', async () => {
		const user = userEvent.setup()
		const onChange = vi.fn()
		render(<OptionCheckbox label="Numbers" checked onChange={onChange} note="Partly set elsewhere" hint="Careful" />)
		expect(checkbox().getAttribute('aria-disabled')).toBeNull()
		const ids = checkbox().getAttribute('aria-describedby')!.split(' ')
		expect(ids.map((id) => document.getElementById(id)!.textContent)).toEqual(['Partly set elsewhere', 'Careful'])
		await user.click(checkbox())
		expect(onChange).toHaveBeenCalledWith(false)
	})

	it('points at a reason shown once for its row, refusing the change', async () => {
		const user = userEvent.setup()
		const onChange = vi.fn()
		render(
			<>
				<OptionCheckbox label="Numbers" checked onChange={onChange} reasonId="row-hint" />
				<p id="row-hint">Set by your organisation</p>
			</>,
		)
		expect(checkbox().getAttribute('aria-disabled')).toBe('true')
		expect(checkbox().getAttribute('aria-describedby')).toBe('row-hint')
		expect(screen.getAllByText('Set by your organisation')).toHaveLength(1)
		await user.click(checkbox())
		expect(onChange).not.toHaveBeenCalled()
	})

	it('can refuse changes without a reason', async () => {
		const user = userEvent.setup()
		const onChange = vi.fn()
		render(<OptionCheckbox label="Numbers" checked disabled onChange={onChange} />)
		expect(checkbox().getAttribute('aria-disabled')).toBe('true')
		expect(checkbox().getAttribute('aria-describedby')).toBeNull()
		await user.click(checkbox())
		expect(onChange).not.toHaveBeenCalled()
	})
})
