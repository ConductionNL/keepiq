// @vitest-environment happy-dom
import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ScanResult } from '@/src/totp/qr-scan'
import type { AuthKeyMode } from '@/src/vault/fields'
import { AuthenticatorKeyField } from './AuthenticatorKeyField'

afterEach(cleanup)

const LINK = 'otpauth://totp/Keepiq%20test%20site:test-user?secret=JBSWY3DPEHPK3PXP&issuer=Keepiq%20test%20site'

/** The form's part: it holds the mode and value the field reports. */
function Harness({ saved = false, removeOnly = false, scan = vi.fn<() => Promise<ScanResult>>(), popout = false, leftOut = false }: { saved?: boolean; removeOnly?: boolean; scan?: () => Promise<ScanResult>; popout?: boolean; leftOut?: boolean }) {
	const [mode, setMode] = useState<AuthKeyMode>('keep')
	const [value, setValue] = useState('')
	return (
		<>
			<AuthenticatorKeyField saved={saved} mode={mode} removeOnly={removeOnly} value={value} error={null} leftOut={leftOut} onMode={(next) => { setMode(next); setValue('') }} onValue={setValue} scan={scan} popout={popout} />
			<span data-testid="state">{`${mode}:${value}`}</span>
		</>
	)
}

const state = () => screen.getByTestId('state').textContent
const keyInput = (name = 'Authenticator key (optional)') => screen.getByLabelText<HTMLInputElement>(name)
const button = (name: string) => screen.getByRole<HTMLButtonElement>('button', { name })

describe('AuthenticatorKeyField', () => {
	it('takes a pasted key in a masked input with its help text', async () => {
		const user = userEvent.setup()
		render(<Harness />)
		expect(keyInput().type).toBe('password')
		expect(keyInput().getAttribute('autocomplete')).toBe('off')
		expect(screen.getByText('Paste the otpauth://totp/ link or the secret key, or scan the QR code on the page.')).toBeTruthy()
		await user.type(keyInput(), 'JBSW Y3DP')
		expect(state()).toBe('keep:JBSW Y3DP')
	})

	it('puts a scanned key in the field and says so in a status line', async () => {
		const user = userEvent.setup()
		const scan = vi.fn(async (): Promise<ScanResult> => ({ key: LINK }))
		render(<Harness scan={scan} />)
		await user.click(button('Scan QR code on page'))
		expect(scan).toHaveBeenCalledOnce()
		expect(keyInput().value).toBe(LINK)
		expect(keyInput().type).toBe('password')
		expect(screen.getByRole('status').textContent).toBe('Authenticator key read from the QR code')
	})

	it.each([
		['no_qr', 'No QR code found on the page. Scroll it into view and try again.'],
		['not_totp', 'This QR code does not hold an authenticator key. Use the QR code the site shows when you set up two-factor sign-in.'],
		['no_picture', 'Keepiq cannot take a picture of this page. Paste the key instead.'],
	] as const)('reads out a scan that finds no key: %s', async (failure, text) => {
		const user = userEvent.setup()
		render(<Harness scan={async () => ({ failure })} />)
		await user.click(button('Scan QR code on page'))
		expect(screen.getByRole('alert').textContent).toBe(text)
		expect(keyInput().getAttribute('aria-invalid')).toBe('true')
		expect(state()).toBe('keep:')
		await user.type(keyInput(), 'J')
		expect(screen.queryByRole('alert')).toBeNull()
	})

	it('disables the scan in the pop-out window and says why, while a paste still works', async () => {
		const user = userEvent.setup()
		const scan = vi.fn()
		render(<Harness popout scan={scan} />)
		expect(button('Scan QR code on page').disabled).toBe(true)
		expect(document.getElementById(keyInput().getAttribute('aria-describedby')!.split(' ')[0]!)!.textContent).toContain('Open Keepiq from the toolbar on the page with the QR code to scan it.')
		await user.type(keyInput(), 'JBSWY3DPEHPK3PXP')
		expect(state()).toBe('keep:JBSWY3DPEHPK3PXP')
	})

	it('shows a saved key as saved, never as text, with one remove button', () => {
		render(<Harness saved />)
		const group = screen.getByRole('group', { name: 'Authenticator key' })
		expect(group.textContent).toContain('Saved')
		expect(screen.getAllByRole('button').map((b) => b.getAttribute('aria-label') ?? b.textContent)).toEqual(['Remove authenticator key'])
		expect(document.querySelector('input')).toBeNull()
	})

	it('brings the input back on Remove for a new key or none, and Keep the saved key returns focus to Remove', async () => {
		const user = userEvent.setup()
		render(<Harness saved />)
		await user.click(button('Remove authenticator key'))
		expect(state()).toBe('replace:')
		expect(document.activeElement).toBe(keyInput('New authenticator key'))
		expect(screen.getByText('Paste or scan a new key, or leave this empty to remove the saved one.')).toBeTruthy()
		expect(button('Scan QR code on page')).toBeTruthy()
		await user.click(button('Keep the saved key'))
		expect(state()).toBe('keep:')
		expect(document.activeElement).toBe(button('Remove authenticator key'))
	})

	it('only removes where no key can be entered, with focus on Keep the saved key and back', async () => {
		const user = userEvent.setup()
		render(<Harness saved removeOnly />)
		await user.click(button('Remove authenticator key'))
		expect(state()).toBe('remove:')
		expect(screen.getByText('The authenticator key is removed when you save.')).toBeTruthy()
		expect(document.querySelector('input')).toBeNull()
		expect(document.activeElement).toBe(button('Keep the saved key'))
		await user.keyboard('{Enter}')
		expect(state()).toBe('keep:')
		expect(document.activeElement).toBe(button('Remove authenticator key'))
	})

	it('says a clone leaves the key out', () => {
		render(<Harness leftOut />)
		expect(screen.getByText('The authenticator key is not cloned.')).toBeTruthy()
	})
})
