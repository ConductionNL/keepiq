type Handler = (event: { data: unknown }) => void

/**
 * A `MessagePort` pair delivering on a microtask, as neither Node's ports nor happy-dom fit a test's timing. As a real
 * port, it queues what arrives before `onmessage` is set and delivers it once it is.
 */
export class FakePort {
	closed = false
	other!: FakePort
	/** Everything posted on this port, delivered or not. */
	readonly posted: unknown[] = []
	private handler: Handler | null = null
	private readonly queue: unknown[] = []

	get onmessage(): Handler | null {
		return this.handler
	}

	set onmessage(handler: Handler | null) {
		this.handler = handler
		if (handler) queueMicrotask(() => this.drain())
	}

	postMessage(data: unknown): void {
		if (this.closed) return
		this.posted.push(data)
		const copy = structuredClone(data)
		queueMicrotask(() => this.other.receive(copy))
	}

	close(): void {
		this.closed = true
	}

	private receive(data: unknown): void {
		if (this.closed) return
		this.queue.push(data)
		this.drain()
	}

	private drain(): void {
		while (this.handler && !this.closed && this.queue.length > 0) this.handler({ data: this.queue.shift() })
	}
}

export class FakeMessageChannel {
	readonly port1 = new FakePort()
	readonly port2 = new FakePort()

	constructor() {
		this.port1.other = this.port2
		this.port2.other = this.port1
	}
}

/** Lets queued deliveries and the promise work they start run; microtasks only, so it also works under fake timers. */
export async function flush(): Promise<void> {
	for (let i = 0; i < 50; i++) await Promise.resolve()
}
