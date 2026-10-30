// @vitest-environment happy-dom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import type { ShareSaveNote as Note } from '@/src/messages'
import { setLocale } from '@/src/testing/i18n'
import { ShareSaveNote } from './ShareSaveNote'

afterEach(cleanup)

const WEB_APP = 'https://cloud.example.org/index.php/apps/keepiq/'
const shown = (note: Note) => {
	render(<ShareSaveNote note={note} webAppUrl={WEB_APP} />)
	return screen.queryAllByRole('status').map((banner) => banner.textContent)
}

describe('ShareSaveNote', () => {
	it.each([
		['en', { ownerOnly: true }, ['Saved. Recipients at partner organisations get this change when the owner next saves it.']],
		['nl', { ownerOnly: true }, ['Opgeslagen. Ontvangers bij partnerorganisaties krijgen deze wijziging zodra de eigenaar het item weer opslaat.']],
	] as const)('in %s, says %j', (locale, note, texts) => {
		setLocale(locale)
		expect(shown(note)).toEqual(texts)
	})

	it.each([
		['en', 'Sharing with bob@cloud.partner.example and carol@cloud.partner.example is paused, because their certificate could not be verified or their organisation no longer accepts it. The Keepiq web app shows the share.', 'Open the Keepiq web app'],
		['nl', 'Delen met bob@cloud.partner.example en carol@cloud.partner.example is gepauzeerd, omdat hun certificaat niet kon worden geverifieerd of hun organisatie de deling niet meer accepteert. De Keepiq-webapp toont de deling.', 'Open de Keepiq-webapp'],
	] as const)('in %s, names the suspended partners in a list of that language, with a link to the web app', (locale, text, link) => {
		setLocale(locale)
		expect(shown({suspended: ['bob@cloud.partner.example', 'carol@cloud.partner.example'] })).toEqual([text + link])
		expect(screen.getByRole('link', { name: link }).getAttribute('href')).toBe(WEB_APP)
	})

	it.each([
		['en', 'Sharing with bob@cloud.partner.example and carol@cloud.partner.example is paused until you share this item with them again.'],
		['nl', 'Delen met bob@cloud.partner.example en carol@cloud.partner.example is gepauzeerd tot je dit item opnieuw met hen deelt.'],
	] as const)('in %s, names the partners saved without on a line of their own', (locale, text) => {
		setLocale(locale)
		expect(shown({paused: ['bob@cloud.partner.example', 'carol@cloud.partner.example'] })).toEqual([text])
	})

	it('shows a suspended and a paused partner on two lines', () => {
		setLocale('en')
		expect(shown({suspended: ['bob@cloud.partner.example'], paused: ['carol@cloud.partner.example'] })).toEqual([
			expect.stringContaining('Sharing with bob@cloud.partner.example is paused, because'),
			'Sharing with carol@cloud.partner.example is paused until you share this item with them again.',
		])
	})

	it('shows nothing for a source save that suspended no one', () => {
		expect(shown({suspended: [], paused: [] })).toEqual([])
	})
})
