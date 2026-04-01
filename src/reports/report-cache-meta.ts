/** Offline rapor uretecinin tazelik durumu — data-manager / realtime ile paylasilir. */

let reportCacheIsStale = false;
let lastGeneratedAt: string | null = null;
let lastSourceRevision = 0;

export function markReportCacheStale(): void {
  reportCacheIsStale = true;
}

export function recordOfflineReportGeneration(cacheRevision: number): void {
  reportCacheIsStale = false;
  lastGeneratedAt = new Date().toISOString();
  lastSourceRevision = cacheRevision;
}

export function getReportCacheMeta(): {
  isStale: boolean;
  lastGeneratedAt: string | null;
  lastSourceRevision: number;
} {
  return {
    isStale: reportCacheIsStale,
    lastGeneratedAt,
    lastSourceRevision: lastSourceRevision
  };
}
