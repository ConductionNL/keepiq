import { useEffect, useMemo, useState } from 'react'
import { i18n } from '#i18n'
import { generateCode, parseTotpSeed, secondsRemaining, type TotpParams } from '@/src/totp/totp'
import { useClipboard } from '../hooks/useClipboard'
import { IconButton } from './Button'

function parse(seed: string): TotpParams | null {
	try {
		return parseTotpSeed(seed)
	} catch {
		return null
	}
}

/** The code and its countdown; the timer stops and the code goes on unmount. */
export function TotpCode({ seed }: { seed: string }) {
	const params = useMemo(() => parse(seed), [seed])
	const [code, setCode] = useState<string | null>(null)
	const [left, setLeft] = useState(0)
	const copy = useClipboard()

	useEffect(() => {
		if (!params) return
		let live = true
		let counter = -1
		const tick = () => {
			const now = Date.now()
			setLeft(secondsRemaining(params.period, now))
			const current = Math.floor(now / 1000 / params.period)
			if (current === counter) return
			counter = current
			void generateCode(params, now).then((next) => live && setCode(next), () => live && setCode(null))
		}
		tick()
		const timer = setInterval(tick, 1000)
		return () => {
			live = false
			clearInterval(timer)
		}
	}, [params])

	if (!params) return <p className="field-row field-row__error">{i18n.t('totp.invalid')}</p>
	const half = params.digits / 2
	return (
		<div className="field-row">
			<div className="field-row__text">
				<span className="field-row__label">{i18n.t('totp.code')}</span>
				<span className="field-row__value totp">{code ? `${code.slice(0, half)} ${code.slice(half)}` : ' '}</span>
			</div>
			<span className="totp__countdown" style={{ '--progress': left / params.period } as React.CSSProperties} role="timer" aria-label={i18n.t('totp.changesIn', left)}>{left}</span>
			<IconButton icon="copy" label={i18n.t('item.copyCode')} disabled={!code} onClick={() => code && void copy(code, i18n.t('totp.code'))} />
		</div>
	)
}
