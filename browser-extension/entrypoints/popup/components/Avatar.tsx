export function initials(name: string): string {
	const words = name.trim().split(/\s+/).filter(Boolean)
	const first = words[0] ?? '?'
	const last = words.at(-1) ?? ''
	return (words.length > 1 ? first.charAt(0) + last.charAt(0) : first.slice(0, 2)).toUpperCase()
}

export function Avatar({ name, dataUrl, size = 32 }: { name: string; dataUrl: string | null; size?: number }) {
	const style = { width: size, height: size }
	if (dataUrl) return <img className="avatar" src={dataUrl} alt="" style={style} />
	return <span className="avatar avatar--initials" style={{ ...style, fontSize: size * 0.4 }} aria-hidden="true">{initials(name)}</span>
}
