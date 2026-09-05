import type { AppPermission } from "../authorization/role-permissions";
import type { BugunPersonelDurumuPerson, GunlukBildirimDuzeltmeAudit } from "../../types/bildirim";

/** Canonical exception turleri — no GELDI write. */
export const SCOPED_CORRECTABLE_TURLER = [
  "GELMEDI",
  "GEC_GELDI",
  "IZINLI",
  "RAPORLU",
  "GOREVDE",
  "ERKEN_CIKTI",
  "DIGER"
] as const;

export type ScopedCorrectableTur = (typeof SCOPED_CORRECTABLE_TURLER)[number];

export const SCOPED_CORRECTABLE_TUR_LABEL: Record<ScopedCorrectableTur, string> = {
  GELMEDI: "Gelmedi",
  GEC_GELDI: "Geç Geldi",
  IZINLI: "İzinli",
  RAPORLU: "Raporlu",
  GOREVDE: "Görevde",
  ERKEN_CIKTI: "Erken Çıktı",
  DIGER: "Diğer"
};

export const PAYROLL_LOCK_EDIT_MESSAGE =
  "Bu dönem bordro kapanışı nedeniyle düzenlemeye kapalı.";

export function canCorrectScopedGunlukBildirim(
  hasPermission: (permission: AppPermission) => boolean
): boolean {
  return hasPermission("gunluk_bildirim.correct_scoped");
}

export function turNeedsTimeFields(tur: string): boolean {
  const key = tur.trim().toUpperCase();
  return key === "GEC_GELDI" || key === "ERKEN_CIKTI";
}

export function turNeedsAltTur(tur: string): boolean {
  return tur.trim().toUpperCase() === "IZINLI";
}

export function turRequiresAciklama(tur: string): boolean {
  return tur.trim().toUpperCase() === "DIGER";
}

export function evidenceLabel(evidence: string | null | undefined): string {
  const key = (evidence ?? "").toUpperCase();
  if (key === "EXCEPTION") return "Exception kaydı";
  if (key === "ATTENDANCE") return "Attendance kanıtı";
  if (key === "COMPLETION") return "Tamamlama → geldi";
  if (key === "UNASSESSED") return "Henüz değerlendirilmedi";
  return "—";
}

export function formatAuditClock(createdAt: string): string {
  const match = /(\d{2}:\d{2})/.exec(createdAt);
  if (match) {
    return match[1];
  }
  try {
    const d = new Date(createdAt);
    if (!Number.isNaN(d.getTime())) {
      return d.toLocaleTimeString("tr-TR", { hour: "2-digit", minute: "2-digit", hour12: false });
    }
  } catch {
    // ignore
  }
  return "—";
}

export function formatAuditTransition(row: GunlukBildirimDuzeltmeAudit): string {
  const from =
    SCOPED_CORRECTABLE_TUR_LABEL[row.eski_bildirim_turu as ScopedCorrectableTur] ??
    row.eski_bildirim_turu;
  const toRaw = row.yeni_bildirim_turu ?? "";
  const to =
    SCOPED_CORRECTABLE_TUR_LABEL[toRaw as ScopedCorrectableTur] ?? (toRaw || "—");
  return `${from} → ${to}`;
}

export function defaultTurFromPerson(person: BugunPersonelDurumuPerson): ScopedCorrectableTur {
  const durum = person.durum.toUpperCase();
  if ((SCOPED_CORRECTABLE_TURLER as readonly string[]).includes(durum)) {
    return durum as ScopedCorrectableTur;
  }
  return "GELMEDI";
}
