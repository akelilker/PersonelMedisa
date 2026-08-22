import type { SgkKatalogBlocker } from "../../api/sgk-katalog-hazirlik.api";
import { formatSurecStateLabel } from "./enum-display";
import {
  formatSgkBlockerDisplayText,
  formatSgkEvetHayirLabel,
  formatSgkImportUygunlukLabel,
  formatSgkKanitKoduLabel,
  formatSgkTamlikDurumuLabel
} from "./sgk-display";

const FIELD_LABELS: Record<string, string> = {
  kanit_turu: "Kanıt Türü",
  mevzuat_kaynagi_mi: "Mevzuat Kaynağı",
  katalog_tamligi_icin_tek_basina_yeterli_mi: "Katalog Tamlığı İçin Tek Başına Yeterli",
  gecerli_mi: "Geçerli",
  current_state: "Mevcut Durum",
  action: "İşlem",
  next_state: "Sonraki Durum",
  allowed_mi: "İzin Verildi",
  yazma_aktif_mi: "Yazma Aktif",
  preview_modu: "Önizleme Modu",
  hesap_sonucu_uretildi_mi: "Hesap Sonucu Üretildi",
  saat_bol_7_5_kullanildi_mi: "7,5 Saat Bölümü Kullanıldı",
  aktif_edildi_mi: "Aktif Edildi",
  varsayilan_15_14_uygulandi_mi: "Varsayılan 15–14 Uygulandı",
  kodlar_normalize: "Normalize Kodlar",
  sonuc_eksik_gun_kodu: "Sonuç Eksik Gün Kodu",
  seed_matrisi_var_mi: "Seed Matrisi Mevcut",
  surec_turu: "Süreç Türü",
  alt_tur: "Alt Tür",
  esleme_sayisi: "Eşleme Sayısı",
  esleme_modu: "Eşleme Modu",
  seed_var_mi: "Seed Mevcut",
  state: "Durum",
  id: "Kayıt No",
  supersedes_id: "Önceki Kayıt",
  idempotent_mi: "Yinelenen İstek",
  surum_kodu: "Sürüm Kodu",
  message: "Açıklama",
  personel_sayisi: "Personel Sayısı",
  ucret_segment_sayisi: "Ücret Segmenti Sayısı",
  puantaj_kayit_sayisi: "Puantaj Kayıt Sayısı",
  izin_kayit_sayisi: "İzin Kayıt Sayısı",
  etki_aday_sayisi: "Etki Adayı Sayısı",
  finans_kalem_sayisi: "Finans Kalem Sayısı",
  mevzuat_parametre_sayisi: "Mevzuat Parametre Sayısı",
  tamlik_durumu: "Tamlık Durumu",
  onaylanabilir_mi: "Onaylanabilir",
  import_yapilabilir_mi: "İçe Aktarmaya Uygun",
  apply_yapilabilir_mi: "Uygulamaya Uygun",
  yazma_endpoint_aktif_mi: "Yazma Uç Noktası Aktif",
  generated_at: "Oluşturulma",
  revision_no: "Revizyon No",
  personel_id: "Personel No",
  source_changed: "Kaynak Değişti"
};

const GIRDI_OZET_LABELS: Record<string, string> = {
  PERSONEL: "Personel",
  UCRET: "Ücret",
  PUANTAJ: "Puantaj",
  FINANS: "Finans",
  MEVZUAT: "Mevzuat",
  MUHUR: "Mühür",
  IZIN: "İzin",
  ETKI_ADAY: "Etki Adayı"
};

const HASH_FIELD_KEYS = new Set([
  "response_hash",
  "payload_hash",
  "esleme_payload_hash",
  "politika_hash",
  "manifest_set_hash",
  "source_hash",
  "snapshot_hash",
  "preflight_hash"
]);

const SKIP_FIELD_KEYS = new Set([
  "blocker_kodlari",
  "blocker_detaylari",
  "canonical_payload",
  "hatali_satirlar",
  "warnings",
  "hashes",
  "schema_version",
  "contract_version",
  "existing_snapshot",
  "tamlik",
  "code"
]);

function fieldLabel(key: string): string {
  return FIELD_LABELS[key] ?? GIRDI_OZET_LABELS[key] ?? "";
}

function formatScalarValue(key: string, value: unknown): string {
  if (value === null || value === undefined) {
    return "—";
  }
  if (typeof value === "boolean") {
    if (key.endsWith("_mi") && (key.includes("uygun") || key.includes("yapilabilir"))) {
      return formatSgkImportUygunlukLabel(value);
    }
    if (key.endsWith("_mi")) {
      return formatSgkEvetHayirLabel(value);
    }
    return formatSgkEvetHayirLabel(value);
  }
  if (typeof value === "number") {
    return String(value);
  }
  if (typeof value === "string") {
    if (HASH_FIELD_KEYS.has(key)) {
      return formatSgkKanitKoduLabel(value);
    }
    if (key === "tamlik_durumu") {
      return formatSgkTamlikDurumuLabel(value);
    }
    if (key === "state" || key.endsWith("_state") || key === "action" || key === "preview_modu" || key === "esleme_modu") {
      return formatSurecStateLabel(value);
    }
    if (key === "generated_at") {
      const date = new Date(value);
      return Number.isNaN(date.getTime()) ? value : date.toLocaleString("tr-TR");
    }
    return value.trim() || "—";
  }
  if (Array.isArray(value)) {
    if (value.length === 0) {
      return "Yok";
    }
    if (value.every((item) => typeof item === "string" || typeof item === "number")) {
      return value.map(String).join(", ");
    }
    return `${value.length} kayıt`;
  }
  return "—";
}

function isBlockerList(value: unknown): value is SgkKatalogBlocker[] {
  return (
    Array.isArray(value) &&
    value.length > 0 &&
    value.every(
      (item) =>
        item &&
        typeof item === "object" &&
        "code" in item &&
        typeof (item as SgkKatalogBlocker).code === "string"
    )
  );
}

function collectRows(data: Record<string, unknown>): Array<{ key: string; label: string; value: string }> {
  const rows: Array<{ key: string; label: string; value: string }> = [];

  for (const [key, value] of Object.entries(data)) {
    if (SKIP_FIELD_KEYS.has(key)) {
      continue;
    }
    if (key === "blocker_kodlari" && isBlockerList(data.blocker_detaylari)) {
      continue;
    }
    const label = HASH_FIELD_KEYS.has(key) ? "Doğrulama Kodu" : fieldLabel(key);
    if (!label) {
      continue;
    }
    if (value !== null && typeof value === "object" && !Array.isArray(value)) {
      continue;
    }
    rows.push({ key, label, value: formatScalarValue(key, value) });
  }

  return rows;
}

function BlockerOzeti({ items }: { items: SgkKatalogBlocker[] }) {
  if (items.length === 0) {
    return null;
  }
  return (
    <div className="api-sonuc-ozeti-blockers">
      <dt>Engeller</dt>
      <dd>
        <ul className="yonetim-list">
          {items.map((item) => (
            <li key={item.code + item.message}>
              {formatSgkBlockerDisplayText(item)}
              {item.cozum_onerisi ? <div className="muted">Çözüm: {item.cozum_onerisi}</div> : null}
            </li>
          ))}
        </ul>
      </dd>
    </div>
  );
}

export function ApiSonucOzeti(props: {
  data: Record<string, unknown>;
  testId?: string;
  title?: string;
}) {
  const rows = collectRows(props.data);
  const blockers = isBlockerList(props.data.blocker_detaylari) ? props.data.blocker_detaylari : [];
  const blockerCount =
    blockers.length === 0 && Array.isArray(props.data.blocker_kodlari) ? props.data.blocker_kodlari.length : 0;
  const nestedTamlik =
    props.data.tamlik && typeof props.data.tamlik === "object" && !Array.isArray(props.data.tamlik)
      ? (props.data.tamlik as Record<string, unknown>)
      : null;
  const nestedTamlikRows = nestedTamlik ? collectRows(nestedTamlik) : [];
  const description =
    typeof props.data.message === "string" && props.data.message.trim() ? props.data.message.trim() : null;

  if (rows.length === 0 && blockers.length === 0 && blockerCount === 0 && nestedTamlikRows.length === 0 && !description) {
    return (
      <p className="muted" data-testid={props.testId}>
        Özet bilgi yok.
      </p>
    );
  }

  return (
    <div className="api-sonuc-ozeti" data-testid={props.testId}>
      {props.title ? <h4>{props.title}</h4> : null}
      <dl className="sgk-islem-ozeti">
        {description ? (
          <div>
            <dt>Açıklama</dt>
            <dd>{description}</dd>
          </div>
        ) : null}
        {rows.map((row) => (
          <div key={row.key}>
            <dt>{row.label}</dt>
            <dd className={HASH_FIELD_KEYS.has(row.key) ? "bordro-mono--sm" : undefined}>{row.value}</dd>
          </div>
        ))}
        {blockerCount > 0 ? (
          <div>
            <dt>Engel Sayısı</dt>
            <dd>{blockerCount}</dd>
          </div>
        ) : null}
        {nestedTamlikRows.length > 0 ? (
          <div className="api-sonuc-ozeti-nested">
            <dt>Tamlık Özeti</dt>
            <dd>
              <dl className="sgk-islem-ozeti">
                {nestedTamlikRows.map((row) => (
                  <div key={`tamlik-${row.key}`}>
                    <dt>{row.label}</dt>
                    <dd className={HASH_FIELD_KEYS.has(row.key) ? "bordro-mono--sm" : undefined}>{row.value}</dd>
                  </div>
                ))}
              </dl>
            </dd>
          </div>
        ) : null}
        {blockers.length > 0 ? <BlockerOzeti items={blockers} /> : null}
      </dl>
    </div>
  );
}

export function GirdiOzetOzeti(props: { data: Record<string, number>; testId?: string }) {
  const rows = Object.entries(props.data)
    .map(([key, value]) => ({
      key,
      label: GIRDI_OZET_LABELS[key] ?? "",
      value
    }))
    .filter((row) => row.label);

  if (rows.length === 0) {
    return null;
  }

  return (
    <dl className="sgk-islem-ozeti" data-testid={props.testId}>
      {rows.map((row) => (
        <div key={row.key}>
          <dt>{row.label}</dt>
          <dd>{row.value}</dd>
        </div>
      ))}
    </dl>
  );
}

export function KaynakOzetOzeti(props: { data: Record<string, unknown>; testId?: string }) {
  return <ApiSonucOzeti data={props.data} testId={props.testId} />;
}
