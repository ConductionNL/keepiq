import { useEffect, useId, useRef, useState, type CSSProperties, type KeyboardEvent } from 'react'

export interface SubTab<T extends string> {
	id: T
	label: string
	disabled?: boolean
}

interface Props<T extends string> {
	label: string
	tabs: SubTab<T>[]
	value: T
	onChange: (id: T) => void
	/** The `tabpanel` the caller renders, labelled by `subTabId(panelId, value)`. */
	panelId: string
	/** Why a tab is disabled, shown under the tabs. */
	hint?: string
}

export const subTabId = (panelId: string, id: string) => `${panelId}-tab-${id}`

/**
 * A WAI-ARIA tablist: arrow keys, Home and End move focus over every tab and select the enabled
 * ones; a disabled tab stays focusable, so its reason is heard.
 */
export function SubTabs<T extends string>({ label, tabs, value, onChange, panelId, hint }: Props<T>) {
	const hintId = useId()
	const list = useRef<HTMLDivElement>(null)
	// The pill only slides once mounted, so opening on a stored tab doesn't animate from the first.
	const [sliding, setSliding] = useState(false)
	useEffect(() => {
		const frame = requestAnimationFrame(() => setSliding(true))
		return () => cancelAnimationFrame(frame)
	}, [])
	const position = { '--index': Math.max(0, tabs.findIndex((tab) => tab.id === value)), '--count': tabs.length } as CSSProperties

	function onKeyDown(event: KeyboardEvent) {
		const focused = tabs.findIndex((tab) => subTabId(panelId, tab.id) === (event.target as HTMLElement).id)
		const at = focused < 0 ? tabs.findIndex((tab) => tab.id === value) : focused
		const next = {
			ArrowRight: tabs[(at + 1) % tabs.length],
			ArrowLeft: tabs[(at - 1 + tabs.length) % tabs.length],
			Home: tabs[0],
			End: tabs[tabs.length - 1],
		}[event.key]
		if (!next) return
		event.preventDefault()
		if (!next.disabled && next.id !== value) onChange(next.id)
		list.current?.querySelector<HTMLElement>(`#${CSS.escape(subTabId(panelId, next.id))}`)?.focus()
	}

	return (
		<div className="sub-tabs">
			<div className={sliding ? 'segmented segmented--sliding' : 'segmented'} role="tablist" aria-label={label} ref={list} style={position} onKeyDown={onKeyDown}>
				<span className="segmented__pill" aria-hidden="true" />
				{tabs.map((tab) => (
					<button
						key={tab.id}
						type="button"
						role="tab"
						id={subTabId(panelId, tab.id)}
						className="segmented__tab"
						aria-selected={tab.id === value}
						aria-controls={panelId}
						aria-disabled={tab.disabled || undefined}
						aria-describedby={tab.disabled && hint ? hintId : undefined}
						tabIndex={tab.id === value ? 0 : -1}
						onClick={() => !tab.disabled && tab.id !== value && onChange(tab.id)}
					>
						{tab.label}
					</button>
				))}
			</div>
			{hint && <p className="field__hint" id={hintId}>{hint}</p>}
		</div>
	)
}
