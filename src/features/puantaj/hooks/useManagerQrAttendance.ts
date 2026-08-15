import { useCallback, useEffect, useMemo, useState } from "react";
import { fetchManagerQrAttendance } from "../../../api/qr.api";
import type { ManagerQrAttendanceItem } from "../../../types/self-service";
import { istanbulToday } from "../qr-read-utils";

type UseManagerQrAttendanceOptions = {
  initialFrom?: string;
  initialTo?: string;
  initialPersonelId?: string;
  autoLoad?: boolean;
};

export function useManagerQrAttendance({
  initialFrom = istanbulToday(),
  initialTo = istanbulToday(),
  initialPersonelId = "",
  autoLoad = true
}: UseManagerQrAttendanceOptions = {}) {
  const [from, setFrom] = useState(initialFrom);
  const [to, setTo] = useState(initialTo);
  const [personelId, setPersonelId] = useState(initialPersonelId);
  const [subeId, setSubeId] = useState("");
  const [anomaly, setAnomaly] = useState("");
  const [items, setItems] = useState<ManagerQrAttendanceItem[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [hasLoaded, setHasLoaded] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await fetchManagerQrAttendance({
        from,
        to,
        personel_id: personelId ? Number(personelId) : undefined,
        sube_id: subeId ? Number(subeId) : undefined,
        limit: 100
      });
      setItems(result.items);
      setTotal(result.total);
      setHasLoaded(true);
    } catch (cause) {
      setItems([]);
      setTotal(0);
      setHasLoaded(false);
      setError(cause);
    } finally {
      setLoading(false);
    }
  }, [from, to, personelId, subeId]);

  useEffect(() => {
    if (autoLoad) void load();
  }, [autoLoad, load]);

  const filteredItems = useMemo(() => {
    if (!anomaly) return items;
    return items.filter((item) => {
      if (anomaly === "INSIDE") return item.inside;
      if (anomaly === "MISSING") return item.missing_entry || item.missing_exit;
      if (anomaly === "BRANCH_MISMATCH") return item.branch_mismatch;
      return item.anomalies.includes(anomaly);
    });
  }, [items, anomaly]);

  return {
    from,
    to,
    personelId,
    subeId,
    anomaly,
    setFrom,
    setTo,
    setPersonelId,
    setSubeId,
    setAnomaly,
    items,
    filteredItems,
    total,
    loading,
    error,
    hasLoaded,
    load
  };
}
