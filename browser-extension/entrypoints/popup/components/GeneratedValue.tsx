import { useEffect, useRef } from 'react'
import { i18n } from '#i18n'
import { useClipboard } from '../hooks/useClipboard'
import { fieldAction } from '../i18n'
import { IconButton } from './Button'

function charClass(char: string): string | undefined {
	if (/\d/.test(char)) return 'gen-digit'
	return /\p{L}/u.test(char) ? undefined : 'gen-symbol'
}

/** Digits and symbols in their own colours, so `l`, `1` and `|` stay apart. */
export function ColouredValue({ value, className }: { value: string; className?: string }) {
	return (
		<span className={`gen-value ${className ?? ''}`}>
			{[...value].map((char, i) => <span key={i} className={charClass(char)}>{char}</span>)}
		</span>
	)
}

interface Props {
	value: string | null
	/** Shown instead of a value, e.g. "Enter an email address". */
	failure?: string | null
	/** What the value is, for the Copy label and toast. */
	label: string
	onRegenerate: () => void
	onCopied?: (value: string) => void
	/** Takes focus once when shown, for a page whose opener disappeared, such as pick mode. */
	focusOnMount?: boolean
}

/** Not a live region: it changes on every option step. The view announces Regenerate and sub-tab changes itself. */
export function GeneratedValue({ value, failure, label, onRegenerate, onCopied, focusOnMount = false }: Props) {
	const copy = useClipboard()
	const box = useRef<HTMLParagraphElement>(null)

	useEffect(() => {
		if (focusOnMount) box.current?.focus()
		// Once, on mount.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [])

	return (
		<div className="generated__box">
			<p className="generated__value" ref={box} tabIndex={focusOnMount ? -1 : undefined}>
				{value ? <ColouredValue value={value} /> : <span className="generated__failure">{failure ?? ' '}</span>}
			</p>
			<IconButton icon="refresh" label={i18n.t('generator.regenerate')} onClick={onRegenerate} />
			<IconButton
				icon="copy"
				label={fieldAction('field.copy', label)}
				disabled={!value}
				onClick={async () => {
					if (!value) return
					await copy(value, label)
					onCopied?.(value)
				}}
			/>
		</div>
	)
}
