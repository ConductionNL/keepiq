// @vitest-environment happy-dom
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { generate } from '@/src/generator/generate'
import { defaultOptions, type GeneratorKind, type GeneratorPolicy } from '@/src/generator/options'
import { DEFAULT_SHELL, ShellContext } from '../shell-context'
import { fakeBackground } from '../testing'
import { Generator, type GeneratorPick } from './Generator'

vi.mock('@/src/generator/generate', async (original) => ({ ...await original<typeof import('@/src/generator/generate')>(), generate: vi.fn() }))

// happy-dom gives import.meta.url a non-file scheme, so the record is found from the package root.
const GOLDEN = resolve(process.cwd(), 'entrypoints/popup/views/Generator.golden.json')

beforeEach(() => {
	vi.mocked(generate).mockImplementation(async (kind) => ({ ok: true, value: `${kind}-1` }))
})
afterEach(() => {
	cleanup()
	document.body.replaceChildren()
})

const policy = (allowPassphrase: boolean): GeneratorPolicy => ({
	minLength: 8, requireUpper: false, requireLower: false, requireDigit: false, requireSymbol: false, allowPassphrase,
})

/** The sub-tabs, the options shown, the history link and the pick footer of one generator. */
async function view(tab: GeneratorKind, allowPassphrase: boolean, pick?: GeneratorPick['target'], failing = false) {
	if (failing) vi.mocked(generate).mockResolvedValue({ ok: false, failure: 'wordlist_unavailable' })
	const options = defaultOptions()
	options.tab = tab
	fakeBackground({ generator: { options, policy: policy(allowPassphrase) } })
	const footer = document.body.appendChild(document.createElement('div'))
	render(
		<ShellContext.Provider value={{ ...DEFAULT_SHELL, toast: vi.fn(), footer }}>
			<Generator onOpenHistory={pick ? undefined : () => {}} pick={pick ? { target: pick, onUse: vi.fn() } : undefined} />
		</ShellContext.Provider>,
	)
	if (failing) await screen.findByText('Wordlist unavailable')
	else await waitFor(() => expect(document.querySelector('.generated__value')?.textContent).toMatch(/-1$/))
	const out = {
		tabs: screen.queryAllByRole('tab').map((t) => `${t.textContent}${t.getAttribute('aria-selected') === 'true' ? '*' : ''}${t.hasAttribute('disabled') || t.getAttribute('aria-disabled') === 'true' ? ' (off)' : ''}`),
		value: document.querySelector('.generated__value')?.textContent,
		controls: [...document.querySelectorAll('.form-card input, .form-card select')].map((el) => `${el.getAttribute('type') ?? el.tagName.toLowerCase()}:${el.getAttribute('aria-label') ?? (el as HTMLInputElement).labels?.[0]?.textContent ?? '?'}`),
		history: screen.queryByRole('button', { name: 'Generator history' }) !== null,
		footer: [...footer.querySelectorAll('button')].map((b) => `${b.textContent}${b.disabled ? ' (off)' : ''}`),
	}
	cleanup()
	document.body.replaceChildren()
	return out
}

async function record() {
	const got: Record<string, unknown> = {}
	for (const pick of [undefined, 'password', 'username'] as const) {
		for (const tab of ['password', 'passphrase', 'username'] as const) {
			for (const allowed of [true, false]) {
				got[`pick=${pick ?? 'none'} tab=${tab} passphrase=${allowed}`] = await view(tab, allowed, pick)
			}
		}
	}
	got['pick=password tab=passphrase failing'] = await view('passphrase', true, 'password', true)
	got['pick=username tab=username failing'] = await view('username', true, 'username', true)
	return got
}

describe('Generator golden record', () => {
	it('shows the recorded tabs, options, history link and pick footer for every mode', async () => {
		const got = await record()
		if (process.env.KEEPIQ_RECORD_GOLDEN === '1') writeFileSync(GOLDEN, JSON.stringify(got, null, '\t') + '\n')
		expect(got).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	}, 60_000)
})
