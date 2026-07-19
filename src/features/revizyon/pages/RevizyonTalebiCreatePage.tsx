import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { ApiRequestError } from "../../../api/api-client";
import { fetchPersonellerList } from "../../../api/personeller.api";
import {
  createRevizyonTalebi,
  fetchRevizyonKaynaklar,
  submitRevizyonTalebi
} from "../../../api/revizyon-talebi.api";
import { FormField } from "../../../components/form/FormField";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import type { Personel } from "../../../types/personel";
import type { RevizyonTipi } from "../../../types/revizyon-talebi";
import { REVIZYON_TIPLERI } from "../../../types/revizyon-talebi";
import { formatRevizyonDeger, formatRevizyonTipiLabel, revizyonUserMessage } from "../revizyon-display";

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

export function RevizyonTalebiCreatePage() {
  const { hasPermission } = useRoleAccess();
  const canCreate = hasPermission("revizyon.create");
  const canViewFinance = hasPermission("revizyon.view_finance_effect");
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();

  const [personeller, setPersoneller] = useState<Personel[]>([]);
  const [personelId, setPersonelId] = useState(searchParams.get("personel_id") ?? "");
  const [haftaBaslangic, setHaftaBaslangic] = useState(searchParams.get("hafta_baslangic") ?? "");
  const [kaynaklar, setKaynaklar] = useState<KaynakOption[]>([]);
  const [kaynakKey, setKaynakKey] = useState("");
  const [revizyonTipi, setRevizyonTipi] = useState<RevizyonTipi | "">("");
  const [yeniDegerText, setYeniDegerText] = useState("");
  const [gerekce, setGerekce] = useState("");
  const [bordroEtki, setBordroEtki] = useState(false);
  const [bordroNotu, setBordroNotu] = useState("");
  const [isLoadingMeta, setIsLoadingMeta] = useState(true);
  const [isLoadingKaynak, setIsLoadingKaynak] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [metaError, setMetaError] = useState<string | null>(null);

  const haftaBitis = useMemo(
    () => (haftaBaslangic ? addDays(haftaBaslangic, 6) : ""),
    [haftaBaslangic]
  );

  const selectedKaynak = useMemo(
    () => kaynaklar.find((item) => `${item.kaynak_tipi}:${item.kaynak_id}` === kaynakKey) ?? null,
    [kaynaklar, kaynakKey]
  );

  const uygunTipler = selectedKaynak?.uygun_revizyon_tipleri ?? [...REVIZYON_TIPLERI];

  useEffect(() => {
    if (!canCreate) {
      setIsLoadingMeta(false);
      return;
    }
    void (async () => {
      try {
        const list = await fetchPersonellerList();
        setPersoneller(list.items);
      } catch (error) {
        setMetaError(error instanceof Error ? error.message : "Personel listesi yüklenemedi.");
      } finally {
        setIsLoadingMeta(false);
      }
    })();
  }, [canCreate]);

  const loadKaynaklar = useCallback(async () => {
    if (!personelId || !haftaBaslangic || !haftaBitis) {
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
      const prefTip = searchParams.get("kaynak_tipi");
      const prefId = searchParams.get("kaynak_id");
      if (prefTip && prefId) {
        const match = items.find(
          (item) => item.kaynak_tipi === prefTip && String(item.kaynak_id) === prefId
        );
        if (match) {
          setKaynakKey(`${match.kaynak_tipi}:${match.kaynak_id}`);
        }
      }
    } catch (error) {
      const code = error instanceof ApiRequestError ? error.code : undefined;
      setErrorMessage(
        revizyonUserMessage(code, error instanceof Error ? error.message : "Kaynaklar yüklenemedi.")
      );
      setKaynaklar([]);
    } finally {
      setIsLoadingKaynak(false);
    }
  }, [personelId, haftaBaslangic, haftaBitis, searchParams]);

  useEffect(() => {
    void loadKaynaklar();
  }, [loadKaynaklar]);

  useEffect(() => {
    if (selectedKaynak && !uygunTipler.includes(revizyonTipi)) {
      setRevizyonTipi((uygunTipler[0] as RevizyonTipi) ?? "");
    }
  }, [selectedKaynak, uygunTipler, revizyonTipi]);

  if (!canCreate) {
    return <ErrorState message="Revizyon talebi oluşturma yetkiniz yok." />;
  }

  if (isLoadingMeta) {
    return <LoadingState label="Form hazırlanıyor..." />;
  }

  if (metaError) {
    return <ErrorState message={metaError} />;
  }

  async function handleSubmit(event: FormEvent, andSubmit: boolean) {
    event.preventDefault();
    if (isSaving || !selectedKaynak || !revizyonTipi || !gerekce.trim()) {
      setErrorMessage("Zorunlu alanları doldurun.");
      return;
    }

    let talepEdilen: unknown = yeniDegerText.trim();
    try {
      if (yeniDegerText.trim().startsWith("{") || yeniDegerText.trim().startsWith("[")) {
        talepEdilen = JSON.parse(yeniDegerText);
      }
    } catch {
      setErrorMessage("Talep edilen değer geçerli JSON değil.");
      return;
    }

    setIsSaving(true);
    setErrorMessage(null);
    try {
      const created = await createRevizyonTalebi({
        personel_id: personelId,
        hafta_baslangic: haftaBaslangic,
        hafta_bitis: haftaBitis,
        etkilenen_tarih: selectedKaynak.etkilenen_tarih,
        kaynak_tipi: selectedKaynak.kaynak_tipi,
        kaynak_id: selectedKaynak.kaynak_id,
        revizyon_tipi: revizyonTipi,
        talep_edilen_deger: talepEdilen as never,
        gerekce: gerekce.trim(),
        bordro_etki_var_mi: canViewFinance ? bordroEtki : false,
        bordro_etki_notu: canViewFinance ? bordroNotu || null : null
      });
      if (andSubmit) {
        await submitRevizyonTalebi(created.id);
      }
      navigate(`/haftalik-kapanis/revizyonlar/${created.id}`);
    } catch (error) {
      const code = error instanceof ApiRequestError ? error.code : undefined;
      setErrorMessage(
        revizyonUserMessage(
          code,
          error instanceof Error ? error.message : "Talep oluşturulamadı."
        )
      );
    } finally {
      setIsSaving(false);
    }
  }

  return (
    <section className="states-page" data-testid="revizyon-talep-create">
      <div className="universal-btn-group" style={{ marginBottom: "1rem" }}>
        <Link className="universal-btn-aux" to="/haftalik-kapanis/revizyonlar">
          Listeye dön
        </Link>
      </div>
      <h2>Revizyon Talebi Aç</h2>

      <form className="workspace-form" onSubmit={(event) => void handleSubmit(event, false)}>
        <label className="form-label" htmlFor="revizyon-personel">
          Personel
        </label>
        <select
          id="revizyon-personel"
          name="personel_id"
          value={personelId}
          onChange={(event) => setPersonelId(event.target.value)}
          required
        >
          <option value="">Seçiniz</option>
          {personeller.map((personel) => (
            <option key={personel.id} value={personel.id}>
              {personel.ad} {personel.soyad} ({personel.sicil_no})
            </option>
          ))}
        </select>

        <FormField
          label="Kapalı hafta başlangıcı (Pazartesi)"
          name="hafta_baslangic"
          type="date"
          value={haftaBaslangic}
          onChange={(value) => {
            const monday = mondayOf(value) ?? value;
            setHaftaBaslangic(monday);
          }}
          required
        />
        <p className="form-hint">Hafta bitişi: {haftaBitis || "—"}</p>

        {isLoadingKaynak ? <LoadingState label="Kaynaklar yükleniyor..." /> : null}

        <label className="form-label" htmlFor="revizyon-kaynak">
          Kaynak kayıt
        </label>
        <select
          id="revizyon-kaynak"
          name="kaynak"
          value={kaynakKey}
          onChange={(event) => setKaynakKey(event.target.value)}
          required
          disabled={isLoadingKaynak || kaynaklar.length === 0}
        >
          <option value="">Seçiniz</option>
          {kaynaklar.map((kaynak) => (
            <option key={`${kaynak.kaynak_tipi}:${kaynak.kaynak_id}`} value={`${kaynak.kaynak_tipi}:${kaynak.kaynak_id}`}>
              {kaynak.goruntuleme_etiketi}
            </option>
          ))}
        </select>

        <FormField
          label="Eski değer (sunucu)"
          name="onceki_deger"
          value={selectedKaynak ? formatRevizyonDeger(selectedKaynak.mevcut_deger) : ""}
          onChange={() => undefined}
          disabled
        />
        <p className="form-hint" data-testid="revizyon-onceki-deger-readonly">
          {selectedKaynak
            ? formatRevizyonDeger(selectedKaynak.mevcut_deger)
            : "Kaynak seçildiğinde sunucu değeri gösterilir"}
        </p>

        <label className="form-label" htmlFor="revizyon-tipi">
          Revizyon tipi
        </label>
        <select
          id="revizyon-tipi"
          name="revizyon_tipi"
          value={revizyonTipi}
          onChange={(event) => setRevizyonTipi(event.target.value as RevizyonTipi)}
          required
        >
          <option value="">Seçiniz</option>
          {uygunTipler.map((tipi) => (
            <option key={tipi} value={tipi}>
              {formatRevizyonTipiLabel(tipi)}
            </option>
          ))}
        </select>

        <label className="form-label" htmlFor="talep-edilen-deger">
          Talep edilen yeni değer
        </label>
        <textarea
          id="talep-edilen-deger"
          name="talep_edilen_deger"
          value={yeniDegerText}
          onChange={(event) => setYeniDegerText(event.target.value)}
          required
          rows={3}
        />

        <label className="form-label" htmlFor="revizyon-gerekce">
          Gerekçe
        </label>
        <textarea
          id="revizyon-gerekce"
          name="gerekce"
          value={gerekce}
          onChange={(event) => setGerekce(event.target.value)}
          required
          rows={3}
        />

        {canViewFinance ? (
          <div data-testid="revizyon-bordro-etki-alani">
            <label className="form-label">
              <input
                type="checkbox"
                checked={bordroEtki}
                onChange={(event) => setBordroEtki(event.target.checked)}
              />{" "}
              Bordro etkisi var
            </label>
            <FormField
              label="Bordro etki notu"
              name="bordro_etki_notu"
              value={bordroNotu}
              onChange={setBordroNotu}
            />
          </div>
        ) : null}

        {errorMessage ? (
          <p className="workspace-error" role="alert" data-testid="revizyon-create-error">
            {errorMessage}
          </p>
        ) : null}

        <div className="universal-btn-group">
          <button
            type="submit"
            className="universal-btn-save"
            disabled={isSaving}
            data-testid="revizyon-taslak-kaydet"
          >
            Taslak Kaydet
          </button>
          <button
            type="button"
            className="universal-btn-aux"
            disabled={isSaving}
            data-testid="revizyon-kaydet-gonder"
            onClick={(event) => void handleSubmit(event as unknown as FormEvent, true)}
          >
            Kaydet ve Onaya Gönder
          </button>
          <button
            type="button"
            className="universal-btn-cancel"
            disabled={isSaving}
            onClick={() => navigate("/haftalik-kapanis/revizyonlar")}
          >
            İptal
          </button>
        </div>
      </form>
    </section>
  );
}
