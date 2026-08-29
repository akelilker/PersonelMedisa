const inflight = new Map<string, Promise<unknown>>();

export function runDeduped<T>(key: string, fn: () => Promise<T>): Promise<T> {
  const existing = inflight.get(key);
  if (existing) {
    return existing as Promise<T>;
  }

  const promise = fn().finally(() => {
    inflight.delete(key);
  }) as Promise<T>;

  inflight.set(key, promise);
  return promise;
}

type AbortableEntry = {
  promise: Promise<unknown>;
  controller: AbortController;
  waiters: number;
};

const abortable = new Map<string, AbortableEntry>();

export type DedupedRequestLease<T> = {
  promise: Promise<T>;
  /** Drops this waiter; the request is aborted once the last waiter is gone. */
  release: () => void;
};

/**
 * Deduped request that can be cancelled once nobody is waiting for it anymore.
 *
 * Concurrent callers for the same key share one HTTP request and one
 * AbortController. The abort is decided in a microtask instead of synchronously,
 * so React StrictMode's mount/unmount/mount of the same effect re-acquires the
 * live request rather than cancelling it and issuing a duplicate GET.
 */
export function acquireDedupedRequest<T>(
  key: string,
  fn: (signal: AbortSignal) => Promise<T>
): DedupedRequestLease<T> {
  let entry = abortable.get(key);

  if (!entry) {
    const controller = new AbortController();
    const created: AbortableEntry = {
      promise: Promise.resolve() as Promise<unknown>,
      controller,
      waiters: 0
    };
    created.promise = fn(controller.signal).finally(() => {
      if (abortable.get(key) === created) {
        abortable.delete(key);
      }
    });
    // The awaiting caller handles rejections; this keeps an abort from surfacing
    // as an unhandled rejection when the last waiter already left.
    created.promise.catch(() => undefined);
    abortable.set(key, created);
    entry = created;
  }

  const current = entry;
  current.waiters += 1;
  let released = false;

  return {
    promise: current.promise as Promise<T>,
    release: () => {
      if (released) {
        return;
      }
      released = true;
      current.waiters -= 1;
      if (current.waiters > 0) {
        return;
      }
      queueMicrotask(() => {
        if (current.waiters > 0 || abortable.get(key) !== current) {
          return;
        }
        abortable.delete(key);
        current.controller.abort();
      });
    }
  };
}
