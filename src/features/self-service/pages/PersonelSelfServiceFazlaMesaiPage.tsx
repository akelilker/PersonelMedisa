import { useEffect, useState } from "react";
import { isApiRequestError, shouldPreferDemoApi } from "../../../api/api-client";
import { fetchMeFazlaCalisma } from "../../../api/me.api";
import { LoadingState } from "../../../components/states/LoadingState";
import type { MeFazlaCalismaResponse } from "../../../types/self-service";
import { SelfServiceFactList, type SelfServiceFact } from "../components/SelfServiceFactList";
import { formatSelfServiceMinutes } from "../format-self-service-minutes";

type Status =
  | { kind: "loading" }
  | { kind: "ready"; rows: SelfServiceFact[] }
  | { kind: "error"; message: string };

function limitState(data: MeFazlaCalismaResponse): string {
  if (data.yillik.limit_asildi_mi) {
    return "Yıllık limit aşıldı";
  }
  if (data.yillik.limit_yaklasiyor_mu) {
    return "Yıllık limite yaklaşıyor";
  }
  return "Limit içinde";
}

function buildRows(data: MeFazlaCalismaResponse): SelfServiceFact[] {
  const rows: SelfServiceFact[] = [
    { label: "Yıl", value: String(data.yil), testId: "personel-fazla-yil" },
    {
      label: "Kullanılan fazla çalışma",
      value: formatSelfServiceMinutes(data.yillik.kullanilan_dakika),
      testId: "personel-fazla-kullanilan"
    },
    {
      label: "Yıllık limit",
      value: formatSelfServiceMinutes(data.yillik.yillik_limit_dakika),
      testId: "personel-fazla-limit"
    },
    {
      label: "Kalan",
      value: formatSelfServiceMinutes(data.yillik.kalan_dakika),
      testId: "personel-fazla-kalan"
    },
    { label: "Durum", value: limitState(data), testId: "personel-fazla-durum" }
  ];
  if (data.donem_ozet) {
    rows.push(
      {
        label: "Dönem fazla çalışma",
        value: formatSelfServiceMinutes(data.donem_ozet.fazla_calisma_dakika_toplam),
        testId: "personel-fazla-donem"
      },
      {
        label: "Dönem çalışma günü",
        value: String(data.donem_ozet.calisma_gun_adet),
        testId: "personel-fazla-donem-gun"
      }
    );
  }
  return rows;
}

export function PersonelSelfServiceFazlaMesaiPage() {
  const [status, setStatus] = useState<Status>({ kind: "loading" });

  useEffect(() => {
    if (shouldPreferDemoApi()) {
      setStatus({ kind: "error", message: "Demo modda fazla mesai özeti yok." });
      return;
    }
    let cancelled = false;
    void fetchMeFazlaCalisma()
      .then((data) => {
        if (!cancelled) {
          setStatus({ kind: "ready", rows: buildRows(data) });
        }
      })
      .catch((cause) => {
        if (cancelled) return;
        const closed =
          isApiRequestError(cause) &&
          (cause.code === "SELF_SERVICE_BINDING_REQUIRED" || cause.code === "FORBIDDEN");
        setStatus({
          kind: "error",
          message: closed
            ? "Fazla mesai özeti bu hesap için kapalı."
            : "Fazla mesai özeti yüklenemedi. Tekrar deneyin."
        });
      });
    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <section className="personel-mobile-shell pm-self-subpage" data-testid="personel-fazla-mesai-page">
      {status.kind === "loading" ? <LoadingState label="Fazla mesai özeti yükleniyor..." /> : null}
      {status.kind === "ready" ? (
        <SelfServiceFactList rows={status.rows} testId="personel-fazla-facts" />
      ) : null}
      {status.kind === "error" ? (
        <p className="self-service-muted" data-testid="personel-fazla-status">
          {status.message}
        </p>
      ) : null}
    </section>
  );
}
