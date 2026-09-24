import { useMemo, type Dispatch, type FormEvent, type SetStateAction } from "react";
import { FormField } from "../../../components/form/FormField";
import type { PersonelReferenceBundle } from "../../../data/app-data.types";
import { filterStatuOptionsForCreate } from "../../personeller/personel-create-org-deps";
import type { Personel } from "../../../types/personel";
import type { IdOption } from "../../../types/referans";
import { formatGeneralField } from "../kayit-surec-utils";
import type { OrganizasyonFormState } from "../kayit-surec-constants";
import { KayitSurecPozisyonReferencePicker } from "./KayitSurecPozisyonReferencePicker";
import { KAYIT_SUREC_POZISYON_FORM_ID } from "../kayit-surec-constants";

type Props = {
  personel: Personel;
  form: OrganizasyonFormState;
  setForm: Dispatch<SetStateAction<OrganizasyonFormState>>;
  refs: PersonelReferenceBundle;
  subeOptions: IdOption[];
  canSubmitOrg: boolean;
  canTransferSube: boolean;
  orgSubmitting: boolean;
  subeSubmitting: boolean;
  orgError: string | null;
  orgInfo: string | null;
  subeError: string | null;
  subeInfo: string | null;
  openPicker: string | null;
  setOpenPicker: (name: string | null) => void;
  yeniSubeId: string;
  setYeniSubeId: (value: string) => void;
  subeGerekce: string;
  setSubeGerekce: (value: string) => void;
  onOrgSubmit: (event: FormEvent<HTMLFormElement>) => void;
  onSubeSubmit: (event: FormEvent<HTMLFormElement>) => void;
};

function filterByParent(options: IdOption[], parentId: string) {
  if (!parentId) {
    return options;
  }
  return options.filter((opt) => String(opt.parentId ?? "") === parentId);
}

function hasPositivePersonelTipiId(value: number | null | undefined): boolean {
  return typeof value === "number" && Number.isFinite(value) && value > 0;
}

export function KayitSurecPersonelOrganizasyonPanel({
  personel,
  form,
  setForm,
  refs,
  subeOptions,
  canSubmitOrg,
  canTransferSube,
  orgSubmitting,
  subeSubmitting,
  orgError,
  orgInfo,
  subeError,
  subeInfo,
  openPicker,
  setOpenPicker,
  yeniSubeId,
  setYeniSubeId,
  subeGerekce,
  setSubeGerekce,
  onOrgSubmit,
  onSubeSubmit
}: Props) {
  const bolumOptions = filterByParent(refs.bolumOptions, form.departmanId);
  const birimOptions = filterByParent(refs.birimOptions, form.bolumId);
  const isDisKaynak = personel.calisan_kapsami === "DIS_KAYNAK";
  const statuOptions = useMemo(
    () => filterStatuOptionsForCreate(refs.personelTipiOptions),
    [refs.personelTipiOptions]
  );
  const statuUnset = !form.personelTipiId && !hasPositivePersonelTipiId(personel.personel_tipi_id);

  return (
    <div className="surec-position-panel" data-testid="kayit-surec-organizasyon-panel">
      <div className="surec-person-placeholder surec-org-section-lead">
        <strong>Görev ve Organizasyon Değişikliği</strong>
        <p>
          Değişiklik anında personel kaydına uygulanır. Structured audit canonical owner üzerinden tutulur;
          gelecek tarihli planlama yoktur.
        </p>
      </div>

      {canSubmitOrg ? (
        <form
          id={KAYIT_SUREC_POZISYON_FORM_ID}
          className="workspace-form surec-position-form"
          onSubmit={onOrgSubmit}
        >
          <div className="surec-position-grid">
            <KayitSurecPozisyonReferencePicker
              label="Departman"
              name="pozisyon-departman"
              value={form.departmanId}
              options={refs.departmanOptions}
              isOpen={openPicker === "departman"}
              onOpenChange={(isOpen) => setOpenPicker(isOpen ? "departman" : null)}
              onChange={(value) =>
                setForm((prev) => ({
                  ...prev,
                  departmanId: value,
                  bolumId: "",
                  birimId: ""
                }))
              }
            />
            <KayitSurecPozisyonReferencePicker
              label="Bölüm"
              name="pozisyon-bolum"
              value={form.bolumId}
              options={bolumOptions}
              isOpen={openPicker === "bolum"}
              onOpenChange={(isOpen) => setOpenPicker(isOpen ? "bolum" : null)}
              onChange={(value) =>
                setForm((prev) => ({
                  ...prev,
                  bolumId: value,
                  birimId: ""
                }))
              }
              disabled={!form.departmanId}
            />
            <KayitSurecPozisyonReferencePicker
              label="Birim"
              name="pozisyon-birim"
              value={form.birimId}
              options={birimOptions}
              isOpen={openPicker === "birim"}
              onOpenChange={(isOpen) => setOpenPicker(isOpen ? "birim" : null)}
              onChange={(value) => setForm((prev) => ({ ...prev, birimId: value }))}
              disabled={!form.bolumId}
            />
            <KayitSurecPozisyonReferencePicker
              label="Görev / Unvan"
              name="pozisyon-gorev"
              value={form.gorevId}
              options={refs.gorevOptions}
              isOpen={openPicker === "gorev"}
              onOpenChange={(isOpen) => setOpenPicker(isOpen ? "gorev" : null)}
              onChange={(value) => setForm((prev) => ({ ...prev, gorevId: value }))}
            />
            <KayitSurecPozisyonReferencePicker
              label="Pozisyon"
              name="pozisyon-pozisyon"
              value={form.pozisyonId}
              options={refs.pozisyonOptions}
              isOpen={openPicker === "pozisyon-ref"}
              onOpenChange={(isOpen) => setOpenPicker(isOpen ? "pozisyon-ref" : null)}
              onChange={(value) => setForm((prev) => ({ ...prev, pozisyonId: value }))}
            />
            <KayitSurecPozisyonReferencePicker
              label="Çalışma Lokasyonu"
              name="pozisyon-lokasyon"
              value={form.calismaLokasyonuId}
              options={refs.calismaLokasyonuOptions}
              isOpen={openPicker === "lokasyon"}
              onOpenChange={(isOpen) => setOpenPicker(isOpen ? "lokasyon" : null)}
              onChange={(value) => setForm((prev) => ({ ...prev, calismaLokasyonuId: value }))}
            />
            {refs.sgkIsverenOptions.length > 0 ? (
              <KayitSurecPozisyonReferencePicker
                label="SGK İşvereni"
                name="pozisyon-sgk"
                value={form.sgkIsverenId}
                options={refs.sgkIsverenOptions}
                isOpen={openPicker === "sgk"}
                onOpenChange={(isOpen) => setOpenPicker(isOpen ? "sgk" : null)}
                onChange={(value) => setForm((prev) => ({ ...prev, sgkIsverenId: value }))}
              />
            ) : null}
          </div>

          <div className="surec-person-placeholder surec-org-section-mid">
            <strong>Çalışma Bilgileri</strong>
            <p>
              Bağlı amir ve Statü (Mavi Yaka / Beyaz Yaka), organizasyon alanlarıyla aynı kaydet işleminde
              atomik olarak uygulanır.
            </p>
          </div>

          <div className="surec-position-grid">
            <KayitSurecPozisyonReferencePicker
              label="Bağlı Amir"
              name="pozisyon-bagli-amir"
              value={form.bagliAmirId}
              options={refs.bagliAmirOptions}
              isOpen={openPicker === "bagli-amir"}
              onOpenChange={(isOpen) => setOpenPicker(isOpen ? "bagli-amir" : null)}
              onChange={(value) => setForm((prev) => ({ ...prev, bagliAmirId: value }))}
            />
            <KayitSurecPozisyonReferencePicker
              label="Statü"
              name="pozisyon-personel-tipi"
              value={form.personelTipiId}
              options={statuOptions}
              isOpen={openPicker === "personel-tipi"}
              onOpenChange={(isOpen) => setOpenPicker(isOpen ? "personel-tipi" : null)}
              onChange={(value) => setForm((prev) => ({ ...prev, personelTipiId: value }))}
              required={!isDisKaynak}
            />
          </div>
          {statuUnset ? (
            <p className="personel-form-note personel-form-note--warning" role="status" data-testid="org-statu-qr-hint">
              Personel Statüsü belirlenmedi. QR kullanımı Mavi Yaka Statüsünde açılır.
            </p>
          ) : null}

          <FormField
            as="select"
            label="Değişiklik Nedeni"
            name="pozisyon-neden"
            value={form.degisiklikNedeni}
            onChange={(value) =>
              setForm((prev) => ({
                ...prev,
                degisiklikNedeni: value as OrganizasyonFormState["degisiklikNedeni"]
              }))
            }
            placeholderOption={{ value: "", label: "Seçiniz (opsiyonel)" }}
            selectOptions={[
              { value: "TERFI", label: "Terfi" },
              { value: "GOREV_DEGISTI", label: "Görev / organizasyon değişikliği" },
              { value: "YAPILANDIRMA", label: "Organizasyon yapılandırması" }
            ]}
          />

          <FormField
            label="Değişiklik Tarihi"
            name="pozisyon-effective-date"
            type="date"
            value={form.degisiklikTarihi}
            onChange={(value) =>
              setForm((prev) => ({ ...prev, degisiklikTarihi: value, effectiveDate: value }))
            }
          />
          <p className="personel-form-note personel-form-note--info">
            Değişiklik hemen uygulanır. Bu tarih yalnızca süreç geçmişi notu içindir; gelecek tarih planlamaz.
          </p>

          <FormField
            label="Açıklama / Değişiklik Nedeni"
            name="pozisyon-aciklama"
            as="textarea"
            value={form.aciklama}
            onChange={(value) => setForm((prev) => ({ ...prev, aciklama: value }))}
            placeholder="En az 10 karakter (organizasyon alanları değişince zorunlu)"
            rows={2}
          />

          {orgError ? <p className="workspace-error">{orgError}</p> : null}
          {orgInfo ? <p className="workspace-success">{orgInfo}</p> : null}
          {orgSubmitting ? <p className="personel-form-note">Kaydediliyor...</p> : null}
        </form>
      ) : (
        <div className="surec-person-placeholder">
          <strong>Görev / Organizasyon</strong>
          <p>Bu işlem için yetkin yok.</p>
        </div>
      )}

      {canTransferSube ? (
        <section
          className="workspace-form surec-org-sube-transfer"
          data-testid="kayit-surec-kalici-sube-panel"
        >
          <div className="surec-person-placeholder surec-org-section-sub">
            <strong>Kalıcı Şube Değişikliği</strong>
            <p>
              Mevcut şube: {formatGeneralField(personel.sube_adi)}. Çalışma lokasyonu ve SGK işvereni korunur;
              şube ile otomatik eşitlenmez.
            </p>
          </div>
          <form id="kayit-surec-kalici-sube-form" onSubmit={onSubeSubmit}>
            <FormField
              as="select"
              label="Yeni Şube"
              name="kalici-yeni-sube"
              value={yeniSubeId}
              onChange={setYeniSubeId}
              placeholderOption={{ value: "", label: "Seçiniz" }}
              selectOptions={subeOptions
                .filter((opt) => opt.id !== personel.sube_id)
                .map((opt) => ({ value: String(opt.id), label: opt.label }))}
              required
            />
            <FormField
              label="Gerekçe"
              name="kalici-sube-gerekce"
              as="textarea"
              value={subeGerekce}
              onChange={setSubeGerekce}
              placeholder="En az 10 karakter"
              rows={2}
              required
            />
            {subeError ? <p className="workspace-error">{subeError}</p> : null}
            {subeInfo ? <p className="workspace-success">{subeInfo}</p> : null}
            <div className="universal-btn-group">
              <button
                type="submit"
                className="universal-btn-save"
                disabled={subeSubmitting || orgSubmitting}
                data-testid="kayit-surec-sube-transfer-submit"
              >
                {subeSubmitting ? "Değiştiriliyor..." : "Şubeyi Değiştir"}
              </button>
            </div>
          </form>
        </section>
      ) : null}
    </div>
  );
}
