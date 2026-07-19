import { useCallback, useEffect, useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { ApiRequestError } from "../../../api/api-client";
import { fetchPersonellerList } from "../../../api/personeller.api";
import { fetchRevizyonKaynaklar } from "../../../api/revizyon-talebi.api";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { ROUTE_PERMISSION } from "../../../lib/authorization/role-permissions";
import type { Personel } from "../../../types/personel";
import {
  buildRevizyonTalebiCreatePath,
  formatRevizyonDeger,
  revizyonUserMessage
} from "../revizyon-display";

type KaynakOption = Awaited<ReturnType<typeof fetchRevizyonKaynaklar>>[number];

function mondayOf(dateStr: string): string | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
    return null;
  }
  const date = new Date(`${dateStr}T12:00:00`);
  const day = date.getDay();
  const diff = day === 0 ? -6 : 1 - day;
  date.setDate(date.getDate() + diff);
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const d = String(date.getDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}

function addDays(dateStr: string, days: number): string {
  const date = new Date(`${dateStr}T12:00:00`);
  date.setDate(date.getDate() + days);
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const d = String(date.getDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}

export function HaftalikKapanisPage() {
  const { hasPermission } = useRoleAccess();
  const canViewRevizyon = hasPermission(ROUTE_PERMISSION.haftalikKapanisPage);
  const canCreate = hasPermission("revizyon.create");
  const canApprove = hasPermission("revizyon.approve");
  const [searchParams] = useSearchParams();

  const [personeller, setPersoneller] = useState<Personel[]>([]);
  const [personelId, setPersonelId] = useState(searchParams.get("personel_id") ?? "");
  const [haftaBaslangic, setHaftaBaslangic] = useState(
    searchParams.get("hafta_baslangic") ?? "2024-01-01"
  );
  const [kaynaklar, setKaynaklar] = useState<KaynakOption[]>([]);
  const [isLoadingMeta, setIsLoadingMeta] = useState(true);
  const [isLoadingKaynak, setIsLoadingKaynak] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const haftaBitis = useMemo(
    () => (haftaBaslangic ? addDays(haftaBaslangic, 6) : ""),
    [haftaBaslangic]
  );

  useEffect(() => {
    if (!canViewRevizyon) {
      setIsLoadingMeta(false);
      return;
    }
    void (async () => {
      try {
        const list = await fetchPersonellerList();
        setPersoneller(list.items);
        setPersonelId((current) => current || (list.items[0] ? String(list.items[0].id) : ""));
      } catch (error) {
        setErrorMessage(error instanceof Error ? error.message : "Personel listesi yüklenemedi.");
      } finally {
        setIsLoadingMeta(false);
      }
    })();
  }, [canViewRevizyon]);

  const loadKaynaklar = useCallback(async () => {
    if (!canCreate || !personelId || !haftaBaslangic || !haftaBitis) {
      setKaynaklar([]);
      return;
    }
    setIsLoadingKaynak(true);
    setErrorMessage(null);
    try {
      const items = await fetchRevizyonKaynaklar({
        personel_id: personelId,
        hafta_baslangic: haftaBaslangic,
        hafta_bitis: haftaBitis
      });
      setKaynaklar(items);
    } catch (error) {
      const code = error instanceof ApiRequestError ? error.code : undefined;
      setErrorMessage(
        revizyonUserMessage(code, error instanceof Error ? error.message : "Kaynaklar yüklenemedi.")
      );
      setKaynaklar([]);
    } finally {
      setIsLoadingKaynak(false);
    }
  }, [canCreate, personelId, haftaBaslangic, haftaBitis]);

  useEffect(() => {
    void loadKaynaklar();
  }, [loadKaynaklar]);

  return (
    <section className="states-page" data-testid="haftalik-kapanis-page">
      <h2>Haftalık Kapanış</h2>
      <p>
        Kapalı hafta snapshot’ları korunur. Revizyon talepleri ve correction kayıtları burada yönetilir;
        rapor/bordro motoru otomatik yeniden hesaplanmaz.
      </p>

      {!canViewRevizyon ? (
        <p role="alert">Revizyon Merkezi görüntüleme yetkiniz yok.</p>
      ) : (
        <>
          <div className="universal-btn-group" style={{ flexWrap: "wrap", gap: "0.75rem" }}>
            <Link
              className="universal-btn-save"
              to={
                personelId
                  ? `/haftalik-kapanis/revizyonlar?personel_id=${encodeURIComponent(personelId)}`
                  : "/haftalik-kapanis/revizyonlar"
              }
              data-testid="hk-revizyon-merkezi-link"
            >
              Revizyon Merkezi
            </Link>
            {canApprove ? (
              <Link
                className="universal-btn-aux"
                to="/haftalik-kapanis/revizyonlar?gorunum=onay"
                data-testid="hk-onay-bekleyenler-link"
              >
                Onay Bekleyenler
              </Link>
            ) : null}
            <Link
              className="universal-btn-aux"
              to="/haftalik-kapanis/revizyonlar?gorunum=corrections"
              data-testid="hk-corrections-link"
            >
              Corrections
            </Link>
            {canCreate ? (
              <Link
                className="universal-btn-aux"
                to={buildRevizyonTalebiCreatePath({
                  personel_id: personelId || undefined,
                  hafta_baslangic: haftaBaslangic || undefined,
                  hafta_bitis: haftaBitis || undefined
                })}
                data-testid="hk-revizyon-talebi-ac"
              >
                Revizyon Talebi Aç
              </Link>
            ) : null}
          </div>

          {canCreate ? (
            <div style={{ marginTop: "1.5rem" }} data-testid="hk-kaynak-prefill-panel">
              <h3>Kapalı hafta kaynak satırları</h3>
              <p className="form-hint">
                Satırdaki “Revizyon Talebi Aç” create formunu personel, hafta ve kaynak ile doldurur.
                Eski değer sunucudan gelir.
              </p>

              {isLoadingMeta ? <LoadingState label="Personeller yükleniyor..." /> : null}

              <label className="form-label" htmlFor="hk-prefill-personel">
                Personel
              </label>
              <select
                id="hk-prefill-personel"
                data-testid="hk-prefill-personel"
                value={personelId}
                onChange={(event) => setPersonelId(event.target.value)}
              >
                <option value="">Seçiniz</option>
                {personeller.map((personel) => (
                  <option key={personel.id} value={personel.id}>
                    {personel.ad} {personel.soyad} ({personel.sicil_no})
                  </option>
                ))}
              </select>

              <label className="form-label" htmlFor="hk-prefill-hafta">
                Kapalı hafta başlangıcı (Pazartesi)
              </label>
              <input
                id="hk-prefill-hafta"
                data-testid="hk-prefill-hafta"
                className="form-input"
                type="date"
                value={haftaBaslangic}
                onChange={(event) => {
                  const monday = mondayOf(event.target.value) ?? event.target.value;
                  setHaftaBaslangic(monday);
                }}
              />
              <p className="form-hint">Hafta bitişi: {haftaBitis || "—"}</p>

              {isLoadingKaynak ? <LoadingState label="Kaynak satırları yükleniyor..." /> : null}
              {errorMessage ? <ErrorState message={errorMessage} onRetry={() => void loadKaynaklar()} /> : null}

              {!isLoadingKaynak && !errorMessage && kaynaklar.length === 0 ? (
                <p className="form-hint" data-testid="hk-kaynak-empty">
                  Bu personel/hafta için revizyona uygun kapalı kaynak satırı yok.
                </p>
              ) : null}

              {kaynaklar.length > 0 ? (
                <table className="raporlar-table" data-testid="hk-kaynak-tablosu">
                  <thead>
                    <tr>
                      <th>Tarih</th>
                      <th>Kaynak</th>
                      <th>Mevcut değer</th>
                      <th>Aksiyon</th>
                    </tr>
                  </thead>
                  <tbody>
                    {kaynaklar.map((kaynak) => (
                      <tr
                        key={`${kaynak.kaynak_tipi}:${kaynak.kaynak_id}`}
                        data-testid={`hk-kaynak-row-${kaynak.kaynak_tipi}-${kaynak.kaynak_id}`}
                      >
                        <td>{kaynak.etkilenen_tarih}</td>
                        <td>{kaynak.goruntuleme_etiketi}</td>
                        <td>{formatRevizyonDeger(kaynak.mevcut_deger)}</td>
                        <td>
                          <Link
                            className="universal-btn-save"
                            data-testid={`hk-satir-revizyon-ac-${kaynak.kaynak_id}`}
                            to={buildRevizyonTalebiCreatePath({
                              personel_id: personelId,
                              hafta_baslangic: haftaBaslangic,
                              hafta_bitis: haftaBitis,
                              etkilenen_tarih: kaynak.etkilenen_tarih,
                              kaynak_tipi: kaynak.kaynak_tipi,
                              kaynak_id: kaynak.kaynak_id
                            })}
                          >
                            Revizyon Talebi Aç
                          </Link>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ) : null}
            </div>
          ) : null}
        </>
      )}

      <p className="form-hint" style={{ marginTop: "1.25rem" }} data-testid="hk-overlay-uyari">
        Aktif correction görünürlüğü, rapor satırlarında gerçek overlay anlamına gelmez. Ham kapanış
        snapshot’ı değişmez.
      </p>
    </section>
  );
}
