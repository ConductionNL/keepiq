// @vitest-environment happy-dom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { DEFAULT_SHELL, ShellContext } from '../shell-context'
import { fakeBackground } from '../testing'
import { Generator } from './Generator'

// Generator.test.tsx mocks `generate`; this file runs the real port.
afterEach(() => {
	cleanup()
	document.body.replaceChildren()
})

const shown = () => document.querySelector('.generated__value')?.textContent ?? ''

describe('Generator with the real generator', () => {
	it('opens on a 14-character password and gives 20 characters at length 20', async () => {
		fakeBackground()
		render(<ShellContext.Provider value={DEFAULT_SHELL}><Generator /></ShellContext.Provider>)
		await waitFor(() => expect(shown()).toMatch(/^[A-HJ-NP-Za-km-z2-9]{14}$/))
		expect(shown()).toMatch(/[2-9]/)

		fireEvent.change(screen.getByRole('slider', { name: 'Length' }), { target: { value: '20' } })
		await waitFor(() => expect(shown()).toHaveLength(20))
	})
})
