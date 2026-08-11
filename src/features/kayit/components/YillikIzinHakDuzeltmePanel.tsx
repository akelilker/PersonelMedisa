import { useCallback, useEffect, useState, type FormEvent } from "react";
import { FormField } from "../../../components/form/FormField";
import {
  createYillikIzinHakDuzeltme,
  fetchYillikIzinBakiye,
  fetchYillikIzinHakDuzeltmeleri,
  getYillikIzinHakDuzeltmeApiErrorMessage,
  reverseYillikIzinHakDuzeltme
} from "../../../api/yillik-izin-hak-duzeltme.api";
import type {
  CreateYillikIzinHakDuzeltmePayload,
  YillikIzinBakiye,
  YillikIzinHakDuzeltmeKaydi
} from "../../../types/yillik-izin-hak-duzeltme";

const FORM_ID = "yillik-izin-hak-duzeltme-form";

type FormState = {
  kategori: CreateYillikIzinHakDuzeltmePayload["kategori"];
  yon: "ARTI" | "EKSI";
  gun: string;
  effectiveDate: string;
  aciklama: string;
};

const INITIAL: FormState = {
  kategori: "DEVIR",
  yon: "ARTI",
  gun: "",
  effectiveDate: "",
  aciklama: ""
};

const KATEGORI_OPTIONS: Array<{ value: CreateYillikIzinHakDuzeltmePayload["kategori"]; label: string }> = [
  { value: "DEVIR", label: "Devir Kaydı" },
  { value: "EK_HAK", label: "Ek Hak" },
  { value: "DUZELTME", label: "Düzeltme" }
];

function formatSigned(value: number): string {
  return value > 0 ? `+${value}` : String(value);
}

function todayIsoDate(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(
    now.getDate()
  ).padStart(2, "0")}`;
}

function formatGunOrUnresolved(value: number | null): string {
  return value === null ? "Kesinleştirilemedi" : `${value} gün`;
}

export function YillikIzinHakDuzeltmePanel({
  personelId,
  enabled
}: {
  personelId: number;
  enabled: boolean;
}) {
  const [bakiye, setBakiye] = useState<YillikIzinBakiye | null>(null);
  const [history, setHistory] = useState<YillikIzinHakDuzeltmeKaydi[]>([]);
  const [loading, setLoading] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [form, setForm] = useState<FormState>(INITIAL);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [submitInfo, setSubmitInfo] = useState<string | null>(null);
  const [reverseTargetId, setReverseTargetId] = useState<number | null>(null);
  const [reverseReason, setReverseReason] = useState("");
  const [reversing, setReversing] = useState(false);

  const reload = useCallback(async () => {
    if (!enabled || !Number.isFinite(personelId) || personelId <= 0) {
      return;
    }
    setLoading(true);
    setLoadError(null);
    try {
      const [nextBakiye, nextHistory] = await Promise.all([
        fetchYillikIzinBakiye(personelId),
        fetchYillikIzinHakDuzeltmeleri(personelId)
      ]);
      setBakiye(nextBakiye);
      setHistory(nextHistory);
    } catch (error) {
      setLoadError(getYillikIzinHakDuzeltmeApiErrorMessage(error, "İzin hak özeti yüklenemedi."));
    } finally {
      setLoading(false);
    }
  }, [enabled, personelId]);

  useEffect(() => {
    void reload();
  }, [reload]);

  if (!enabled) {
    return null;
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (submitting) return;
    setSubmitError(null);
    setSubmitInfo(null);

    const gunAbs = Number.parseInt(form.gun.trim(), 10);
    if (!Number.isInteger(gunAbs) || gunAbs <= 0) {
      setSubmitError("Gün değişimi pozitif tam sayı olmalıdır.");
      return;
    }
    if (!form.effectiveDate) {
      setSubmitError("Etki tarihi zorunludur.");
      return;
    }
    if (form.aciklama.trim().length < 3) {
      setSubmitError("Açıklama en az 3 karakter olmalıdır.");
      return;
    }

    const payload: CreateYillikIzinHakDuzeltmePayload = {
      gun_delta: form.yon === "ARTI" ? gunAbs : -gunAbs,
      kategori: form.kategori,
      aciklama: form.aciklama.trim(),
      effective_date: form.effectiveDate
    };

    setSubmitting(true);
    try {
      await createYillikIzinHakDuzeltme(personelId, payload);
      setForm(INITIAL);
      setSubmitInfo("İzin hak düzeltmesi kaydedildi.");
      await reload();
    } catch (error) {
      setSubmitError(getYillikIzinHakDuzeltmeApiErrorMessage(error, "Kayıt oluşturulamadı."));
    } finally {
      setSubmitting(false);
    }
  }

  async function handleReverse(targetId: number) {
    if (reversing) return;
    if (reverseReason.trim().length < 3) {
      setSubmitError("Ters kayıt açıklaması en az 3 karakter olmalıdır.");
      return;
    }
    setReversing(true);
    setSubmitError(null);
    try {
      await reverseYillikIzinHakDuzeltme(personelId, targetId, { aciklama: reverseReason.trim() });
      setReverseTargetId(null);
      setReverseReason("");
      setSubmitInfo("Ters kayıt oluşturuldu.");
      await reload();
    } catch (error) {
      setSubmitError(getYillikIzinHakDuzeltmeApiErrorMessage(error, "Ters kayıt oluşturulamadı."));
    } finally {
      setReversing(false);
    }
  }

  return (
    <div className="surec-shell-panel" data-testid="yillik-izin-hak-duzeltme-panel">
      <h3 className="surec-shell-panel-title">İzin Hak Düzeltmesi</h3>
      <p className="surec-shell-panel-desc">
        Bu işlem yasal hak formülünü değiştirmez; izin hak geçmişine imzalı gün hareketi ekler.
      </p>

      {loading ? <p data-testid="yillik-izin-hak-loading">Bakiye yükleniyor…</p> : null}
      {loadError ? <p className="surec-form-error">{loadError}</p> : null}

      {bakiye ? (
        <div className="personel-izin-infobox" data-testid="yillik-izin-hak-context">
          <p data-testid="yihd-mevcut-hak">
            <strong>Bu Yıl / Mevcut Hak Ediş:</strong> {bakiye.mevcut_yillik_hak_gun} gün
          </p>
          <p data-testid="yihd-birikmis-hak">
            <strong>Birikmiş Yasal Hak:</strong> {bakiye.birikmis_yasal_hak_gun} gün
          </p>
          <p>
            <strong>Manuel Düzeltmeler:</strong> {formatSigned(bakiye.manuel_duzeltme_gun)} gün
          </p>
          <p>
            <strong>Kullanılan:</strong> {formatGunOrUnresolved(bakiye.kullanilan_gun)}
          </p>
          <p className="personel-izin-kalan">
            <strong>Kalan:</strong> {formatGunOrUnresolved(bakiye.kalan_gun)}
          </p>
          {!bakiye.takvim_dogrulandi_mi ? (
            <p data-testid="yillik-izin-hak-takvim-uyari">
              Canonical takvim eksik/belirsiz olduğu için kullanılan ve kalan kesinleştirilemedi.
            </p>
          ) : null}
        </div>
      ) : null}

      <form id={FORM_ID} className="workspace-form workspace-form-stack" onSubmit={handleSubmit}>
        <FormField
          label="Kategori"
          name="yihd-kategori"
          as="select"
          value={form.kategori}
          onChange={(value) =>
            setForm((prev) => ({
              ...prev,
              kategori: value as FormState["kategori"]
            }))
          }
          selectOptions={KATEGORI_OPTIONS}
          required
        />

        <FormField
          label="Yön"
          name="yihd-yon"
          as="select"
          value={form.yon}
          onChange={(value) => setForm((prev) => ({ ...prev, yon: value as FormState["yon"] }))}
          selectOptions={[
            { value: "ARTI", label: "Ekle (+)" },
            { value: "EKSI", label: "Düş (-)" }
          ]}
          required
        />

        <FormField
          label="Gün Değişimi"
          name="yihd-gun"
          type="number"
          min={1}
          step="1"
          value={form.gun}
          onChange={(value) => setForm((prev) => ({ ...prev, gun: value }))}
          required
        />

        <FormField
          label="Etki Tarihi"
          name="yihd-effective"
          type="date"
          value={form.effectiveDate}
          onChange={(value) => setForm((prev) => ({ ...prev, effectiveDate: value }))}
          required
        />

        <FormField
          label="Açıklama"
          name="yihd-aciklama"
          as="textarea"
          rows={3}
          value={form.aciklama}
          onChange={(value) => setForm((prev) => ({ ...prev, aciklama: value }))}
          required
        />

        {submitError ? <p className="surec-form-error">{submitError}</p> : null}
        {submitInfo ? <p className="workspace-success">{submitInfo}</p> : null}

        <div className="workspace-inline-actions">
          <button
            type="submit"
            className="universal-btn-primary"
            data-testid="yihd-submit"
            disabled={submitting}
          >
            {submitting ? "Kaydediliyor…" : "Düzeltmeyi Kaydet"}
          </button>
        </div>
      </form>

      <div className="personel-izin-list" data-testid="yihd-history">
        <h4>Hak Düzeltme Geçmişi</h4>
        {history.length === 0 ? (
          <p>Kayıtlı hak düzeltmesi yok.</p>
        ) : (
          <ul className="personel-surec-list">
            {history.map((row) => {
              const isFuture = row.effective_date > todayIsoDate();
              return (
              <li key={row.id} className="personel-surec-card" data-testid={`yihd-row-${row.id}`}>
                <span>
                  {row.effective_date} · {row.kategori} · {formatSigned(row.gun_delta)} gün
                  {isFuture ? " · İleri tarihli" : ""}
                  {row.is_reversed ? " · terslendi" : ""}
                  {row.reverses_id ? ` · #${row.reverses_id} tersi` : ""}
                </span>
                <span>{row.aciklama}</span>
                {row.created_by_display ? <span>{row.created_by_display}</span> : null}
                {!row.is_reversed && row.kategori !== "TERS_KAYIT" ? (
                  reverseTargetId === row.id ? (
                    <div className="workspace-inline-actions">
                      <input
                        data-testid={`yihd-reverse-reason-${row.id}`}
                        value={reverseReason}
                        onChange={(event) => setReverseReason(event.target.value)}
                        placeholder="Ters kayıt açıklaması"
                      />
                      <button
                        type="button"
                        className="universal-btn-primary"
                        data-testid={`yihd-reverse-confirm-${row.id}`}
                        disabled={reversing}
                        onClick={() => void handleReverse(row.id)}
                      >
                        Onayla
                      </button>
                      <button
                        type="button"
                        className="universal-btn-aux"
                        onClick={() => {
                          setReverseTargetId(null);
                          setReverseReason("");
                        }}
                      >
                        Vazgeç
                      </button>
                    </div>
                  ) : (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      data-testid={`yihd-reverse-${row.id}`}
                      onClick={() => {
                        setReverseTargetId(row.id);
                        setReverseReason("");
                      }}
                    >
                      Tersle
                    </button>
                  )
                ) : null}
              </li>
              );
            })}
          </ul>
        )}
      </div>
    </div>
  );
}
