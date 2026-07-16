import type { DonemKapanisPreflightSummary } from "../../../../api/donem-kapanis.api";

type DonemDurumBandiProps = {
  summary: DonemKapanisPreflightSummary | null;
};

function resolveStatusLabel(summary: DonemKapanisPreflightSummary): string {
  if (summary.muhur_state === "MUHURLENDI" || summary.donem_state === "MUHURLU") {
    return "Dönem mühürlü";
  }
  if (summary.kapanabilir_mi) {
    return "Kapanmaya hazır";
  }
  if (summary.blocker_count > 0) {
    return "Eksik işlemler var";
  }
  if (summary.warning_count > 0) {
    return "Uyarılar mevcut";
  }
  return "Ön kontrol tamamlandı";
}

function resolveStatusClass(summary: DonemKapanisPreflightSummary): string {
  if (summary.muhur_state === "MUHURLENDI" || summary.donem_state === "MUHURLU") {
    return "donem-durum-bandi donem-durum-bandi--sealed";
  }
  if (summary.kapanabilir_mi) {
    return "donem-durum-bandi donem-durum-bandi--ready";
  }
  if (summary.blocker_count > 0) {
    return "donem-durum-bandi donem-durum-bandi--blocked";
  }
  return "donem-durum-bandi donem-durum-bandi--warning";
}

export function DonemDurumBandi({ summary }: DonemDurumBandiProps) {
  if (!summary) {
    return null;
  }

  return (
    <div className={resolveStatusClass(summary)} data-testid="donem-kapanis-durum-bandi">
      <strong data-testid="donem-kapanis-durum-label">{resolveStatusLabel(summary)}</strong>
      <span className="donem-durum-bandi-meta">
        {summary.sube?.ad ?? "Şube"} · {summary.donem} · Mührü:{" "}
        {summary.muhur_state === "MUHURLENDI" ? `#${summary.muhur_id ?? "-"}` : "Açık"}
      </span>
      {summary.generated_at ? (
        <span className="donem-durum-bandi-meta">Güncelleme: {new Date(summary.generated_at).toLocaleString("tr-TR")}</span>
      ) : null}
    </div>
  );
}
