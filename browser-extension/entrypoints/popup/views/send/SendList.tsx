import { useEffect, useRef, useState } from 'react'
import { i18n } from '#i18n'
import type { SendRow as Row, VaultWriteError } from '@/src/messages'
import { Button } from '../../components/Button'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { EmptyState } from '../../components/EmptyState'
import { Banner } from '../../components/ErrorBanner'
import { Loading } from '../../components/Loading'
import { SendRow, sendLabel } from '../../components/SendRow'
import { writeErrorText } from '../../errors'
import { useSends } from '../../hooks/useSends'
import { useShell } from '../../shell-context'

type Removing = { row: Row; busy: boolean; failed: boolean; error?: VaultWriteError }

/** Labels with a number where two sends share one, so every button's name is unique. */
function rowNames(rows: Row[]): string[] {
	const labels = rows.map(sendLabel)
	const seen = new Map<string, number>()
	return labels.map((label) => {
		if (labels.indexOf(label) === labels.lastIndexOf(label)) return label
		const n = (seen.get(label) ?? 0) + 1
		seen.set(label, n)
		return `${label} (${n})`
	})
}

/** `focusId`: the row to focus once listed, e.g. back from a created send whose page held focus. */
export function SendList({ onNew, focusId = null }: { onNew: () => void; focusId?: string | null }) {
	const { sends, failed, load, retry, linkFor, remove } = useSends()
	const { toast } = useShell()
	const [removing, setRemoving] = useState<Removing | null>(null)
	const area = useRef<HTMLDivElement>(null)
	/** The row index focus goes to once the list is there: the one after a removed row. */
	const focusAt = useRef<number | null>(null)
	// The refresh after a create may still be out, so wait until the new row is listed.
	const focusRow = useRef(focusId)

	useEffect(load, [load])

	useEffect(() => {
		if (removing) return
		if (failed && (focusRow.current !== null || focusAt.current !== null)) {
			// The row will not come; Retry is where the user goes next.
			area.current?.querySelector<HTMLElement>('button')?.focus()
			focusRow.current = null
			focusAt.current = null
			return
		}
		if (!sends) return
		if (focusRow.current !== null) {
			const index = sends.findIndex((row) => row.id === focusRow.current)
			if (index === -1) return
			focusAt.current = index
			focusRow.current = null
		}
		if (focusAt.current === null) return
		const rows = area.current?.querySelectorAll('li') ?? []
		const target = rows.length > 0 ? rows[Math.min(focusAt.current, rows.length - 1)] : area.current
		target?.querySelector<HTMLElement>('button')?.focus()
		focusAt.current = null
	}, [sends, removing, failed])

	async function confirmRemove(row: Row, index: number) {
		setRemoving({ row, busy: true, failed: false })
		const reply = await remove(row.id)
		if (reply?.ok) {
			focusAt.current = index
			setRemoving(null)
			toast(i18n.t('send.removed'))
		} else {
			setRemoving({ row, busy: false, failed: true, error: reply?.error })
		}
	}

	let content
	if (failed) {
		content = (
			<Banner tone="error" role="alert" action={<Button variant="link" onClick={retry}>{i18n.t('send.retry')}</Button>}>
				{i18n.t('send.loadFailed')}
			</Banner>
		)
	} else if (!sends) {
		content = <Loading />
	} else if (sends.length === 0) {
		content = (
			<EmptyState icon="send" title={i18n.t('send.empty')}>
				<p className="empty__text">{i18n.t('send.emptyText')}</p>
				<Button onClick={onNew}>{i18n.t('send.new')}</Button>
			</EmptyState>
		)
	} else {
		const names = rowNames(sends)
		content = (
			<ul className="items">
				{sends.map((row, i) => <SendRow key={row.id} row={row} name={names[i]!} link={linkFor(row)} onRemove={(row) => setRemoving({ row, busy: false, failed: false })} />)}
			</ul>
		)
	}

	return (
		<div ref={area}>
			{content}
			{removing && (
				<ConfirmDialog
					title={i18n.t('send.removeTitle')}
					actions={[{ label: i18n.t('send.remove'), variant: 'danger', busy: removing.busy, onClick: () => void confirmRemove(removing.row, Math.max(0, sends?.findIndex((row) => row.id === removing.row.id) ?? 0)) }]}
					onCancel={() => setRemoving(null)}
					error={removing.failed ? writeErrorText(removing.error) : null}
				>
					<p>{i18n.t('send.removeConfirm')}</p>
				</ConfirmDialog>
			)}
		</div>
	)
}
