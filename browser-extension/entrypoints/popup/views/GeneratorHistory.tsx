import { useState } from 'react'
import { i18n } from '#i18n'
import { Button, IconButton } from '../components/Button'
import { ConfirmDialog } from '../components/ConfirmDialog'
import { EmptyState } from '../components/EmptyState'
import { ErrorBanner } from '../components/ErrorBanner'
import { ColouredValue } from '../components/GeneratedValue'
import { Loading } from '../components/Loading'
import { errorText } from '../errors'
import { useClipboard } from '../hooks/useClipboard'
import { useGeneratorHistory } from '../hooks/useGeneratorHistory'
import { fieldAction, rich, SLOT } from '../i18n'
import { relativeTime } from '../relative-time'
import { kindLabel } from './Generator'

export function GeneratorHistory() {
	const { entries, failed, clear } = useGeneratorHistory(true)
	const copy = useClipboard()
	const [confirming, setConfirming] = useState(false)
	const [clearFailed, setClearFailed] = useState(false)

	if (!entries) return failed ? <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner> : <Loading />
	if (entries.length === 0) return <EmptyState icon="history" title={i18n.t('generator.historyEmpty')} />

	async function confirmClear() {
		const cleared = await clear()
		setClearFailed(!cleared)
		if (cleared) setConfirming(false)
	}

	return (
		<div className="stack">
			<ul className="card fields">
				{entries.map((entry, i) => (
					<li key={`${entry.createdAt}-${i}`} className="field-row">
						<div className="field-row__text">
							<span className="field-row__label">
								{rich(i18n.t('generator.historyMeta', { kind: kindLabel(entry.kind), time: SLOT }), <time dateTime={entry.createdAt}>{relativeTime(entry.createdAt)}</time>)}
							</span>
							<ColouredValue value={entry.value} className="field-row__value" />
						</div>
						<IconButton icon="copy" label={fieldAction('field.copy', kindLabel(entry.kind))} onClick={() => void copy(entry.value, kindLabel(entry.kind))} />
					</li>
				))}
			</ul>
			<Button variant="secondary" onClick={() => setConfirming(true)}>{i18n.t('generator.clearHistory')}</Button>
			{confirming && (
				<ConfirmDialog
					title={i18n.t('generator.clearHistoryTitle')}
					actions={[{ label: i18n.t('generator.clearHistory'), variant: 'danger', onClick: () => void confirmClear() }]}
					onCancel={() => {
						setConfirming(false)
						setClearFailed(false)
					}}
					error={clearFailed ? errorText('not_responding', '') : null}
				>
					<p>{i18n.t('generator.clearHistoryText')}</p>
				</ConfirmDialog>
			)}
		</div>
	)
}
