import { useId } from 'react'
import { i18n } from '#i18n'
import { NumberField } from './NumberField'

interface Props {
	label: string
	value: number
	min: number
	max: number
	onChange: (value: number) => void
	/** The range's steps, which come one per pixel of a drag; `onChange` when absent. */
	onSlide?: (value: number) => void
	/** Why `min` is raised, e.g. the organisation's floor; the range above it stays usable. */
	lockedBy?: string
}

export function LengthSlider({ label, value, min, max, onChange, onSlide = onChange, lockedBy }: Props) {
	const id = useId()
	const lockedId = lockedBy ? `${id}-locked` : undefined
	return (
		<div className="field">
			<label className="field__label" htmlFor={id}>{label}</label>
			<div className="slider">
				<input
					id={id}
					type="range"
					className="slider__range"
					min={min}
					max={max}
					step={1}
					value={value}
					aria-describedby={lockedId}
					onChange={(event) => onSlide(Number(event.target.value))}
				/>
				<NumberField label={i18n.t('generator.lengthValue')} hideLabel value={value} min={min} max={max} describedBy={lockedId} onChange={onChange} />
			</div>
			{lockedBy && <p className="field__hint" id={lockedId}>{lockedBy}</p>}
		</div>
	)
}
