import { i18n } from '#i18n'
import type { ShareSaveNote as Note } from '@/src/messages'
import { cloudIdList } from '../errors'
import { Banner } from './ErrorBanner'

/** What a save left to others, on the item's detail; Shell holds it for that item until the popup closes (share-sync). */
export function ShareSaveNote({ note, webAppUrl }: { note: Note; webAppUrl: string }) {
	return (
		<>
			{note.ownerOnly && <Banner tone="warning" role="status">{i18n.t('shareSave.ownerOnly')}</Banner>}
			{note.suspended && note.suspended.length > 0 && (
				<Banner tone="warning" role="status">
					{i18n.t('shareSave.suspended', { cloudIds: cloudIdList(note.suspended) })}
					<br /><a href={webAppUrl} target="_blank" rel="noreferrer">{i18n.t('common.openWebApp')}</a>
				</Banner>
			)}
			{note.paused && note.paused.length > 0 && <Banner tone="warning" role="status">{i18n.t('shareSave.paused', { cloudIds: cloudIdList(note.paused) })}</Banner>}
		</>
	)
}
