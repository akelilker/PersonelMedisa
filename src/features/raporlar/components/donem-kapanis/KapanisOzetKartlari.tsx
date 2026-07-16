import type { DonemKapanisPreflightSummary } from "../../../../api/donem-kapanis.api";

type KapanisOzetKartlariProps = {
  summary: DonemKapanisPreflightSummary | null;
};

export function KapanisOzetKartlari({ summary }: KapanisOzetKartlariProps) {
  if (!summary) {
    return null;
  }

  const cards = [
    { label: "Engelleyici", value: summary.blocker_count, testId: "blocker" },
    { label: "Uyarı", value: summary.warning_count, testId: "warning" },
    { label: "Bilgi", value: summary.info_count, testId: "info" },
    {
      label: "HAZIR aday",
      value: summary.candidate_state_counts.HAZIR ?? 0,
      testId: "candidate-hazir"
    },
    {
      label: "İnceleme gerekli",
      value: summary.candidate_state_counts.INCELEME_GEREKLI ?? 0,
      testId: "candidate-inceleme"
    },
    {
      label: "Kontrol bekleyen puantaj",
      value: summary.puantaj_counts.kontrol_bekleyen ?? summary.puantaj_counts.control_pending ?? 0,
      testId: "puantaj-kontrol"
    }
  ];

  return (
    <div className="yonetim-summary-grid" data-testid="donem-kapanis-ozet-kartlari">
      {cards.map((card) => (
        <article key={card.testId} className="yonetim-summary-card" data-testid={`donem-kapanis-ozet-${card.testId}`}>
          <span>{card.label}</span>
          <strong>{card.value}</strong>
        </article>
      ))}
    </div>
  );
}
