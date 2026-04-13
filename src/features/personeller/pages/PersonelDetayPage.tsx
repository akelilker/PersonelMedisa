import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from "react";
import { Link, useParams } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { usePersonelDetail } from "../../../hooks/usePersoneller";
import {
  formatAktifDurumLabel,
  formatZimmetKayitDurumuLabel,
  formatZimmetTeslimDurumuLabel,
  formatZimmetUrunTuruLabel,
  formatSurecStateLabel,
  formatSurecTuruLabel
} from "../../../lib/display/enum-display";
import { hesaplaIzinBakiye } from "../../../services/izin-hesap-motoru";
import type { KeyOption } from "../../../types/referans";
import type { Personel } from "../../../types/personel";
import type { Surec } from "../../../types/surec";
import { ZIMMET_TESLIM_DURUMU_OPTIONS, ZIMMET_URUN_TURU_OPTIONS, type Zimmet } from "../../../types/zimmet";

const PERSONEL_DOSYA_TABS = [
  { id: "genel-bilgiler", label: "Genel Bilgiler" },
  { id: "puantaj", label: "Puantaj" },
  { id: "izin-devamsizlik", label: "Izin & Devamsizlik" },
  { id: "zimmet-envanter", label: "Zimmet & Envanter" },
  { id: "surec-gecmisi", label: "Surec Gecmisi" }
] as const;

const PERSONEL_SUREC_FORM_ID = "personel-surec-form";
const PERSONEL_ZIMMET_FORM_ID = "personel-zimmet-form";

type PersonelDosyaTabId = (typeof PERSONEL_DOSYA_TABS)[number]["id"];
type PersonelTimelineEventTone = "default" | "danger";

type PersonelTimelineEvent = {
  id: string;
  tarih: string | null;
  baslik: string;
  kaynak: string;
  ozet: string;
  aciklama?: string;
  etiket?: string;
  tone?: PersonelTimelineEventTone;
  sortValue: number;
  sortRank: number;
};

function formatDetailValue(value: string | null | undefined) {
  if (typeof value !== "string") {
    return "-";
  }

  const trimmed = value.trim();
  return trimmed ? trimmed : "-";
}

function formatDetailNumber(value: number | null | undefined) {
  return typeof value === "number" ? String(value) : "-";
}

function formatReferenceValue(label?: string, id?: number) {
  if (label) {
    return label;
  }

  return typeof id === "number" ? `#${id}` : "-";
}

function formatSgkHesaplamaModuLabel(value?: string) {
  if (value === "OTUZ_GUN_STANDART") {
    return "30 gun standart";
  }

  if (value === "TAKVIM_GUNU") {
    return "Takvim gunu";
  }

  return "-";
}

function keyOptionsToSelectOptions(options: KeyOption[]) {
  return options.map((option) => ({ value: option.key, label: option.label }));
}

function parseTimelineDate(value: string | null | undefined) {
  if (typeof value !== "string") {
    return null;
  }

  const trimmed = value.trim();
  if (!trimmed) {
    return null;
  }

  const parsed = Date.parse(`${trimmed}T00:00:00`);
  return Number.isFinite(parsed) ? parsed : null;
}

function normalizeTimelineText(value: string | null | undefined) {
  const formatted = formatDetailValue(value);
  return formatted === "-" ? null : formatted;
}

function createTimelineSortValue(value: string | null | undefined) {
  const parsed = parseTimelineDate(value);
  return parsed ?? Number.NEGATIVE_INFINITY;
}

function buildSurecTitle(surec: Surec) {
  const anaBaslik = formatSurecTuruLabel(surec.surec_turu);
  const altBaslik = normalizeTimelineText(
    surec.alt_tur ? formatSurecTuruLabel(surec.alt_tur) : null
  );

  if (altBaslik && altBaslik !== anaBaslik) {
    return `${anaBaslik} / ${altBaslik}`;
  }

  return anaBaslik;
}

function buildSurecOzet(surec: Surec) {
  const parts = [
    surec.baslangic_tarihi ? `Baslangic: ${surec.baslangic_tarihi}` : null,
    surec.bitis_tarihi ? `Bitis: ${surec.bitis_tarihi}` : null
  ].filter((part): part is string => part !== null);

  return parts.length > 0 ? parts.join(" / ") : "Surec kaydi";
}

function buildPersonelTimeline(
  personel: Personel,
  surecler: Surec[],
  zimmetler: Zimmet[]
): PersonelTimelineEvent[] {
  const events: PersonelTimelineEvent[] = [];
  const iseGirisTarihi = normalizeTimelineText(personel.ise_giris_tarihi);

  if (iseGirisTarihi) {
    const iseGirisOzeti = [
      normalizeTimelineText(personel.sicil_no) ? `Sicil ${personel.sicil_no}` : null,
      normalizeTimelineText(personel.departman_adi),
      normalizeTimelineText(personel.gorev_adi)
    ]
      .filter((part): part is string => part !== null)
      .join(" / ");

    events.push({
      id: `personel-ise-giris-${personel.id}`,
      tarih: iseGirisTarihi,
      baslik: "Ise Giris",
      kaynak: "Personel",
      ozet: iseGirisOzeti || "Personel kaydi olusturuldu.",
      sortValue: createTimelineSortValue(iseGirisTarihi),
      sortRank: 4
    });
  }

  for (const surec of surecler) {
    const tarih = normalizeTimelineText(surec.baslangic_tarihi ?? surec.bitis_tarihi);
    const surecTuru = surec.surec_turu.trim().toUpperCase();
    events.push({
      id: `surec-${surec.id}`,
      tarih,
      baslik: buildSurecTitle(surec),
      kaynak: "Surec",
      ozet: buildSurecOzet(surec),
      aciklama: normalizeTimelineText(surec.aciklama) ?? undefined,
      etiket: normalizeTimelineText(formatSurecStateLabel(surec.state)) ?? undefined,
      tone: surecTuru === "ISTEN_AYRILMA" ? "danger" : "default",
      sortValue: createTimelineSortValue(tarih),
      sortRank: surecTuru === "ISTEN_AYRILMA" ? 0 : 1
    });
  }

  for (const zimmet of zimmetler) {
    const teslimTarihi = normalizeTimelineText(zimmet.teslim_tarihi);
    const urun = formatZimmetUrunTuruLabel(zimmet.urun_turu);
    const teslimDurumu = formatZimmetTeslimDurumuLabel(zimmet.teslim_durumu);
    const teslimEden = normalizeTimelineText(zimmet.teslim_eden);
    const ortakAciklama = normalizeTimelineText(zimmet.aciklama) ?? undefined;

    if (teslimTarihi) {
      events.push({
        id: `zimmet-teslim-${zimmet.id}`,
        tarih: teslimTarihi,
        baslik: "Zimmet Teslim",
        kaynak: "Zimmet",
        ozet: [urun, teslimDurumu, teslimEden].filter((part): part is string => Boolean(part)).join(" / "),
        aciklama: ortakAciklama,
        etiket: normalizeTimelineText(formatZimmetKayitDurumuLabel(zimmet.zimmet_durumu)) ?? undefined,
        sortValue: createTimelineSortValue(teslimTarihi),
        sortRank: 2
      });
    }

    const iadeTarihi = normalizeTimelineText(zimmet.iade_tarihi);
    if (iadeTarihi) {
      events.push({
        id: `zimmet-iade-${zimmet.id}`,
        tarih: iadeTarihi,
        baslik: "Zimmet Iadesi",
        kaynak: "Zimmet",
        ozet: [urun, teslimEden].filter((part): part is string => Boolean(part)).join(" / "),
        aciklama: ortakAciklama,
        etiket: formatZimmetKayitDurumuLabel("IADE_EDILDI"),
        sortValue: createTimelineSortValue(iadeTarihi),
        sortRank: 3
      });
    }
  }

  return [...events].sort((left, right) => {
    if (right.sortValue !== left.sortValue) {
      return right.sortValue - left.sortValue;
    }

    if (left.sortRank !== right.sortRank) {
      return left.sortRank - right.sortRank;
    }

    return right.id.localeCompare(left.id, "tr");
  });
}

function DossierField({
  label,
  value,
  valueClassName
}: {
  label: string;
  value: string;
  valueClassName?: string;
}) {
  return (
    <div className="personel-dosya-field">
      <span className="personel-dosya-field-label">{label}</span>
      <strong className={valueClassName ?? "personel-dosya-field-value"}>{value}</strong>
    </div>
  );
}

function DossierRecord({
  label,
  value
}: {
  label: string;
  value: string;
}) {
  return (
    <div className="personel-dosya-record">
      <span className="personel-dosya-record-label">{label}</span>
      <span className="personel-dosya-record-value">{value}</span>
    </div>
  );
}

function DossierSection({
  title,
  description,
  children
}: {
  title: string;
  description?: string;
  children: ReactNode;
}) {
  return (
    <section className="personel-dosya-section">
      <div className="personel-dosya-section-head">
        <h3>{title}</h3>
        {description ? <p>{description}</p> : null}
      </div>
      <div className="personel-dosya-record-list">{children}</div>
    </section>
  );
}

function PersonelDosyaHero({
  personel
}: {
  personel: Personel;
}) {
  const durumLabel =
    personel.aktif_durum === "PASIF"
      ? formatDetailValue(personel.pasiflik_durumu_etiketi) !== "-"
        ? formatDetailValue(personel.pasiflik_durumu_etiketi)
        : formatAktifDurumLabel(personel.aktif_durum)
      : formatAktifDurumLabel(personel.aktif_durum);
  const sicil = formatDetailValue(personel.sicil_no);
  const departman = formatReferenceValue(personel.departman_adi, personel.departman_id);
  const gorev = formatReferenceValue(personel.gorev_adi, personel.gorev_id);
  const heroSummary = [sicil !== "-" ? `Sicil ${sicil}` : null, departman !== "-" ? departman : null, gorev !== "-" ? gorev : null]
    .filter((part): part is string => part != null)
    .join(" / ");

  return (
    <section className="personel-dosya-hero">
      <div className="personel-dosya-hero-head">
        <div className="personel-dosya-hero-copy">
          <p className="personel-dosya-kicker">Personel Dosyasi</p>
          <h3>
            {personel.ad} {personel.soyad}
          </h3>
          <p className="personel-dosya-sub">{heroSummary || "Kurumsal personel kaydi"}</p>
        </div>
      </div>

      <div className="personel-dosya-hero-grid">
        <DossierField label="Ad" value={personel.ad} />
        <DossierField label="Soyad" value={personel.soyad} />
        <DossierField label="Sicil No" value={formatDetailValue(personel.sicil_no)} />
        <DossierField label="Departman / Birim" value={formatReferenceValue(personel.departman_adi, personel.departman_id)} />
        <DossierField label="Gorev / Unvan" value={formatReferenceValue(personel.gorev_adi, personel.gorev_id)} />
        <DossierField
          label="Calisma Durumu"
          value={durumLabel}
          valueClassName={
            personel.aktif_durum === "PASIF"
              ? "personel-dosya-field-value personel-dosya-field-value--danger"
              : "personel-dosya-field-value"
          }
        />
        <DossierField label="Ise Giris Tarihi" value={formatDetailValue(personel.ise_giris_tarihi)} />
      </div>
    </section>
  );
}

function PersonelDosyaActionRow({
  canEditPersonel,
  canAccessSurecler,
  canCreateSurec,
  isActionMenuOpen,
  onToggleActionMenu,
  onCloseActionMenu,
  onStartEdit,
  onOpenSurecModal,
  onOpenSurecHistory
}: {
  canEditPersonel: boolean;
  canAccessSurecler: boolean;
  canCreateSurec: boolean;
  isActionMenuOpen: boolean;
  onToggleActionMenu: () => void;
  onCloseActionMenu: () => void;
  onStartEdit: () => void;
  onOpenSurecModal: () => void;
  onOpenSurecHistory: () => void;
}) {
  const actionItems = useMemo(() => {
    const items: Array<{ id: string; label: string; onSelect: () => void }> = [];

    if (canCreateSurec) {
      items.push({
        id: "surec-ekle",
        label: "Surec Ekle",
        onSelect: () => {
          onCloseActionMenu();
          onOpenSurecModal();
        }
      });
    } else if (canAccessSurecler) {
      items.push({
        id: "surec-gecmisi",
        label: "Surec Gecmisini Ac",
        onSelect: () => {
          onCloseActionMenu();
          onOpenSurecHistory();
        }
      });
    }

    if (canEditPersonel) {
      items.push({
        id: "duzenle",
        label: "Karti Duzenle",
        onSelect: () => {
          onCloseActionMenu();
          onStartEdit();
        }
      });
    }

    return items;
  }, [canAccessSurecler, canCreateSurec, canEditPersonel, onCloseActionMenu, onOpenSurecHistory, onOpenSurecModal, onStartEdit]);

  if (actionItems.length === 0) {
    return null;
  }

  return (
    <div className="personel-dosya-actions-row">
      <div className="personel-dosya-actions-spacer" aria-hidden="true" />
      <div className="personel-dosya-action-host">
        <button
          type="button"
          className="universal-btn-aux"
          onClick={onToggleActionMenu}
          aria-expanded={isActionMenuOpen}
        >
          Islemler
        </button>
        <div className={`settings-dropdown personel-dosya-action-menu${isActionMenuOpen ? " open" : ""}`}>
          {actionItems.map((item) => (
            <button key={item.id} type="button" onClick={item.onSelect}>
              {item.label}
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}

function PersonelKartPanelGenelBilgiler({ personel }: { personel: Personel }) {
  return (
    <div className="personel-dosya-sections">
      <DossierSection
        title="Kimlik ve Iletisim"
        description="Temel kimlik, iletisim ve lokasyon verileri bu dosyada salt okunur izlenir."
      >
        <DossierRecord label="T.C. Kimlik No" value={formatDetailValue(personel.tc_kimlik_no)} />
        <DossierRecord label="Telefon" value={formatDetailValue(personel.telefon)} />
        <DossierRecord label="Dogum Tarihi" value={formatDetailValue(personel.dogum_tarihi)} />
        <DossierRecord label="Dogum Yeri" value={formatDetailValue(personel.dogum_yeri)} />
        <DossierRecord label="Kan Grubu" value={formatDetailValue(personel.kan_grubu)} />
        <DossierRecord label="Sube" value={formatReferenceValue(personel.sube_adi, personel.sube_id)} />
      </DossierSection>

      <DossierSection
        title="Organizasyon ve Acil Durum"
        description="Bagli organizasyon, yonetim hatti ve acil durum bilgileri burada tutulur."
      >
        <DossierRecord
          label="Personel Tipi"
          value={formatReferenceValue(personel.personel_tipi_adi, personel.personel_tipi_id)}
        />
        <DossierRecord label="Bagli Amir" value={formatReferenceValue(personel.bagli_amir_adi, personel.bagli_amir_id)} />
        <DossierRecord label="Acil Durum Kisisi" value={formatDetailValue(personel.acil_durum_kisi)} />
        <DossierRecord label="Acil Durum Telefonu" value={formatDetailValue(personel.acil_durum_telefon)} />
        <DossierRecord label="Pasiflik Etiketi" value={formatDetailValue(personel.pasiflik_durumu_etiketi)} />
      </DossierSection>
    </div>
  );
}

function PersonelPuantajPanel({
  personel,
  canViewPuantaj
}: {
  personel: Personel;
  canViewPuantaj: boolean;
}) {
  const sgkPrimGunu = typeof personel.sgk_prim_gun === "number" ? `${personel.sgk_prim_gun} Gun` : "-";
  const eksikGun = typeof personel.sgk_eksik_gun_sayisi === "number" ? `${personel.sgk_eksik_gun_sayisi} Gun` : "-";
  const takvimGun = typeof personel.sgk_ayin_takvim_gun_sayisi === "number" ? `${personel.sgk_ayin_takvim_gun_sayisi} Gun` : "-";
  const donem = formatDetailValue(personel.sgk_donem);
  const hesaplamaModu = formatSgkHesaplamaModuLabel(personel.sgk_hesaplama_modu);

  return (
    <div className="personel-dosya-sections">
      <section className="personel-puantaj-summary-card" data-testid="personel-sgk-prim-gun-card">
        <span className="personel-puantaj-summary-kicker">SGK Prim Gunu</span>
        <strong className="personel-puantaj-summary-value" data-testid="personel-sgk-prim-gun">
          {sgkPrimGunu}
        </strong>
        <p className="personel-puantaj-summary-note">
          Aylik puantajdan turetilen resmi prim gunu ozeti burada read-only izlenir.
        </p>
      </section>

      <DossierSection
        title="Aylik Puantaj Ozeti"
        description="Bu dosya SGK prim gunu, eksik gun ve hesaplama modunu tek bakista gosterir."
      >
        <DossierRecord label="Donem" value={donem} />
        <DossierRecord label="SGK Prim Gunu" value={sgkPrimGunu} />
        <DossierRecord label="Eksik Gun" value={eksikGun} />
        <DossierRecord label="Takvim Gun Sayisi" value={takvimGun} />
        <DossierRecord label="Hesaplama Modu" value={hesaplamaModu} />
      </DossierSection>

      <PlaceholderPanel
        title="Gunluk Puantaj Dosyasi"
        description="Gunluk giris-cikis kayitlari ve detayli puantaj duzenleme akisi ayri puantaj ekraninda kalir."
        actionLabel="Puantaj ekranina git"
        actionTo="/puantaj"
        actionState={{ prefillPersonelId: personel.id }}
        canOpen={canViewPuantaj}
        noPermissionMessage="Puantaj goruntuleme yetkiniz yok."
      />
    </div>
  );
}

function PlaceholderPanel({
  title,
  description,
  actionLabel,
  actionTo,
  actionState,
  canOpen,
  noPermissionMessage
}: {
  title: string;
  description: string;
  actionLabel?: string;
  actionTo?: string;
  actionState?: Record<string, unknown>;
  canOpen?: boolean;
  noPermissionMessage?: string;
}) {
  return (
    <div className="personel-kart-placeholder">
      <h3>{title}</h3>
      <p>{description}</p>
      {actionLabel && actionTo ? (
        canOpen ? (
          <Link to={actionTo} state={actionState} className="universal-btn-aux">
            {actionLabel}
          </Link>
        ) : (
          <p className="personel-kart-placeholder-note">{noPermissionMessage ?? "Bu alani goruntuleme yetkiniz yok."}</p>
        )
      ) : (
        <p className="personel-kart-placeholder-note">Icerik bir sonraki keside baglanacak.</p>
      )}
    </div>
  );
}

function PersonelIzinDevamsizlikPanel({
  personel,
  surecler
}: {
  personel: Personel;
  surecler: Surec[];
}) {
  const bakiye = useMemo(() => {
    if (!personel.ise_giris_tarihi) return null;
    return hesaplaIzinBakiye(
      {
        ise_giris_tarihi: personel.ise_giris_tarihi,
        dogum_tarihi: personel.dogum_tarihi
      },
      surecler
    );
  }, [personel.ise_giris_tarihi, personel.dogum_tarihi, surecler]);

  const izinSurecleri = useMemo(
    () =>
      surecler.filter(
        (s) => s.surec_turu === "IZIN" && s.state !== "IPTAL"
      ),
    [surecler]
  );

  return (
    <div
      id="personel-kart-panel-izin-devamsizlik"
      role="tabpanel"
      className="personel-kart-panel"
      aria-labelledby="personel-kart-tab-izin-devamsizlik"
    >
      <div className="personel-detail-grid">
        <section className="personel-detail-section">
          <h3>Izin Hakki</h3>
          {bakiye ? (
            <div className="personel-izin-infobox" data-testid="izin-bakiye-infobox">
              <p>
                <strong>Kidem:</strong> {bakiye.hak_edis.kidem_yil} yil
              </p>
              {bakiye.hak_edis.yas !== null ? (
                <p>
                  <strong>Yas:</strong> {bakiye.hak_edis.yas}
                </p>
              ) : null}
              <p>
                <strong>Yillik Izin Hakki:</strong> {bakiye.hak_edis.yillik_izin_gun} gun
                {bakiye.hak_edis.yas_istisna_uygulandi ? (
                  <span className="personel-izin-istisna-badge"> (50 yas istisnasi)</span>
                ) : null}
              </p>
              <p>
                <strong>Kullanilan:</strong> {bakiye.kullanilan_gun} gun
              </p>
              <p className="personel-izin-kalan">
                <strong>Kalan Izin:</strong> {bakiye.kalan_gun} gun
              </p>
            </div>
          ) : (
            <p>Ise giris tarihi bilgisi eksik; izin hakki hesaplanamadi.</p>
          )}
        </section>

        <section className="personel-detail-section">
          <h3>Izin Hareketleri</h3>
          {izinSurecleri.length === 0 ? (
            <p>Kayitli izin hareketi bulunamadi.</p>
          ) : (
            <ul className="personel-surec-list personel-izin-list" data-testid="izin-hareket-listesi">
              {izinSurecleri.map((surec) => (
                <li key={surec.id} className="personel-surec-card">
                  <span className="personel-surec-card-type">
                    {formatSurecTuruLabel(surec.surec_turu)}
                    {surec.alt_tur ? ` / ${surec.alt_tur}` : ""}
                  </span>
                  <span className="personel-surec-card-state">{formatSurecStateLabel(surec.state)}</span>
                  <span className="personel-surec-card-dates">
                    Baslangic: {formatDetailValue(surec.baslangic_tarihi)}
                    {surec.bitis_tarihi ? ` | Bitis: ${surec.bitis_tarihi}` : ""}
                  </span>
                  {surec.aciklama ? (
                    <span className="personel-surec-card-desc">{surec.aciklama}</span>
                  ) : null}
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
    </div>
  );
}

function PersonelSurecGecmisiPanel({
  personel,
  canAccessSurecler,
  canCreateSurec,
  isLoading,
  errorMessage,
  surecler,
  zimmetler,
  onOpenCreateModal
}: {
  personel: Personel;
  canAccessSurecler: boolean;
  canCreateSurec: boolean;
  isLoading: boolean;
  errorMessage: string | null;
  surecler: Surec[];
  zimmetler: Zimmet[];
  onOpenCreateModal: () => void;
}) {
  const timeline = useMemo(
    () => buildPersonelTimeline(personel, surecler, zimmetler),
    [personel, surecler, zimmetler]
  );

  if (!canAccessSurecler) {
    return (
      <div className="personel-kart-placeholder">
        <h3>Surec Gecmisi</h3>
        <p>Bu dosya yalnizca surec goruntuleme yetkisi olan kullanicilar icin acilir.</p>
      </div>
    );
  }

  return (
    <div className="personel-surec-history">
      <div className="personel-surec-history-head">
        <div>
          <h3>Surec Gecmisi</h3>
          <p>Personelin tum surec hareketleri kronolojik olay gunlugu olarak izlenir.</p>
        </div>
        {canCreateSurec ? (
          <button type="button" className="universal-btn-aux" onClick={onOpenCreateModal}>
            Surec Ekle
          </button>
        ) : null}
      </div>

      {isLoading ? <p className="personel-kart-placeholder-note">Surec gecmisi yukleniyor...</p> : null}
      {!isLoading && errorMessage ? <p className="personel-create-error">{errorMessage}</p> : null}
      {!isLoading && !errorMessage && timeline.length === 0 ? (
        <div className="personel-kart-placeholder">
          <h3>Kayit Bulunamadi</h3>
          <p>Bu personel icin henuz kronolojik olay kaydi bulunmuyor.</p>
        </div>
      ) : null}

      {!isLoading && !errorMessage && timeline.length > 0 ? (
        <ol className="personel-timeline" data-testid="personel-surec-timeline">
          {timeline.map((event) => (
            <li
              key={event.id}
              className={`personel-timeline-item${event.tone === "danger" ? " is-danger" : ""}`}
            >
              <div className="personel-timeline-marker" aria-hidden="true" />
              <div className="personel-timeline-body">
                <div className="personel-timeline-head">
                  <strong>{event.baslik}</strong>
                  {event.etiket ? <span className="personel-surec-state">{event.etiket}</span> : null}
                </div>
                <div className="personel-timeline-meta">
                  <span>{event.tarih ?? "-"}</span>
                  <span>{event.kaynak}</span>
                </div>
                <p className="personel-timeline-summary">{event.ozet}</p>
                {event.aciklama ? (
                  <p className="personel-timeline-note">{event.aciklama}</p>
                ) : null}
              </div>
            </li>
          ))}
        </ol>
      ) : null}
    </div>
  );
}

function PersonelZimmetEnvanterPanel({
  canCreateZimmet,
  isLoading,
  errorMessage,
  zimmetler,
  onOpenCreateModal
}: {
  canCreateZimmet: boolean;
  isLoading: boolean;
  errorMessage: string | null;
  zimmetler: Zimmet[];
  onOpenCreateModal: () => void;
}) {
  return (
    <div className="personel-zimmet-panel">
      <div className="personel-zimmet-head">
        <div>
          <h3>Zimmet ve Envanter Kayitlari</h3>
          <p>Kullaniciya teslim edilen ekipmanlar ve geri alinmis kayitlar bu listede izlenir.</p>
        </div>
        {canCreateZimmet ? (
          <button type="button" className="universal-btn-aux" onClick={onOpenCreateModal}>
            Yeni Zimmet Ekle
          </button>
        ) : null}
      </div>

      {isLoading ? <p className="personel-kart-placeholder-note">Zimmet kayitlari yukleniyor...</p> : null}
      {!isLoading && errorMessage ? <p className="personel-create-error">{errorMessage}</p> : null}

      {!isLoading && !errorMessage && zimmetler.length === 0 ? (
        <div className="personel-kart-placeholder">
          <h3>Zimmet Kaydi Bulunamadi</h3>
          <p>Bu personel icin henuz zimmetlenmis urun kaydi bulunmuyor.</p>
        </div>
      ) : null}

      {!isLoading && !errorMessage && zimmetler.length > 0 ? (
        <div className="personel-zimmet-table-wrap">
          <table className="personel-zimmet-table">
            <thead>
              <tr>
                <th>Urun Turu</th>
                <th>Teslim Tarihi</th>
                <th>Teslim Eden</th>
                <th>Teslim Durumu</th>
                <th>Kayit Durumu</th>
                <th>Seri No / Aciklama</th>
              </tr>
            </thead>
            <tbody>
              {zimmetler.map((zimmet) => (
                <tr key={zimmet.id}>
                  <td className="personel-zimmet-cell-strong">{formatZimmetUrunTuruLabel(zimmet.urun_turu)}</td>
                  <td>{formatDetailValue(zimmet.teslim_tarihi)}</td>
                  <td>{formatDetailValue(zimmet.teslim_eden)}</td>
                  <td>{formatZimmetTeslimDurumuLabel(zimmet.teslim_durumu)}</td>
                  <td>
                    <span
                      className={`personel-zimmet-state${zimmet.zimmet_durumu === "IADE_EDILDI" ? " is-returned" : ""}`}
                    >
                      {formatZimmetKayitDurumuLabel(zimmet.zimmet_durumu)}
                    </span>
                  </td>
                  <td className="personel-zimmet-note-cell">{formatDetailValue(zimmet.aciklama)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </div>
  );
}

export function PersonelDetayPage() {
  const { personelId } = useParams();
  const parsedPersonelId = Number.parseInt(personelId ?? "", 10);
  const hasValidId = !Number.isNaN(parsedPersonelId) && parsedPersonelId > 0;
  const { hasPermission } = useRoleAccess();
  const canEditPersonel = hasPermission("personeller.update");
  const canCreateSurec = hasPermission("surecler.create");
  const canViewSurecler = hasPermission("surecler.view") || hasPermission("surecler.view.sube");
  const canAccessSurecler = canCreateSurec || canViewSurecler;
  const canViewPuantaj = hasPermission("puantaj.view");
  const canCreateZimmet = canEditPersonel;

  const [activeTab, setActiveTab] = useState<PersonelDosyaTabId>("genel-bilgiler");
  const [isActionMenuOpen, setIsActionMenuOpen] = useState(false);

  const {
    personel,
    isLoading,
    errorMessage,
    refetch,
    isEditing,
    setIsEditing,
    isSubmitting,
    editErrorMessage,
    editForm,
    setEditForm,
    discardEdit,
    updatePersonelHandler,
    isSurecModalOpen,
    openSurecModal,
    closeSurecModal,
    surecForm,
    setSurecForm,
    createSurecHandler,
    isSurecSubmitting,
    surecCreateErrorMessage,
    surecHistory,
    isSurecHistoryLoading,
    surecHistoryErrorMessage,
    surecTuruOptions,
    surecReferenceErrorMessage,
    isZimmetModalOpen,
    openZimmetModal,
    closeZimmetModal,
    zimmetForm,
    setZimmetForm,
    createZimmetHandler,
    isZimmetSubmitting,
    zimmetCreateErrorMessage,
    zimmetHistory,
    isZimmetHistoryLoading,
    zimmetHistoryErrorMessage
  } = usePersonelDetail(parsedPersonelId, hasValidId, {
    canViewSurecler,
    canCreateSurec,
    canCreateZimmet
  });

  useEffect(() => {
    setActiveTab("genel-bilgiler");
    setIsActionMenuOpen(false);
  }, [parsedPersonelId]);

  useEffect(() => {
    if (isEditing || isSurecModalOpen || isZimmetModalOpen) {
      setIsActionMenuOpen(false);
    }
  }, [isEditing, isSurecModalOpen, isZimmetModalOpen]);

  function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    void updatePersonelHandler(event, canEditPersonel);
  }

  function handleSurecCreateSubmit(event: FormEvent<HTMLFormElement>) {
    void createSurecHandler(event);
  }

  function handleZimmetCreateSubmit(event: FormEvent<HTMLFormElement>) {
    void createZimmetHandler(event);
  }

  function handleOpenSurecModal() {
    setActiveTab("surec-gecmisi");
    openSurecModal();
  }

  function handleOpenSurecHistory() {
    setActiveTab("surec-gecmisi");
  }

  function handleOpenZimmetModal() {
    setActiveTab("zimmet-envanter");
    openZimmetModal();
  }

  const pageHeading =
    personel != null ? `${personel.ad} ${personel.soyad} personel dosyasi` : "Personel detayi";

  return (
    <section className="personel-detay-page personel-dosya-page" aria-label={pageHeading}>
      <h2 className="personeller-sr-only">{pageHeading}</h2>

      {isLoading ? <LoadingState label="Personel dosyasi yukleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void refetch()} />
      ) : null}

      {!isLoading && !errorMessage && !personel ? (
        <EmptyState title="Personel bulunamadi" message="Belirtilen ID ile kayit bulunamadi." />
      ) : null}

      {!isLoading && !errorMessage && personel ? (
        <div className="personel-detail-card">
          <PersonelDosyaHero personel={personel} />

          {!isEditing ? (
            <PersonelDosyaActionRow
              canEditPersonel={canEditPersonel}
              canAccessSurecler={canAccessSurecler}
              canCreateSurec={canCreateSurec}
              isActionMenuOpen={isActionMenuOpen}
              onToggleActionMenu={() => setIsActionMenuOpen((prev) => !prev)}
              onCloseActionMenu={() => setIsActionMenuOpen(false)}
              onStartEdit={() => setIsEditing(true)}
              onOpenSurecModal={handleOpenSurecModal}
              onOpenSurecHistory={handleOpenSurecHistory}
            />
          ) : null}

          {isEditing ? (
            <form className="personel-edit-form" onSubmit={handleEditSubmit}>
              <div className="form-field-grid">
                <FormField
                  label="Ad"
                  name="edit-ad"
                  value={editForm.ad}
                  onChange={(value) => setEditForm((prev) => ({ ...prev, ad: value }))}
                  required
                />
                <FormField
                  label="Soyad"
                  name="edit-soyad"
                  value={editForm.soyad}
                  onChange={(value) => setEditForm((prev) => ({ ...prev, soyad: value }))}
                  required
                />
                <FormField
                  label="Telefon"
                  name="edit-telefon"
                  type="tel"
                  value={editForm.telefon}
                  onChange={(value) => setEditForm((prev) => ({ ...prev, telefon: value }))}
                />
              </div>

              {editErrorMessage ? <p className="personel-create-error">{editErrorMessage}</p> : null}

              <div className="universal-btn-group">
                <button type="submit" className="universal-btn-save" disabled={isSubmitting}>
                  {isSubmitting ? "Kaydediliyor..." : "Kaydet"}
                </button>
                <button type="button" className="universal-btn-cancel" onClick={discardEdit} disabled={isSubmitting}>
                  Vazgec
                </button>
              </div>
            </form>
          ) : (
            <>
              <div className="personel-kart-tablist" role="tablist" aria-label="Personel dosyasi sekmeleri">
                {PERSONEL_DOSYA_TABS.map((tab) => (
                  <button
                    key={tab.id}
                    type="button"
                    role="tab"
                    id={`personel-kart-tab-${tab.id}`}
                    className={`personel-kart-tab${activeTab === tab.id ? " is-active" : ""}`}
                    aria-selected={activeTab === tab.id}
                    aria-controls={`personel-kart-panel-${tab.id}`}
                    tabIndex={activeTab === tab.id ? 0 : -1}
                    onClick={() => setActiveTab(tab.id)}
                  >
                    {tab.label}
                  </button>
                ))}
              </div>

              <div
                id="personel-kart-panel-genel-bilgiler"
                role="tabpanel"
                className="personel-kart-panel"
                aria-labelledby="personel-kart-tab-genel-bilgiler"
                hidden={activeTab !== "genel-bilgiler"}
              >
                <PersonelKartPanelGenelBilgiler personel={personel} />
              </div>

              <div
                id="personel-kart-panel-puantaj"
                role="tabpanel"
                className="personel-kart-panel"
                aria-labelledby="personel-kart-tab-puantaj"
                hidden={activeTab !== "puantaj"}
              >
                <PersonelPuantajPanel personel={personel} canViewPuantaj={canViewPuantaj} />
              </div>

              <PersonelIzinDevamsizlikPanel
                personel={personel}
                surecler={surecHistory}
              />

              <div
                id="personel-kart-panel-zimmet-envanter"
                role="tabpanel"
                className="personel-kart-panel"
                aria-labelledby="personel-kart-tab-zimmet-envanter"
                hidden={activeTab !== "zimmet-envanter"}
              >
                <PersonelZimmetEnvanterPanel
                  canCreateZimmet={canCreateZimmet}
                  isLoading={isZimmetHistoryLoading}
                  errorMessage={zimmetHistoryErrorMessage}
                  zimmetler={zimmetHistory}
                  onOpenCreateModal={handleOpenZimmetModal}
                />
              </div>

              <div
                id="personel-kart-panel-surec-gecmisi"
                role="tabpanel"
                className="personel-kart-panel"
                aria-labelledby="personel-kart-tab-surec-gecmisi"
                hidden={activeTab !== "surec-gecmisi"}
              >
                <PersonelSurecGecmisiPanel
                  personel={personel}
                  canAccessSurecler={canAccessSurecler}
                  canCreateSurec={canCreateSurec}
                  isLoading={isSurecHistoryLoading}
                  errorMessage={surecHistoryErrorMessage}
                  surecler={surecHistory}
                  zimmetler={zimmetHistory}
                  onOpenCreateModal={handleOpenSurecModal}
                />
              </div>
            </>
          )}
        </div>
      ) : null}

      {personel && canCreateSurec && isSurecModalOpen ? (
        <AppModal
          title="Surec Ekle"
          onClose={closeSurecModal}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={PERSONEL_SUREC_FORM_ID}
                className="universal-btn-save"
                disabled={isSurecSubmitting}
              >
                {isSurecSubmitting ? "Kaydediliyor..." : "Kaydet"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={closeSurecModal}
                disabled={isSurecSubmitting}
              >
                Vazgec
              </button>
            </div>
          }
        >
          <form id={PERSONEL_SUREC_FORM_ID} className="personel-surec-form-grid" onSubmit={handleSurecCreateSubmit}>
            {surecTuruOptions.length > 0 ? (
              <FormField
                as="select"
                label="Surec Turu"
                name="personel-surec-turu"
                value={surecForm.surecTuru}
                onChange={(value) => setSurecForm((prev) => ({ ...prev, surecTuru: value }))}
                required
                placeholderOption={{ value: "", label: "Seciniz" }}
                selectOptions={keyOptionsToSelectOptions(surecTuruOptions)}
              />
            ) : (
              <FormField
                label="Surec Turu"
                name="personel-surec-turu-text"
                value={surecForm.surecTuru}
                onChange={(value) => setSurecForm((prev) => ({ ...prev, surecTuru: value }))}
                required
                placeholder="IZIN, RAPOR, ISTEN_AYRILMA"
              />
            )}
            <FormField
              label="Baslangic Tarihi"
              name="personel-surec-baslangic"
              type="date"
              value={surecForm.baslangicTarihi}
              onChange={(value) => setSurecForm((prev) => ({ ...prev, baslangicTarihi: value }))}
              required
            />
            <FormField
              label="Bitis Tarihi"
              name="personel-surec-bitis"
              type="date"
              value={surecForm.bitisTarihi}
              onChange={(value) => setSurecForm((prev) => ({ ...prev, bitisTarihi: value }))}
            />
            <FormField
              as="textarea"
              label="Aciklama"
              name="personel-surec-aciklama"
              value={surecForm.aciklama}
              onChange={(value) => setSurecForm((prev) => ({ ...prev, aciklama: value }))}
              rows={4}
            />
            {surecCreateErrorMessage ? <p className="personel-create-error">{surecCreateErrorMessage}</p> : null}
            {surecReferenceErrorMessage ? <p className="personel-create-error">{surecReferenceErrorMessage}</p> : null}
          </form>
        </AppModal>
      ) : null}

      {personel && canCreateZimmet && isZimmetModalOpen ? (
        <AppModal
          title="Yeni Zimmet Ekle"
          onClose={closeZimmetModal}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={PERSONEL_ZIMMET_FORM_ID}
                className="universal-btn-save"
                disabled={isZimmetSubmitting}
              >
                {isZimmetSubmitting ? "Kaydediliyor..." : "Kaydet"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={closeZimmetModal}
                disabled={isZimmetSubmitting}
              >
                Vazgec
              </button>
            </div>
          }
        >
          <form id={PERSONEL_ZIMMET_FORM_ID} className="personel-zimmet-form-grid" onSubmit={handleZimmetCreateSubmit}>
            <FormField
              as="select"
              label="Urun Turu"
              name="personel-zimmet-urun-turu"
              value={zimmetForm.urunTuru}
              onChange={(value) => setZimmetForm((prev) => ({ ...prev, urunTuru: value }))}
              required
              placeholderOption={{ value: "", label: "Seciniz" }}
              selectOptions={[...ZIMMET_URUN_TURU_OPTIONS]}
            />
            <FormField
              label="Teslim Tarihi"
              name="personel-zimmet-teslim-tarihi"
              type="date"
              value={zimmetForm.teslimTarihi}
              onChange={(value) => setZimmetForm((prev) => ({ ...prev, teslimTarihi: value }))}
              required
            />
            <FormField
              label="Teslim Eden"
              name="personel-zimmet-teslim-eden"
              value={zimmetForm.teslimEden}
              onChange={(value) => setZimmetForm((prev) => ({ ...prev, teslimEden: value }))}
              required
              placeholder="Birim Amiri veya IK gorevlisi"
            />
            <FormField
              as="select"
              label="Teslim Durumu"
              name="personel-zimmet-teslim-durumu"
              value={zimmetForm.teslimDurumu}
              onChange={(value) => setZimmetForm((prev) => ({ ...prev, teslimDurumu: value }))}
              required
              selectOptions={[...ZIMMET_TESLIM_DURUMU_OPTIONS]}
            />
            <FormField
              as="textarea"
              label="Seri No / Aciklama"
              name="personel-zimmet-aciklama"
              value={zimmetForm.aciklama}
              onChange={(value) => setZimmetForm((prev) => ({ ...prev, aciklama: value }))}
              rows={4}
            />
            {zimmetCreateErrorMessage ? <p className="personel-create-error">{zimmetCreateErrorMessage}</p> : null}
          </form>
        </AppModal>
      ) : null}
    </section>
  );
}
