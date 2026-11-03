// @vitest-environment happy-dom
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ErrorCode } from '@/src/messages'
import { setLocale } from '@/src/testing/i18n'
import { customField, type CustomField } from '@/src/vault/fields'
import { errorText, writeErrorText } from '../errors'
import { AdditionalFieldsEditor } from './AdditionalFieldsEditor'
import { Avatar, initials } from './Avatar'
import { Button } from './Button'
import { ConfirmDialog } from './ConfirmDialog'
import { FolderTree } from './FolderTree'
import { Header } from './Header'
import { TextArea } from './TextArea'
import { TextField } from './TextField'
import { TypeSelect } from './TypeSelect'
import { MaskedField } from './MaskedField'
import { PageFooter } from './PageFooter'
import { PinDialog } from './PinDialog'
import { SearchField } from './SearchField'
import { Select } from './Select'
import { TabBar } from './TabBar'
import { Toast } from './Toast'
import { DEFAULT_SHELL, ShellContext } from '../shell-context'
import { account, fakeBackground, fakeClipboard, sentOf, types } from '../testing'
import { FolderNameDialog } from './FolderNameDialog'

afterEach(cleanup)

describe('initials', () => {
	it.each([
		['Alice Doe', 'AD'],
		['alice', 'AL'],
		['Jan van der Berg', 'JB'],
		['  ', '?'],
	])('%j → %s', (name, expected) => {
		expect(initials(name)).toBe(expected)
	})
})

describe('Avatar', () => {
	it('shows the picture when there is one, else initials', () => {
		const { container, rerender } = render(<Avatar name="Alice Doe" dataUrl="data:image/png;base64,AA==" />)
		expect(container.querySelector('img')?.getAttribute('src')).toBe('data:image/png;base64,AA==')
		rerender(<Avatar name="Alice Doe" dataUrl={null} />)
		expect(container.textContent).toBe('AD')
	})
})

describe('Button', () => {
	it('is disabled and marked busy while busy', () => {
		render(<Button busy>Unlock</Button>)
		const button = screen.getByRole('button', { name: 'Unlock' })
		expect(button).toHaveProperty('disabled', true)
		expect(button.getAttribute('aria-busy')).toBe('true')
		expect(button.getAttribute('type')).toBe('button')
	})
})

describe('errorText', () => {
	it('has its own copy for every code, with a generic one for a code it does not know', () => {
		const codes: ErrorCode[] = [
			'insecure_url', 'invalid_url', 'permission_denied', 'unreachable', 'not_nextcloud', 'unauthorized', 'keepiq_missing',
			'no_active_suite', 'unlock_blocked', 'duplicate', 'limit_reached', 'invalid_master_password', 'offline_no_cache', 'session_revoked',
			'write_locked', 'server_error', 'not_responding',
		]
		const generic = errorText('unknown', 'h')
		expect(generic).toBe('Something went wrong')
		for (const code of codes) expect(errorText(code, 'h')).not.toBe(generic)
		expect(errorText('future_code' as ErrorCode, 'h')).toBe(generic)
		expect(errorText('unreachable', 'cloud.example.org')).toBe('Could not reach cloud.example.org')
		expect(errorText('limit_reached', 'h')).toBe('Maximum of 5 accounts reached')
	})

	it('words the PIN codes, counting the attempts left', () => {
		expect(errorText('invalid_pin', 'h', 1)).toBe('Wrong PIN, 1 attempt left')
		expect(errorText('invalid_pin', 'h', 3)).toBe('Wrong PIN, 3 attempts left')
		expect(errorText('invalid_pin', 'h')).toBe('Wrong PIN')
		expect(errorText('pin_forgotten', 'h')).toBe('PIN forgotten after too many attempts')
		expect(errorText('pin_key_changed', 'h')).toBe('Your vault key changed. Unlock with your master password.')
	})

	it('words a code in the browser language', () => {
		setLocale('nl')
		expect(errorText('unreachable', 'cloud.example.org')).toBe('Kan cloud.example.org niet bereiken')
	})
})

describe('Header', () => {
	it('shows the pop-out button when asked', async () => {
		const onPopout = vi.fn()
		render(<Header title="Vault" active={account()} onAvatarClick={() => {}} onPopout={onPopout} />)
		await userEvent.setup().click(screen.getByRole('button', { name: 'Pop out' }))
		expect(onPopout).toHaveBeenCalled()
	})

	it('leaves out the pop-out button when not given', () => {
		render(<Header title="Vault" active={account()} />)
		expect(screen.queryByRole('button', { name: 'Pop out' })).toBeNull()
		expect(screen.queryByRole('button', { name: 'New' })).toBeNull()
	})

	it('puts a primary New left of Pop out', async () => {
		const onAdd = vi.fn()
		render(<Header title="Vault" active={account()} onAvatarClick={() => {}} onAdd={onAdd} onPopout={() => {}} />)
		const buttons = screen.getAllByRole('button')
		const add = screen.getByRole('button', { name: 'New' })
		expect(add.className).toContain('btn--primary')
		expect(buttons.indexOf(add)).toBe(buttons.indexOf(screen.getByRole('button', { name: 'Pop out' })) - 1)
		await userEvent.setup().click(add)
		expect(onAdd).toHaveBeenCalled()
	})
})

describe('TabBar', () => {
	it('marks the active tab and reports a choice', async () => {
		const onSelect = vi.fn()
		render(<TabBar active="send" onSelect={onSelect} />)
		expect(screen.getByRole('button', { name: 'Send' }).getAttribute('aria-current')).toBe('page')
		expect(screen.getByRole('button', { name: 'Vault' }).getAttribute('aria-current')).toBeNull()
		await userEvent.setup().click(screen.getByRole('button', { name: 'Generator' }))
		expect(onSelect).toHaveBeenCalledWith('generator')
	})
})

describe('PageFooter', () => {
	it('renders into the shell footer, and nowhere without one', () => {
		const footer = document.body.appendChild(document.createElement('div'))
		const context = DEFAULT_SHELL
		const { rerender } = render(<ShellContext.Provider value={{ ...context, footer: null }}><PageFooter><button type="button">Edit</button></PageFooter></ShellContext.Provider>)
		expect(screen.queryByRole('button', { name: 'Edit' })).toBeNull()
		rerender(<ShellContext.Provider value={{ ...context, footer }}><PageFooter><button type="button">Edit</button></PageFooter></ShellContext.Provider>)
		expect(footer.contains(screen.getByRole('button', { name: 'Edit' }))).toBe(true)
		footer.remove()
	})
})

describe('Select', () => {
	it('is named by its label and reports the chosen value', async () => {
		const onChange = vi.fn()
		const options = [{ value: 'a', label: 'First' }, { value: 'b', label: 'Second' }]
		render(<Select label="Pick" value="a" options={options} onChange={onChange} />)
		const select = screen.getByRole<HTMLSelectElement>('combobox', { name: 'Pick' })
		expect(select.value).toBe('a')
		await userEvent.setup().selectOptions(select, 'Second')
		expect(onChange).toHaveBeenCalledWith('b')
	})

	it('shows a hint under the control and links it', () => {
		const { rerender } = render(<Select label="Pick" value="a" options={[{ value: 'a', label: 'First' }]} onChange={vi.fn()} hint="Set by your organisation" />)
		const select = screen.getByRole('combobox', { name: 'Pick' })
		expect(document.getElementById(select.getAttribute('aria-describedby')!)!.textContent).toBe('Set by your organisation')
		rerender(<Select label="Pick" value="a" options={[{ value: 'a', label: 'First' }]} onChange={vi.fn()} />)
		expect(select.getAttribute('aria-describedby')).toBeNull()
		expect(screen.queryByText('Set by your organisation')).toBeNull()
	})
})

/** Read out as it appears, and named by the focused field. */
function expectAnnounced(label: string, text: string) {
	const alert = screen.getByRole('alert')
	expect(alert.textContent).toBe(text)
	const field = screen.getByLabelText(label)
	expect(document.activeElement).toBe(field)
	expect(field.getAttribute('aria-describedby')!.split(' ')).toContain(alert.id)
}

describe('PinDialog', () => {
	function renderPin(replies: Record<string, unknown> = {}) {
		const sent = fakeBackground({ replies })
		const onDone = vi.fn()
		const onCancel = vi.fn()
		const refreshState = vi.fn()
		render(<ShellContext.Provider value={{ ...DEFAULT_SHELL, refreshState }}><PinDialog onDone={onDone} onCancel={onCancel} /></ShellContext.Provider>)
		return { sent, onDone, onCancel, refreshState, user: userEvent.setup() }
	}

	async function fill(user: ReturnType<typeof userEvent.setup>, pin: string, again = pin, masterPassword = 'hunter2') {
		if (masterPassword) await user.type(screen.getByLabelText('Master password'), masterPassword)
		await user.type(screen.getByLabelText('PIN'), pin)
		await user.type(screen.getByLabelText('Confirm PIN'), again)
	}

	it('advises against a reused PIN, says what a restart asks, and sets it with the master password', async () => {
		const { sent, onDone, user } = renderPin()
		const describedBy = (label: string) => screen.getByLabelText(label).getAttribute('aria-describedby')!.split(' ').map((id) => document.getElementById(id)!.textContent)
		expect(describedBy('PIN')).toEqual(['Don\'t reuse a PIN from your phone or bank.'])
		expect(describedBy('Master password')).toEqual(['After a browser restart you enter your master password once; then your PIN works again.'])
		expect(document.activeElement).toBe(screen.getByLabelText('Master password'))
		await fill(user, '246801')
		await user.click(screen.getByRole('button', { name: 'Turn on' }))
		expect(sentOf(sent, 'pin.set')).toEqual([{ kind: 'pin.set', accountId: 'a1', pin: '246801', masterPassword: 'hunter2' }])
		expect(onDone).toHaveBeenCalledOnce()
	})

	it('refuses a short PIN before sending anything, focusing it', async () => {
		const { sent, user } = renderPin()
		await fill(user, '12345', '12345{Enter}')
		expectAnnounced('PIN', 'Use at least 6 characters')
		expect(sentOf(sent, 'pin.set')).toEqual([])
	})

	it('counts characters, not UTF-16 units, as the background does', async () => {
		const { sent, user } = renderPin()
		await fill(user, '😀😀😀😀😀', '😀😀😀😀😀{Enter}')
		expectAnnounced('PIN', 'Use at least 6 characters')
		expect(sentOf(sent, 'pin.set')).toEqual([])
	})

	it('refuses PINs that differ, focusing the second once it names the error', async () => {
		const { sent, user } = renderPin()
		await fill(user, '246801', '246812')
		const again = screen.getByLabelText('Confirm PIN')
		let describedOnFocus: string | null = null
		again.addEventListener('focus', () => (describedOnFocus = again.getAttribute('aria-describedby')))
		await user.click(screen.getByRole('button', { name: 'Turn on' }))
		expectAnnounced('Confirm PIN', 'PINs don\'t match')
		expect(describedOnFocus).toContain(screen.getByRole('alert').id)
		expect(sentOf(sent, 'pin.set')).toEqual([])
	})

	it('refuses an empty master password before sending anything', async () => {
		const { sent, user } = renderPin()
		await fill(user, '246801', '246801', '')
		await user.click(screen.getByRole('button', { name: 'Turn on' }))
		expectAnnounced('Master password', 'Enter your master password')
		expect(sentOf(sent, 'pin.set')).toEqual([])
	})

	it('says when the master password is wrong and sets nothing', async () => {
		const { onDone, user } = renderPin({ 'pin.set': { ok: false, code: 'invalid_master_password' } })
		await fill(user, '246801')
		await user.click(screen.getByRole('button', { name: 'Turn on' }))
		expect(await screen.findByText('Invalid master password')).toBeTruthy()
		expect(screen.getByLabelText<HTMLInputElement>('Master password').value).toBe('')
		expect(onDone).not.toHaveBeenCalled()
	})

	it('re-reads the popup state when the account locked meanwhile', async () => {
		const { refreshState, onCancel, user } = renderPin({ 'pin.set': { ok: false, code: 'locked' } })
		await fill(user, '246801')
		await user.click(screen.getByRole('button', { name: 'Turn on' }))
		expect(refreshState).toHaveBeenCalled()
		expect(onCancel).toHaveBeenCalled()
	})
})

describe('Toast', () => {
	it('keeps its live region mounted while empty', () => {
		const { rerender } = render(<Toast message={null} />)
		expect(screen.getByRole('status').textContent).toBe('')
		rerender(<Toast message="Password copied" />)
		expect(screen.getByRole('status').textContent).toBe('Password copied')
	})
})

describe('MaskedField', () => {
	it('keeps a masked value out of the DOM until revealed, and copies it', async () => {
		const user = userEvent.setup()
		const writeText = fakeClipboard()
		const toast = vi.fn()
		vi.spyOn(browser.runtime, 'sendMessage').mockResolvedValue(null as never)
		render(<ShellContext.Provider value={{ ...DEFAULT_SHELL, toast }}><MaskedField label="CVV" value="123" masked /></ShellContext.Provider>)
		expect(screen.queryByText('123')).toBeNull()
		await user.click(screen.getByRole('button', { name: 'Show CVV' }))
		expect(screen.getByText('123')).toBeTruthy()
		expect(screen.getByRole('button', { name: 'Hide CVV' }).getAttribute('aria-pressed')).toBe('true')
		await user.click(screen.getByRole('button', { name: 'Copy CVV' }))
		expect(writeText).toHaveBeenCalledWith('123')
		expect(toast).toHaveBeenCalledWith('CVV copied')
	})

	it.each([['CR', 'line1\rline2'], ['CRLF', 'line1\r\nline2']])('shows a %s value on its own lines, and copies it as stored', async (_, value) => {
		const user = userEvent.setup()
		const writeText = fakeClipboard()
		vi.spyOn(browser.runtime, 'sendMessage').mockResolvedValue(null as never)
		render(<ShellContext.Provider value={{ ...DEFAULT_SHELL, toast: vi.fn() }}><MaskedField label="Key" value={value} masked /></ShellContext.Provider>)
		await user.click(screen.getByRole('button', { name: 'Show key' }))
		expect(document.querySelector('.field-row__value')!.textContent).toBe('line1\nline2')
		await user.click(screen.getByRole('button', { name: 'Copy key' }))
		expect(writeText).toHaveBeenCalledWith(value)
	})

	it('shows a plain value with only copy', () => {
		render(<MaskedField label="Username" value="alice" />)
		expect(screen.getByText('alice')).toBeTruthy()
		expect(screen.queryByRole('button', { name: /^Show/ })).toBeNull()
		expect(screen.getByRole('button', { name: 'Copy username' })).toBeTruthy()
	})
})

describe('ConfirmDialog focusing Cancel', () => {
	it('starts on Cancel when asked, for a risky action', () => {
		render(<ConfirmDialog title="Keep unlocked?" actions={[{ label: 'Keep unlocked', variant: 'danger', onClick: vi.fn() }]} onCancel={vi.fn()} focusCancel />)
		expect(document.activeElement?.textContent).toBe('Cancel')
	})
})

describe('TextField announcing its error', () => {
	it('reads the error out when asked, and only then', () => {
		const { rerender } = render(<TextField label="Domain" value="" onChange={vi.fn()} error="Enter a domain" announce />)
		expect(screen.getByRole('alert').textContent).toBe('Enter a domain')
		rerender(<TextField label="Domain" value="" onChange={vi.fn()} error="Enter a domain" />)
		expect(screen.queryByRole('alert')).toBeNull()
	})
})

describe('TextField with an error shown elsewhere', () => {
	it('keeps its own description and adds the given one', () => {
		render(<><TextField label="Hours" value="" onChange={vi.fn()} hint="Whole hours" aria-invalid aria-describedby="shared" /><p id="shared">Enter at least one minute</p></>)
		const field = screen.getByLabelText('Hours')
		expect(field.getAttribute('aria-invalid')).toBe('true')
		expect(field.getAttribute('aria-describedby')!.split(' ').map((id) => document.getElementById(id)!.textContent)).toEqual(['Whole hours', 'Enter at least one minute'])
	})
})

describe('ConfirmDialog', () => {
	it('focuses inside, cancels on Escape and gives focus back', async () => {
		const opener = document.body.appendChild(document.createElement('button'))
		opener.focus()
		const onCancel = vi.fn()
		const onClick = vi.fn()
		const { unmount } = render(<ConfirmDialog title="Delete item" actions={[{ label: 'Delete', variant: 'danger', onClick }]} onCancel={onCancel}><p>Sure?</p></ConfirmDialog>)
		const dialog = screen.getByRole('dialog', { name: 'Delete item' })
		expect(dialog.getAttribute('aria-modal')).toBe('true')
		expect(document.activeElement?.textContent).toBe('Delete')
		const user = userEvent.setup()
		await user.click(screen.getByRole('button', { name: 'Delete' }))
		expect(onClick).toHaveBeenCalled()
		await user.keyboard('{Escape}')
		expect(onCancel).toHaveBeenCalled()
		unmount()
		expect(document.activeElement).toBe(opener)
		opener.remove()
	})

	it('keeps Tab inside the dialog', async () => {
		render(<ConfirmDialog title="Delete item" actions={[{ label: 'Delete', onClick: () => {} }]} onCancel={() => {}} />)
		const user = userEvent.setup()
		await user.tab()
		expect(document.activeElement?.textContent).toBe('Cancel')
		await user.tab()
		expect(document.activeElement?.textContent).toBe('Delete')
	})

	it('shows an error with Retry', async () => {
		const onRetry = vi.fn()
		render(<ConfirmDialog title="Delete item" actions={[]} onCancel={() => {}} error="Could not reach the server" onRetry={onRetry} />)
		expect(screen.getByRole('alert').textContent).toContain('Could not reach the server')
		await userEvent.setup().click(screen.getByRole('button', { name: 'Try again' }))
		expect(onRetry).toHaveBeenCalled()
	})
})

describe('AdditionalFieldsEditor', () => {
	function Editor({ initial = [] as CustomField[] }) {
		const [fields, setFields] = useState(initial)
		return <AdditionalFieldsEditor fields={fields} errors={{}} onChange={setFields} />
	}

	it('adds, masks, reveals and removes rows', async () => {
		render(<Editor />)
		const user = userEvent.setup()
		await user.click(screen.getByRole('button', { name: 'Add field' }))
		await user.type(screen.getByLabelText('Field name'), 'pin')
		const value = screen.getByLabelText('pin') as HTMLInputElement
		expect(value.type).toBe('password')
		await user.click(screen.getByRole('button', { name: 'Show pin' }))
		expect(value.type).toBe('text')
		await user.click(screen.getByRole('button', { name: 'Remove pin' }))
		expect(screen.queryByLabelText('Field name')).toBeNull()
	})

	it.each([
		['LF', 'v1\nv2', 'v1\nv2!'],
		['CRLF', 'v1\r\nv2', 'v1\r\nv2!'],
		['CR', 'v1\rv2', 'v1\rv2!'],
	])('keeps the %s lines of a value through an edit, masked until shown', async (_, value, sent) => {
		const onChange = vi.fn()
		const field = customField('recovery', value)
		render(<AdditionalFieldsEditor fields={[field]} errors={{}} onChange={onChange} />)
		expect(document.body.textContent).not.toContain('v1')
		expect(screen.getByRole('group', { name: 'recovery' })).toBeTruthy()
		await userEvent.setup().click(screen.getByRole('button', { name: 'Show recovery' }))
		const area = screen.getByLabelText('recovery') as HTMLTextAreaElement
		expect([area.tagName, area.value]).toEqual(['TEXTAREA', value])
		// happy-dom hands a text area's value back unchanged, where a browser gives LF.
		fireEvent.change(area, { target: { value: 'v1\nv2!' } })
		expect(onChange).toHaveBeenCalledWith([{ ...field, value: sent }])
	})

	it('keeps a value in its text area, and the focus there, once its last line break is deleted', async () => {
		render(<Editor initial={[customField('recovery', 'v1\nv2')]} />)
		await userEvent.setup().click(screen.getByRole('button', { name: 'Show recovery' }))
		const area = screen.getByLabelText('recovery')
		area.focus()
		fireEvent.change(area, { target: { value: 'v1v2' } })
		expect(screen.getByLabelText('recovery')).toBe(area)
		expect(document.activeElement).toBe(area)
	})

	it('shows the errors it is given on their row', () => {
		const field = customField('Login', 'x')
		render(<AdditionalFieldsEditor fields={[field]} errors={{ [`field.${field.id}.name`]: { code: 'reserved' } }} onChange={() => {}} />)
		expect(screen.getByText('This name is reserved')).toBeTruthy()
	})
})

describe('FolderTree and TypeSelect', () => {
	it('indents children under their parent, sorted by name', () => {
		const folders = [{ id: 'b', name: 'beta', parentId: null }, { id: 'c', name: 'Clients', parentId: 'w' }, { id: 'w', name: 'Work', parentId: null }]
		render(<FolderTree folders={folders} row={(folder) => folder.name} />)
		const rows = screen.getAllByRole('listitem')
		expect(rows.map((row) => [row.textContent, row.style.getPropertyValue('--depth')])).toEqual([['beta', '0'], ['Work', '0'], ['Clients', '1']])
	})

	it('offers a passkey only as the type an item already has', () => {
		const { rerender } = render(<TypeSelect types={types} value="t-login" onChange={() => {}} />)
		const labels = () => [...(screen.getByLabelText('Type') as HTMLSelectElement).options].map((option) => option.textContent)
		expect(labels()).not.toContain('Passkey')
		rerender(<TypeSelect types={types} value="t-passkey" onChange={() => {}} disabled />)
		expect(labels()).toContain('Passkey')
	})
})

describe('FolderNameDialog', () => {
	it('refuses a blank name before any request', async () => {
		const sent = fakeBackground()
		render(<FolderNameDialog target={{ create: { parentId: null } }} onDone={() => {}} onCancel={() => {}} />)
		await userEvent.setup().click(screen.getByRole('button', { name: 'Save' }))
		expect(screen.getByText('Name is required')).toBeTruthy()
		expect(sentOf(sent, 'folder.create')).toEqual([])
	})

	it('renames from the current name, trimmed', async () => {
		const sent = fakeBackground()
		const onDone = vi.fn()
		render(<FolderNameDialog target={{ rename: { id: 'w', name: 'Work' } }} onDone={onDone} onCancel={() => {}} />)
		const user = userEvent.setup()
		expect(screen.getByRole('dialog', { name: 'Rename folder' })).toBeTruthy()
		await user.type(screen.getByLabelText('Folder name'), ' 2 {Enter}')
		await vi.waitFor(() => expect(onDone).toHaveBeenCalledWith(null))
		expect(sentOf(sent, 'folder.rename')).toEqual([{ kind: 'folder.rename', accountId: 'a1', id: 'w', name: 'Work 2' }])
	})
})

describe('TextArea', () => {
	it('is labelled and shows its error', async () => {
		const onChange = vi.fn()
		render(<TextArea label="Notes" value="" onChange={onChange} error="Too long (max 65536 bytes)" />)
		const area = screen.getByRole('textbox', { name: 'Notes' })
		expect(area.getAttribute('aria-invalid')).toBe('true')
		expect(screen.getByText('Too long (max 65536 bytes)')).toBeTruthy()
		await userEvent.setup().type(area, 'a')
		expect(onChange).toHaveBeenCalledWith('a')
	})

	it('when concealed, keeps the value out of the page until shown, and the toggle focused', async () => {
		const user = userEvent.setup()
		render(<TextArea label="Password" value={'line1\nline2'} onChange={vi.fn()} concealed />)
		expect(screen.queryByRole('textbox')).toBeNull()
		expect(document.body.textContent).not.toContain('line1')
		await user.click(screen.getByRole('button', { name: 'Show password' }))
		expect((screen.getByRole('textbox', { name: 'Password' }) as HTMLTextAreaElement).value).toBe('line1\nline2')
		const hide = screen.getByRole('button', { name: 'Hide password' })
		expect([hide.getAttribute('aria-pressed'), document.activeElement]).toEqual(['true', hide])
		await user.click(hide)
		expect(screen.queryByRole('textbox')).toBeNull()
	})

	it('when concealed, names the dots by its label and ties its error to them', () => {
		render(<TextArea label="Password" value={'line1\nline2'} onChange={vi.fn()} error="Too long (max 65536 bytes)" concealed />)
		const dots = screen.getByLabelText('Password')
		expect(screen.getByRole('group', { name: 'Password' })).toBe(dots)
		expect(dots.textContent).toBe('••••••••')
		expect(document.getElementById(dots.getAttribute('aria-describedby')!)!.textContent).toBe('Too long (max 65536 bytes)')
		// Pointing at no control until shown, a `for` would leave the label naming nothing.
		expect(document.querySelector('label')!.hasAttribute('for')).toBe(false)
	})

	it('is never spell-checked, and autocorrected only when not concealed', async () => {
		const attributes = (area: HTMLElement) => ['spellcheck', 'autocapitalize', 'autocorrect'].map((name) => area.getAttribute(name))
		render(<><TextArea label="Notes" value="" onChange={vi.fn()} /><TextArea label="Key" value="k" onChange={vi.fn()} concealed /></>)
		await userEvent.setup().click(screen.getByRole('button', { name: 'Show key' }))
		expect(attributes(screen.getByRole('textbox', { name: 'Notes' }))).toEqual(['false', null, null])
		expect(attributes(screen.getByRole('textbox', { name: 'Key' }))).toEqual(['false', 'off', 'off'])
	})
})

describe('TextField', () => {
	it('never spell-checks or autocorrects a password field, also once revealed', async () => {
		render(<TextField label="Password" type="password" value="hunter2" onChange={vi.fn()} />)
		const input = screen.getByLabelText('Password') as HTMLInputElement
		const attributes = () => [input.type, input.getAttribute('spellcheck'), input.getAttribute('autocapitalize'), input.getAttribute('autocorrect')]
		expect(attributes()).toEqual(['password', 'false', 'off', 'off'])
		await userEvent.setup().click(screen.getByRole('button', { name: 'Show password' }))
		expect(attributes()).toEqual(['text', 'false', 'off', 'off'])
	})

	it('never spell-checks any other field, and autocorrects only one not kept verbatim', () => {
		render(<><TextField label="Name" value="" onChange={vi.fn()} /><TextField label="Username" value="" onChange={vi.fn()} verbatim /></>)
		const attributes = (label: string) => ['spellcheck', 'autocapitalize', 'autocorrect'].map((name) => screen.getByLabelText(label).getAttribute(name))
		expect(attributes('Name')).toEqual(['false', null, null])
		expect(attributes('Username')).toEqual(['false', 'off', 'off'])
	})

	it('puts an action after the show toggle, named by its label', async () => {
		const onClick = vi.fn()
		render(<TextField label="Password" type="password" value="" onChange={vi.fn()} action={{ icon: 'generate', label: 'Generate password', onClick }} />)
		const buttons = screen.getAllByRole('button')
		expect(buttons.map((button) => button.getAttribute('aria-label'))).toEqual(['Show password', 'Generate password'])
		await userEvent.setup().click(buttons[1]!)
		// The button itself, since a mouse click does not focus it in every browser.
		expect(onClick).toHaveBeenCalledExactlyOnceWith(buttons[1])
	})

	it('has no action unless given one', () => {
		render(<TextField label="Name" value="" onChange={vi.fn()} />)
		expect(screen.queryByRole('button')).toBeNull()
	})
})

describe('SearchField', () => {
	it('is never spell-checked', () => {
		render(<SearchField value="github" onChange={vi.fn()} />)
		expect(screen.getByRole('searchbox').getAttribute('spellcheck')).toBe('false')
	})
})

describe('writeErrorText', () => {
	it('words a conflict by what was being written', () => {
		const conflict = { code: 'conflict' } as const
		expect(writeErrorText(conflict, 'edit')).toBe('This item was changed elsewhere. Go back and edit it again to see the latest version.')
		expect(writeErrorText(conflict, 'folderName')).toBe('A folder with this name already exists here')
		expect(writeErrorText(conflict, 'folderDelete')).toBe('This folder changed. Check the choices again.')
		expect(writeErrorText(undefined)).toBe('Keepiq is not responding. Reload the extension and reopen this popup.')
	})

	it('words a blocked row by its reason', () => {
		expect(writeErrorText({ code: 'blocked', reason: 'suite_revoked' })).toBe('Its encryption key was revoked.')
		expect(writeErrorText({ code: 'blocked' })).toBe('This item is blocked.')
	})

	it('names a policy refusal, and keeps any other refusal neutral', () => {
		expect(writeErrorText({ code: 'policy' })).toBe("Your organisation's policy does not allow this change")
		expect(writeErrorText({ code: 'forbidden' })).toBe('Keepiq does not allow this change. The Keepiq web app shows why.')
	})

	it.each([
		['stale_source', 'The original of this shared item was changed after you opened it. Nothing was saved; open it again and redo your change.'],
		['incomplete', 'The people this item is shared with kept changing while it was saved. Nothing was saved; please try again.'],
		['bad_certificate', 'Keepiq sent an encryption key for someone this item is shared with that this browser could not read. Nothing was saved.'],
		['unverified', "This item is shared with partner organisations, and this browser can't confirm they would get it exactly as you saw it. Nothing was saved; make this change in the Keepiq web app."],
		['partner_lookup_limit', 'Too many partner lookups. Nothing was saved; try again in a minute.'],
		['federation_unavailable', "Your Keepiq server can't share with partner organisations, so this item can't be saved here. Nothing was saved; ask your administrator."],
	] as const)('words a failed share-save: %s', (code, text) => {
		expect(writeErrorText({ code }, 'edit')).toBe(text)
	})

	it.each([
		['incomplete', 'Met wie dit item gedeeld is, veranderde steeds tijdens het opslaan. Er is niets opgeslagen; probeer het opnieuw.'],
		['partner_lookup_limit', 'Te veel zoekopdrachten naar partners. Er is niets opgeslagen; probeer het over een minuut opnieuw.'],
		['federation_unavailable', 'Je Keepiq-server kan niet delen met partnerorganisaties, dus dit item kan hier niet worden opgeslagen. Er is niets opgeslagen; vraag het je beheerder.'],
	] as const)('words a failed share-save in Dutch: %s', (code, text) => {
		setLocale('nl')
		expect(writeErrorText({ code }, 'edit')).toBe(text)
	})

	it('names a partner too old for an authenticator key, with the version when the server gave one', () => {
		const partners = [{ shareId: 'fs-bob', cloudId: 'bob@cloud.partner.example' }]
		const text = 'Nothing was saved: the Keepiq of bob@cloud.partner.example is too old to receive an authenticator key. Remove the key, or ask them to update Keepiq.'
		expect(writeErrorText({ code: 'partner_upgrade_required', partners }, 'edit')).toBe(text)
		expect(writeErrorText({ code: 'partner_upgrade_required', partners, requiredVersion: '0.3.12' }, 'edit')).toBe(`${text} They need Keepiq 0.3.12 or later.`)
		expect(writeErrorText({ code: 'partner_upgrade_required', partners: [] }, 'edit')).toBe('Nothing was saved: a partner organisation this item is shared with runs a Keepiq too old to receive an authenticator key. Remove the key, or ask them to update Keepiq.')
		setLocale('nl')
		expect(writeErrorText({ code: 'partner_upgrade_required', partners, requiredVersion: '0.3.12' }, 'edit')).toBe('Er is niets opgeslagen: de Keepiq van bob@cloud.partner.example is te oud om een authenticatorsleutel te ontvangen. Verwijder de sleutel, of vraag hen Keepiq bij te werken. Ze hebben Keepiq 0.3.12 of nieuwer nodig.')
	})

	it.each([
		['en', "Nothing was saved: a partner organisation this item is shared with can't be reached (bob@cloud.partner.example and carol@cloud.partner.example). Try again later, or save without them, which pauses sharing this item with them until you share it with them again."],
		['nl', 'Er is niets opgeslagen: een partnerorganisatie waarmee dit item gedeeld is, is niet bereikbaar (bob@cloud.partner.example en carol@cloud.partner.example). Probeer het later opnieuw, of sla op zonder hen. Dan wordt het delen van dit item met hen gepauzeerd tot je het opnieuw met hen deelt.'],
	] as const)('names the unreachable partners in a list of the catalog\'s language: %s', (locale, text) => {
		setLocale(locale)
		const partners = [{ shareId: 'fs-bob', cloudId: 'bob@cloud.partner.example' }, { shareId: 'fs-carol', cloudId: 'carol@cloud.partner.example' }]
		expect(writeErrorText({ code: 'partner_unreachable', partners }, 'edit')).toBe(text)
	})
})
