// @vitest-environment happy-dom
import { cleanup, render, screen } from '@testing-library/react'
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
import { TypeSelect } from './TypeSelect'
import { MaskedField } from './MaskedField'
import { PageFooter } from './PageFooter'
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

	it('shows a plain value with only copy', () => {
		render(<MaskedField label="Username" value="alice" />)
		expect(screen.getByText('alice')).toBeTruthy()
		expect(screen.queryByRole('button', { name: /^Show/ })).toBeNull()
		expect(screen.getByRole('button', { name: 'Copy username' })).toBeTruthy()
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
})
