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
