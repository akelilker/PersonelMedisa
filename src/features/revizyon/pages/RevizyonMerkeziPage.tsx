import { useCallback, useEffect, useMemo, useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { ApiRequestError } from "../../../api/api-client";
import { fetchRevizyonCorrections } from "../../../api/revizyon-correction.api";
import { fetchRevizyonTalepleri } from "../../../api/revizyon-talebi.api";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import type { RevizyonCorrectionEvent } from "../../../types/revizyon-correction";
import type { RevizyonTalebi } from "../../../types/revizyon-talebi";
import {
  formatRevizyonDeger,
  formatRevizyonDurumLabel,
  formatRevizyonTipiLabel,
  revizyonUserMessage
} from "../revizyon-display";

type Gorunum = "talepler" | "onay" | "corrections";

function resolveGorunum(raw: string | null, canApprove: boolean): Gorunum {
  if (raw === "corrections") {
    return "corrections";
  }
  if (raw === "onay" && canApprove) {
    return "onay";
  }
  return "talepler";
}

export function RevizyonMerkeziPage() {
  const { hasPermission } = useRoleAccess();
  const canCreate = hasPermission("revizyon.create");
  const canApprove = hasPermission("revizyon.approve");
  const canViewFinance = hasPermission("revizyon.view_finance_effect");
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const gorunum = resolveGorunum(searchParams.get("gorunum"), canApprove);
  const personelIdFilter = searchParams.get("personel_id") ?? "";

  const [talepler, setTalepler] = useState<RevizyonTalebi[]>([]);
  const [corrections, setCorrections] = useState<RevizyonCorrectionEvent[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setErrorMessage(null);
    try {
      if (gorunum === "corrections") {
        const items = await fetchRevizyonCorrections({
          personel_id: personelIdFilter || undefined
        });
        setCorrections(items);
        setTalepler([]);
      } else {
        const items = await fetchRevizyonTalepleri({
          personel_id: personelIdFilter || undefined,
          durum: gorunum === "onay" ? "ONAY_BEKLIYOR" : undefined
        });
        setTalepler(items);
        setCorrections([]);
      }
    } catch (error) {
      const code = error instanceof ApiRequestError ? error.code : undefined;
      setErrorMessage(
        revizyonUserMessage(code, error instanceof Error ? error.message : "Liste yüklenemedi.")
      );
    } finally {
      setIsLoading(false);
    }
  }, [gorunum, personelIdFilter]);

  useEffect(() => {
    void load();
  }, [load]);

  const tabs = useMemo(
    () =>
      [
        { id: "talepler" as const, label: "Revizyon Talepleri", show: true },
        { id: "onay" as const, label: "Onay Bekleyenler", show: canApprove },
        { id: "corrections" as const, label: "Corrections", show: true }
      ].filter((tab) => tab.show),
    [canApprove]
  );

  function setGorunum(next: Gorunum) {
    const params = new URLSearchParams(searchParams);
    if (next === "talepler") {
      params.delete("gorunum");
    } else {
      params.set("gorunum", next);
    }
    setSearchParams(params);
  }

  return (
    <section className="states-page" data-testid="revizyon-merkezi-page">
      <div className="universal-btn-group" style={{ marginBottom: "1rem", flexWrap: "wrap" }}>
        <Link className="universal-btn-aux" to="/haftalik-kapanis">
          Haftalık Kapanış
        </Link>
        {canCreate ? (
          <Link
            className="universal-btn-save"
            to={
              personelIdFilter
                ? `/haftalik-kapanis/revizyonlar/yeni?personel_id=${encodeURIComponent(personelIdFilter)}`
                : "/haftalik-kapanis/revizyonlar/yeni"
            }
            data-testid="revizyon-yeni-talep"
          >
            Revizyon Talebi Aç
          </Link>
        ) : null}
      </div>

      <nav className="raporlar-panel-nav" aria-label="Revizyon görünümleri">
        {tabs.map((tab) => (
          <button
            key={tab.id}
            type="button"
            className={gorunum === tab.id ? "is-active" : undefined}
            data-testid={`revizyon-tab-${tab.id}`}
            onClick={() => setGorunum(tab.id)}
          >
            {tab.label}
          </button>
        ))}
      </nav>

      {personelIdFilter ? (
        <p className="form-hint">Personel filtresi: {personelIdFilter}</p>
      ) : null}

      {isLoading ? <LoadingState label="Revizyon kayıtları yükleniyor..." /> : null}
      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void load()} />
      ) : null}

      {!isLoading && !errorMessage && gorunum !== "corrections" && talepler.length === 0 ? (
        <EmptyState title="Boş liste" message="Gösterilecek revizyon talebi yok." />
      ) : null}

      {!isLoading && !errorMessage && gorunum === "corrections" && corrections.length === 0 ? (
        <EmptyState title="Boş liste" message="Gösterilecek correction kaydı yok." />
      ) : null}

      {!isLoading && !errorMessage && gorunum !== "corrections" && talepler.length > 0 ? (
        <div className="raporlar-table-wrap yonetim-table-wrap">
          <table className="raporlar-table" data-testid="revizyon-talep-tablosu">
            <thead>
              <tr>
                <th>Personel</th>
                <th>Sicil</th>
                <th>Şube</th>
                <th>Departman</th>
                <th>Hafta</th>
                <th>Tarih</th>
                <th>Tip</th>
                <th>Durum</th>
                {canViewFinance ? <th>Bordro</th> : null}
                <th>Correction</th>
                <th>Talep eden</th>
                <th>Zaman</th>
                <th>Detay</th>
              </tr>
            </thead>
            <tbody>
              {talepler.map((talep) => (
                <tr key={talep.id}>
                  <td>{talep.personel_ad_soyad ?? `Personel #${talep.personel_id}`}</td>
                  <td>{talep.sicil_no ?? "—"}</td>
                  <td>{talep.sube_adi ?? "—"}</td>
                  <td>{talep.departman_adi ?? "—"}</td>
                  <td>
                    {talep.hafta_baslangic} → {talep.hafta_bitis}
                  </td>
                  <td>{talep.etkilenen_tarih}</td>
                  <td>{formatRevizyonTipiLabel(talep.revizyon_tipi)}</td>
                  <td>
                    <span className="personeller-status-badge">{formatRevizyonDurumLabel(talep.durum)}</span>
                  </td>
                  {canViewFinance ? (
                    <td>{talep.bordro_etki_var_mi ? "Var" : "Yok"}</td>
                  ) : null}
                  <td>
                    {talep.aktif_correction_var_mi
                      ? "Aktif"
                      : talep.correction_durumu === "IPTAL"
                        ? "İptal"
                        : "—"}
                  </td>
                  <td>{talep.talep_eden_kullanici_adi ?? "—"}</td>
                  <td>{new Date(talep.talep_zamani).toLocaleString("tr-TR")}</td>
                  <td>
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => navigate(`/haftalik-kapanis/revizyonlar/${talep.id}`)}
                    >
                      Detay
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      {!isLoading && !errorMessage && gorunum === "corrections" && corrections.length > 0 ? (
        <div className="raporlar-table-wrap yonetim-table-wrap">
          <table className="raporlar-table" data-testid="revizyon-correction-tablosu">
            <thead>
              <tr>
                <th>ID</th>
                <th>Talep</th>
                <th>Personel</th>
                <th>Hafta</th>
                <th>Tarih</th>
                <th>Tip</th>
                <th>Önceki</th>
                <th>Yeni</th>
                <th>Δ dk</th>
                <th>Δ gün</th>
                {canViewFinance ? <th>Bordro</th> : null}
                <th>Durum</th>
                <th>Oluşturma</th>
                <th>Detay</th>
              </tr>
            </thead>
            <tbody>
              {corrections.map((correction) => (
                <tr key={correction.id}>
                  <td>{correction.id}</td>
                  <td>{correction.revizyon_talebi_id}</td>
                  <td>{correction.personel_ad_soyad ?? `Personel #${correction.personel_id}`}</td>
                  <td>
                    {correction.hafta_baslangic} → {correction.hafta_bitis}
                  </td>
                  <td>{correction.etkilenen_tarih}</td>
                  <td>{correction.correction_tipi}</td>
                  <td>{formatRevizyonDeger(correction.onceki_deger as never)}</td>
                  <td>{formatRevizyonDeger(correction.yeni_deger as never)}</td>
                  <td>{correction.delta_dakika}</td>
                  <td>{correction.delta_gun}</td>
                  {canViewFinance ? (
                    <td>{correction.bordro_etki_var_mi ? "Var" : "Yok"}</td>
                  ) : null}
                  <td>{correction.iptal_edildi_mi ? "İptal" : "Aktif"}</td>
                  <td>{new Date(correction.olusturma_zamani).toLocaleString("tr-TR")}</td>
                  <td>
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() =>
                        navigate(`/haftalik-kapanis/corrections/${correction.id}`)
                      }
                    >
                      Detay
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </section>
  );
}
