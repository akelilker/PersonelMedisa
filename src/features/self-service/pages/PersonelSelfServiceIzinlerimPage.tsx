import { useEffect, useState } from "react";
import { isApiRequestError, shouldPreferDemoApi } from "../../../api/api-client";
import { fetchMeYillikIzinBakiye } from "../../../api/me.api";
import { LoadingState } from "../../../components/states/LoadingState";
import { SelfServiceFactList, type SelfServiceFact } from "../components/SelfServiceFactList";
import { buildSelfServiceYillikIzinView } from "../personel-self-service-yillik-izin-view";

type Status =
  | { kind: "loading" }
  | { kind: "ready"; rows: SelfServiceFact[]; note: string | null }
  | { kind: "empty"; message: string }
  | { kind: "error"; message: string };

export function PersonelSelfServiceIzinlerimPage() {
  const [status, setStatus] = useState<Status>({ kind: "loading" });

  useEffect(() => {
    if (shouldPreferDemoApi()) {
      setStatus({ kind: "empty", message: "Demo modda izin bakiyesi yok." });
      return;
    }
    let cancelled = false;
    void fetchMeYillikIzinBakiye()
      .then((bakiye) => {
        if (cancelled) return;
        const view = buildSelfServiceYillikIzinView(bakiye);
        if (!view) {
          setStatus({
            kind: "empty",
            message: "İzin bakiyesi için işe giriş bilgisi bulunamadı."
          });
          return;
        }
        setStatus({
          kind: "ready",
          note: view.pendingMessage,
          rows: [
            { label: "İşe giriş", value: view.iseGirisLabel, testId: "personel-izin-ise-giris" },
            { label: "Kıdem", value: view.kidemCalisiyorLabel, testId: "personel-izin-kidem" },
            { label: "Toplam hak", value: view.toplamLabel, testId: "personel-izin-toplam" },
            { label: "Kullanılan", value: view.kullanilanLabel, testId: "personel-izin-kullanilan" },
            { label: "Kalan", value: view.kalanLabel, testId: "personel-izin-kalan" }
          ]
        });
      })
      .catch((cause) => {
        if (cancelled) return;
        const unbound =
          isApiRequestError(cause) &&
          (cause.code === "SELF_SERVICE_BINDING_REQUIRED" || cause.code === "FORBIDDEN");
        setStatus({
          kind: "error",
          message: unbound
            ? "İzin bakiyesi bu hesap için kapalı."
            : "İzin bakiyesi yüklenemedi. Tekrar deneyin."
        });
      });
    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <section className="personel-mobile-shell pm-self-subpage" data-testid="personel-izinlerim-page">
      {status.kind === "loading" ? <LoadingState label="İzin bakiyesi yükleniyor..." /> : null}
      {status.kind === "ready" ? (
        <>
          {status.note ? (
            <p className="self-service-muted" data-testid="personel-izin-pending">
              {status.note}
            </p>
          ) : null}
          <SelfServiceFactList rows={status.rows} testId="personel-izin-facts" />
        </>
      ) : null}
      {status.kind === "empty" || status.kind === "error" ? (
        <p className="self-service-muted" data-testid="personel-izin-status">
          {status.message}
        </p>
      ) : null}
    </section>
  );
}
