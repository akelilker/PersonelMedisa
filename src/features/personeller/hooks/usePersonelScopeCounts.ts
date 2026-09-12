import { useEffect, useMemo, useState } from "react";
import { fetchPersonellerList } from "../../../api/personeller.api";
import type { SubeInfo } from "../../../types/auth";

export type PersonelScopeCard = {
  id: number | "all";
  name: string;
  count: number | null;
  loading: boolean;
};

/**
 * Per-branch + ALL counts via GET /personeller?limit=1 meta.total.
 * Explicit `sube_id` for each branch; ALL omits sube_id and suppresses session
 * X-Active-Sube-Id so a leftover active branch cannot force every count equal.
 */
export function usePersonelScopeCounts(branches: SubeInfo[], enabled: boolean) {
  const key = useMemo(() => branches.map((b) => b.id).join(","), [branches]);
  const [counts, setCounts] = useState<Record<string, number | null>>({});
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!enabled) {
      return;
    }
    let cancelled = false;
    const ac = new AbortController();
    setLoading(true);
    void (async () => {
      const next: Record<string, number | null> = {};
      // ALL = omit sube_id + clear active header (authorized unique active set)
      try {
        const all = await fetchPersonellerList({
          aktiflik: "aktif",
          page: 1,
          limit: 1,
          prefer_query_sube: true,
          active_sube_header: null,
          signal: ac.signal
        });
        next.all = all.pagination?.total ?? all.items.length;
      } catch {
        next.all = null;
      }
      await Promise.all(
        branches.map(async (branch) => {
          try {
            const res = await fetchPersonellerList({
              aktiflik: "aktif",
              page: 1,
              limit: 1,
              sube_id: branch.id,
              prefer_query_sube: true,
              active_sube_header: branch.id,
              signal: ac.signal
            });
            next[String(branch.id)] = res.pagination?.total ?? res.items.length;
          } catch {
            next[String(branch.id)] = null;
          }
        })
      );
      if (!cancelled) {
        setCounts(next);
        setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
      ac.abort();
    };
  }, [enabled, key]);

  return { counts, loading };
}
