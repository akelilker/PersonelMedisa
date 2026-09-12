import {
  useLayoutEffect,
  useMemo,
  type Dispatch,
  type SetStateAction
} from "react";
import { AppSelectField } from "../../../components/form/AppSelect";
import { FormField } from "../../../components/form/FormField";
import { mapUcretTipiSelectOptions } from "../../../lib/display/ucret-tipi-display";
import { CALISAN_KAPSAMI_SELECT_OPTIONS } from "../../../lib/display/enum-display";
import type { PersonelReferenceBundle } from "../../../data/app-data.types";
import type { CreatePersonelFormState } from "../../../hooks/usePersoneller";
import type { IdOption } from "../../../types/referans";
import {
  filterSgkIsverenOptionsForSube,
  resolveSgkIsverenAfterSubeChange
} from "../personel-create-org-deps";

type PersonelCreateFieldsProps = {
  form: CreatePersonelFormState;
  setForm: Dispatch<SetStateAction<CreatePersonelFormState>>;
  onDepartmanChange?: (value: string) => void;
  onBagliAmirChange?: (value: string) => void;
  bagliAmirInfoMessage?: string | null;
  bagliAmirSubeWarning?: string | null;
  bagliAmirDepartmanWarning?: string | null;
  refs: PersonelReferenceBundle;
  subeOptions?: IdOption[];
  subeLoadError?: string | null;
  createErrorMessage?: string | null;
  fieldErrors?: Partial<Record<"tcKimlikNo" | "subeId", string>>;
  onFieldErrorClear?: (field: "tcKimlikNo" | "subeId") => void;
  referenceError?: string | null;
  className?: string;
  /** Ücret yönetim yetkisi olmayan roller maaş alanını görmez. */
  canManageUcret?: boolean;
};

function toSelectOptions(options: IdOption[]) {
  return options.map((option) => ({ value: String(option.id), label: option.label }));
}

const kanGrubuOptions = [
  { value: "A Rh+", label: "A Rh+" },
  { value: "A Rh-", label: "A Rh-" },
  { value: "B Rh+", label: "B Rh+" },
  { value: "B Rh-", label: "B Rh-" },
  { value: "AB Rh+", label: "AB Rh+" },
  { value: "AB Rh-", label: "AB Rh-" },
  { value: "0 Rh+", label: "0 Rh+" },
  { value: "0 Rh-", label: "0 Rh-" }
];

function refMissingNote(label: string, blocking: boolean) {
  return (
    <p className="personel-create-error" role="alert">
      {blocking
        ? `${label} listesi yüklenemedi. Bu alan zorunlu; referanslar gelene kadar kayıt oluşturulamaz.`
        : `${label} referansı yüklenemedi; bu alan şimdilik seçilemez.`}
    </p>
  );
}

export function PersonelCreateFields({
  form,
  setForm,
  onDepartmanChange,
  onBagliAmirChange,
  bagliAmirInfoMessage,
  bagliAmirSubeWarning,
  bagliAmirDepartmanWarning,
  refs,
  subeOptions = [],
  subeLoadError,
  createErrorMessage,
  fieldErrors,
  onFieldErrorClear,
  referenceError,
  className,
  canManageUcret = false
}: PersonelCreateFieldsProps) {
  const tcKimlikNoFieldError = fieldErrors?.tcKimlikNo;
  const subeIdFieldError = fieldErrors?.subeId;

  const filteredSgkIsverenOptions = useMemo(
    () => filterSgkIsverenOptionsForSube(refs.sgkIsverenOptions, subeOptions, form.subeId),
    [form.subeId, refs.sgkIsverenOptions, subeOptions]
  );


  function handleSubeChange(value: string) {
    const nextSgkOptions = filterSgkIsverenOptionsForSube(refs.sgkIsverenOptions, subeOptions, value);
    setForm((prev) => ({
      ...prev,
      subeId: value,
      sgkIsverenId: resolveSgkIsverenAfterSubeChange(prev.sgkIsverenId, nextSgkOptions)
    }));
    onFieldErrorClear?.("subeId");
  }

  function handleDepartmanChange(value: string) {
    if (onDepartmanChange) {
      onDepartmanChange(value);
      return;
    }
    const nextBolumId =
      value &&
      form.bolumId &&
      refs.bolumOptions.some(
        (opt) => String(opt.id) === form.bolumId && String(opt.parentId ?? "") === value
      )
        ? form.bolumId
        : "";
    const nextBirimId =
      nextBolumId &&
      form.birimId &&
      refs.birimOptions.some(
        (opt) => String(opt.id) === form.birimId && String(opt.parentId ?? "") === nextBolumId
      )
        ? form.birimId
        : "";
    setForm((prev) => ({
      ...prev,
      departmanId: value,
      bolumId: nextBolumId,
      birimId: nextBirimId
    }));
  }

  useLayoutEffect(() => {
    if (tcKimlikNoFieldError) {
      const input = document.getElementById("create-tc");
      if (!(input instanceof HTMLInputElement)) {
        return;
      }

      try {
        input.focus({ preventScroll: true });
        input.scrollIntoView({ block: "nearest", behavior: "smooth" });
      } catch {
        /* Focus/scroll desteklenmiyorsa sessizce devam et. */
      }
      return;
    }

    if (!subeIdFieldError) {
      return;
    }

    const trigger = document.getElementById("create-sube");
    if (!(trigger instanceof HTMLElement)) {
      return;
    }

    try {
      trigger.focus({ preventScroll: true });
      trigger.scrollIntoView({ block: "nearest", behavior: "smooth" });
    } catch {
      /* Focus/scroll desteklenmiyorsa sessizce devam et. */
    }
  }, [tcKimlikNoFieldError, subeIdFieldError]);

  return (
    <div className={className}>
      <div className="personel-form-columns">
        <div className="personel-form-column">
          <AppSelectField
            label="Çalışan Kapsamı"
            name="create-calisan-kapsami"
            value={form.calisanKapsami}
            onChange={(value) =>
              setForm((prev) => ({
                ...prev,
                calisanKapsami: value === "DIS_KAYNAK" ? "DIS_KAYNAK" : "IC_PERSONEL",
                ...(value === "DIS_KAYNAK" ? { sgkIsverenId: "" } : {})
              }))
            }
            required
            options={CALISAN_KAPSAMI_SELECT_OPTIONS}
          />
          <p className="personel-form-note personel-form-note--info" data-testid="create-sicil-auto-note">
            Sicil numarası kayıt sırasında otomatik atanacaktır.
          </p>
          <FormField
            label="T.C. Kimlik No"
            name="create-tc"
            value={form.tcKimlikNo}
            onChange={(value) => {
              setForm((prev) => ({ ...prev, tcKimlikNo: value }));
              onFieldErrorClear?.("tcKimlikNo");
            }}
            placeholder="Örn. 12345678122"
            required={form.calisanKapsami !== "DIS_KAYNAK"}
          />
          {tcKimlikNoFieldError ? (
            <p className="personel-create-error" role="alert">
              {tcKimlikNoFieldError}
            </p>
          ) : null}
          <FormField
            label="Ad"
            name="create-ad"
            value={form.ad}
            onChange={(value) => setForm((prev) => ({ ...prev, ad: value }))}
            placeholder="Örn. İlker"
            required
          />
          <FormField
            label="Soyad"
            name="create-soyad"
            value={form.soyad}
            onChange={(value) => setForm((prev) => ({ ...prev, soyad: value }))}
            placeholder="Örn. AKEL"
            required={form.calisanKapsami !== "DIS_KAYNAK"}
          />
          <FormField
            label="Doğum Tarihi"
            name="create-dogum"
            type="date"
            value={form.dogumTarihi}
            onChange={(value) => setForm((prev) => ({ ...prev, dogumTarihi: value }))}
            required={form.calisanKapsami !== "DIS_KAYNAK"}
          />
          <FormField
            label="Telefon"
            name="create-telefon"
            type="tel"
            value={form.telefon}
            onChange={(value) => setForm((prev) => ({ ...prev, telefon: value }))}
            placeholder="Örn. 0532 123 45 67"
            required={form.calisanKapsami !== "DIS_KAYNAK"}
          />
          <FormField
            label="Acil Durum Kişisi"
            name="create-acil-kisi"
            value={form.acilDurumKisi}
            onChange={(value) => setForm((prev) => ({ ...prev, acilDurumKisi: value }))}
            placeholder="Örn. Serhan Köse"
            required={form.calisanKapsami !== "DIS_KAYNAK"}
          />
          <FormField
            label="Acil Durum Telefon"
            name="create-acil-tel"
            type="tel"
            value={form.acilDurumTelefon}
            onChange={(value) => setForm((prev) => ({ ...prev, acilDurumTelefon: value }))}
            placeholder="Örn. 0532 123 45 67"
            required={form.calisanKapsami !== "DIS_KAYNAK"}
          />
          <FormField
            label="Doğum Yeri"
            name="create-dogum-yeri"
            value={form.dogumYeri}
            onChange={(value) => setForm((prev) => ({ ...prev, dogumYeri: value }))}
            placeholder="Örn. İstanbul"
          />
          <AppSelectField
            label="Kan Grubu"
            name="create-kan"
            value={form.kanGrubu}
            onChange={(value) => setForm((prev) => ({ ...prev, kanGrubu: value }))}
            placeholderOption={{ value: "", label: "Seçiniz" }}
            options={kanGrubuOptions}
          />
        </div>

        <div className="personel-form-column">
          <FormField
            label="İşe Giriş Tarihi"
            name="create-ise-giris"
            type="date"
            value={form.iseGirisTarihi}
            onChange={(value) => setForm((prev) => ({ ...prev, iseGirisTarihi: value }))}
            required
          />
          {subeLoadError ? (
            <p className="personel-create-error" role="alert">
              {subeLoadError}
            </p>
          ) : subeOptions.length > 0 ? (
            <>
              <AppSelectField
                label="Şube"
                name="create-sube"
                value={form.subeId}
                onChange={handleSubeChange}
                required={form.calisanKapsami !== "DIS_KAYNAK"}
                placeholderOption={{ value: "", label: "Seçiniz" }}
                options={toSelectOptions(subeOptions)}
              />
              {subeIdFieldError ? (
                <p className="personel-create-error" role="alert">
                  {subeIdFieldError}
                </p>
              ) : null}
            </>
          ) : (
            refMissingNote("Şube", true)
          )}
          {form.calisanKapsami !== "DIS_KAYNAK" ? (
            refs.sgkIsverenOptions.length > 0 ? (
              <>
                <AppSelectField
                  label="SGK İşveren"
                  name="create-sgk-isveren"
                  value={form.sgkIsverenId}
                  onChange={(value) => setForm((prev) => ({ ...prev, sgkIsverenId: value }))}
                  required
                  placeholderOption={{ value: "", label: "Seçiniz" }}
                  options={toSelectOptions(filteredSgkIsverenOptions)}
                  disabled={!form.subeId}
                />
                {form.subeId && filteredSgkIsverenOptions.length === 0 ? (
                  <p className="personel-form-note personel-form-note--warning" role="status">
                    Seçilen şubenin şirketiyle uyumlu SGK işvereni bulunamadı.
                  </p>
                ) : null}
              </>
            ) : (
              refMissingNote("SGK işveren", true)
            )
          ) : null}
          {form.calisanKapsami !== "DIS_KAYNAK" && refs.calismaLokasyonuOptions.length > 0 ? (
            <AppSelectField
              label="Çalışma Lokasyonu"
              name="create-calisma-lokasyonu"
              value={form.calismaLokasyonuId}
              onChange={(value) => setForm((prev) => ({ ...prev, calismaLokasyonuId: value }))}
              placeholderOption={{ value: "", label: "Seçiniz" }}
              options={toSelectOptions(refs.calismaLokasyonuOptions)}
            />
          ) : null}
          {refs.bagliAmirOptions.length > 0 ? (
            <>
              <AppSelectField
                label="Bağlı Amir"
                name="create-bagli-amir"
                value={form.bagliAmirId}
                onChange={
                  onBagliAmirChange ??
                  ((value) => setForm((prev) => ({ ...prev, bagliAmirId: value })))
                }
                placeholderOption={{ value: "", label: "Seçiniz" }}
                options={toSelectOptions(refs.bagliAmirOptions)}
              />
              {bagliAmirInfoMessage ? (
                <p className="personel-form-note personel-form-note--info">{bagliAmirInfoMessage}</p>
              ) : null}
              {bagliAmirSubeWarning ? (
                <p className="personel-form-note personel-form-note--warning">{bagliAmirSubeWarning}</p>
              ) : null}
            </>
          ) : (
            refMissingNote("Bağlı amir", false)
          )}
          {refs.departmanOptions.length > 0 ? (
            <>
              <AppSelectField
                label="Departman"
                name="create-departman"
                value={form.departmanId}
                onChange={handleDepartmanChange}
                required={form.calisanKapsami !== "DIS_KAYNAK"}
                placeholderOption={{ value: "", label: "Seçiniz" }}
                options={toSelectOptions(refs.departmanOptions)}
              />
              {bagliAmirDepartmanWarning ? (
                <p className="personel-form-note personel-form-note--warning">
                  {bagliAmirDepartmanWarning}
                </p>
              ) : null}
            </>
          ) : (
            refMissingNote("Departman", true)
          )}
          {refs.bolumOptions.length > 0 ? (
            <AppSelectField
              label="Bölüm"
              name="create-bolum"
              value={form.bolumId}
              onChange={(value) => {
                const nextBirimId =
                  value &&
                  form.birimId &&
                  refs.birimOptions.some(
                    (opt) => String(opt.id) === form.birimId && String(opt.parentId ?? "") === value
                  )
                    ? form.birimId
                    : "";
                setForm((prev) => ({ ...prev, bolumId: value, birimId: nextBirimId }));
              }}
              placeholderOption={{ value: "", label: "Seçiniz" }}
              options={toSelectOptions(
                refs.bolumOptions.filter(
                  (opt) => !form.departmanId || String(opt.parentId ?? "") === form.departmanId
                )
              )}
              disabled={!form.departmanId}
            />
          ) : null}
          {refs.birimOptions.length > 0 ? (
            <AppSelectField
              label="Birim"
              name="create-birim"
              value={form.birimId}
              onChange={(value) => setForm((prev) => ({ ...prev, birimId: value }))}
              placeholderOption={{ value: "", label: "Seçiniz" }}
              options={toSelectOptions(
                refs.birimOptions.filter(
                  (opt) => !form.bolumId || String(opt.parentId ?? "") === form.bolumId
                )
              )}
              disabled={!form.bolumId}
            />
          ) : null}
          {refs.gorevOptions.length > 0 ? (
            <AppSelectField
              label="Görev / Unvan"
              name="create-gorev"
              value={form.gorevId}
              onChange={(value) => setForm((prev) => ({ ...prev, gorevId: value }))}
              required={form.calisanKapsami !== "DIS_KAYNAK"}
              placeholderOption={{ value: "", label: "Seçiniz" }}
              options={toSelectOptions(refs.gorevOptions)}
            />
          ) : (
            refMissingNote("Görev / Unvan", true)
          )}
          {refs.pozisyonOptions.length > 0 ? (
            <AppSelectField
              label="Pozisyon"
              name="create-pozisyon"
              value={form.pozisyonId}
              onChange={(value) => setForm((prev) => ({ ...prev, pozisyonId: value }))}
              placeholderOption={{ value: "", label: "Seçiniz" }}
              options={toSelectOptions(refs.pozisyonOptions)}
            />
          ) : null}
          {refs.personelTipiOptions.length > 0 ? (
            <AppSelectField
              label="Çalışma Tipi"
              name="create-personel-tipi"
              value={form.personelTipiId}
              onChange={(value) => setForm((prev) => ({ ...prev, personelTipiId: value }))}
              required={form.calisanKapsami !== "DIS_KAYNAK"}
              placeholderOption={{ value: "", label: "Seçiniz" }}
              options={toSelectOptions(refs.personelTipiOptions)}
            />
          ) : (
            refMissingNote("Çalışma Tipi", true)
          )}
          {form.calisanKapsami !== "DIS_KAYNAK" && refs.ucretTipiOptions.length > 0 ? (
            <AppSelectField
              label="Ücret Tipi"
              name="create-ucret-tipi"
              value={form.ucretTipiId}
              onChange={(value) => setForm((prev) => ({ ...prev, ucretTipiId: value }))}
              options={mapUcretTipiSelectOptions(refs.ucretTipiOptions)}
            />
          ) : (
            refMissingNote("Ücret Tipi", false)
          )}
          {form.calisanKapsami !== "DIS_KAYNAK" && canManageUcret ? (
            <FormField
              label="Net Maaş"
              name="create-maas"
              type="number"
              min={0}
              step="0.01"
              value={form.maasTutari}
              onChange={(value) => setForm((prev) => ({ ...prev, maasTutari: value }))}
              placeholder="Örn. 35000"
            />
          ) : null}
        </div>
      </div>

      {createErrorMessage ? <p className="personel-create-error">{createErrorMessage}</p> : null}
      {referenceError ? <p className="personel-create-error">{referenceError}</p> : null}
    </div>
  );
}
