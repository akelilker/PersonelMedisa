import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { fetchRevizyonCorrections } from "../../../../api/revizyon-correction.api";
import { fetchRevizyonTalepleri } from "../../../../api/revizyon-talebi.api";
import {
  useDevamPrimiEligibilityOzeti,
  type DevamPrimiEligibilityDurum
} from "../../../../hooks/useDevamPrimiEligibilityOzeti";
import {
  usePuantajEksikGunOzeti
} from "../../../../hooks/usePuantajEksikGunOzeti";
import type { Personel } from "../../../../types/personel";
import type {
  RevizyonCorrectionEvent,
  RevizyonCorrectionTipi
} from "../../../../types/revizyon-correction";
import type {
  RevizyonTalebi,
  RevizyonTalebiDurumu,
  RevizyonTipi
} from "../../../../types/revizyon-talebi";
import { DossierRecord, DossierSection } from "./personel-dosya-dossier";
import { PersonelFinansAdaylariSection } from "./PersonelFinansAdaylariSection";
import { PersonelBordroGatewaySection } from "./PersonelBordroGatewaySection";
import {
  computeFinansAdayToplamlari,
  formatFinansKayitTutar,
  hasFinansAdayToplami
} from "./personel-finans-adaylari-utils";
import { usePersonelFinansAdaylari } from "./usePersonelFinansAdaylari";
import {
  formatDateTimeDetail,
  formatDetailValue,
  formatNullableScalar,
  formatSgkHesaplamaModuLabel,
  timestampValue
} from "./personel-dosya-format-utils";
import { buildRevizyonTalebiCreatePath } from "../../../revizyon/revizyon-display";

const BORDRO_ADAY_OZETI_HENUZ_OLUSMADI =
  "Bu dönem için immutable SGK snapshot sonucu henüz oluşmadı.";
const BORDRO_ADAY_KALEM_GORMUNUYOR = "Bu ay bordroya yansıyacak aday kalem görünmüyor.";

function hasMesaiFinansKaydi(kayitlar: { kalem_turu: string; tutar: number; state?: string }[]): boolean {
  return kayitlar.some(
    (item) =>
      item.kalem_turu.trim().toUpperCase() === "MESAI" &&
      item.tutar > 0 &&
      (!item.state?.trim() || item.state.trim().toUpperCase() === "AKTIF")
  );
}

const REVIZYON_DURUM_LABELS: Record<RevizyonTalebiDurumu, string> = {
  TASLAK: "Taslak",
  ONAY_BEKLIYOR: "Onay bekliyor",
  ONAYLANDI: "Onaylandı",
  REDDEDILDI: "Reddedildi",
  IPTAL: "İptal"
};

const REVIZYON_TIPI_LABELS: Record<RevizyonTipi, string> = {
  PUANTAJ_GIRIS_CIKIS_DUZELTME: "Giriş / çıkış düzeltme",
  MOLA_DUZELTME: "Mola düzeltme",
  DEVAMSIZLIK_DUZELTME: "Devamsızlık düzeltme",
  SUREC_GEC_GIRIS: "Süreç geç giriş",
  SERBEST_ZAMAN_ETKI_DUZELTME: "Serbest zaman etki düzeltme",
  KAPANIS_HESAP_REVIZYONU: "Kapanış hesap revizyonu",
  BORDRO_ETKI_NOTU: "Bordro etki notu"
};

const REVIZYON_CORRECTION_TIPI_LABELS: Record<RevizyonCorrectionTipi, string> = {
  GIRIS_CIKIS_DUZELTME: "Giriş / çıkış düzeltme",
  MOLA_DUZELTME: "Mola düzeltme",
  DEVAMSIZLIK_DUZELTME: "Devamsızlık düzeltme",
  SERBEST_ZAMAN_ETKI_DUZELTME: "Serbest zaman etki düzeltme",
  KAPANIS_HESAP_REVIZYONU: "Kapanış hesap revizyonu",
  BORDRO_ETKI_NOTU: "Bordro etki notu"
};

function sortRevizyonTalepleriByLatest(items: RevizyonTalebi[]) {
  return [...items].sort(
    (left, right) => timestampValue(right.talep_zamani) - timestampValue(left.talep_zamani)
  );
}

function sortCorrectionsByLatest(items: RevizyonCorrectionEvent[]) {
  return [...items].sort(
    (left, right) => timestampValue(right.olusturma_zamani) - timestampValue(left.olusturma_zamani)
  );
}

function formatDevamPrimiDonemLabel(donem: string) {
  const match = donem.match(/^(\d{4})-(\d{2})$/);
  if (!match) {
    return donem;
  }

  const ayAdlari = [
    "Ocak",
    "Şubat",
    "Mart",
    "Nisan",
    "Mayıs",
    "Haziran",
    "Temmuz",
    "Ağustos",
    "Eylül",
    "Ekim",
    "Kasım",
    "Aralık"
  ];
  const ay = Number.parseInt(match[2], 10);
  const ayAdi = ayAdlari[ay - 1];
  return ayAdi ? `${ayAdi} ${match[1]}` : donem;
}

function devamPrimiDurumToneClass(durum: DevamPrimiEligibilityDurum) {
  if (durum === "kesildi") {
    return "is-kesildi";
  }
  if (durum === "manuel_inceleme") {
    return "is-manuel";
  }
  return "is-hak";
}

function formatRevizyonTalebiSummary(talep: RevizyonTalebi) {
  const durum = REVIZYON_DURUM_LABELS[talep.durum] ?? talep.durum;
  const tip = REVIZYON_TIPI_LABELS[talep.revizyon_tipi] ?? talep.revizyon_tipi;
  const eskiDeger = formatNullableScalar(talep.onceki_deger);
  const yeniDeger = formatNullableScalar(talep.talep_edilen_deger);
  const correction = talep.correction_event_id != null ? `Correction #${talep.correction_event_id}` : "Correction yok";
  const talepZamani = formatDateTimeDetail(talep.talep_zamani);

  return `${durum} / ${tip} / ${talep.etkilenen_tarih} / ${eskiDeger} -> ${yeniDeger} / ${correction} / ${talepZamani}`;
}

function formatRevizyonCorrectionSummary(correction: RevizyonCorrectionEvent) {
  const tip = REVIZYON_CORRECTION_TIPI_LABELS[correction.correction_tipi] ?? correction.correction_tipi;
  const eskiDeger = formatNullableScalar(correction.onceki_deger);
  const yeniDeger = formatNullableScalar(correction.yeni_deger);
  const durum = correction.iptal_edildi_mi ? "İptal" : "Aktif";
  const deltaParts = [
    correction.delta_dakika !== 0 ? `${correction.delta_dakika} dk` : null,
    correction.delta_gun !== 0 ? `${correction.delta_gun} gün` : null
  ].filter((part): part is string => part !== null);
  const delta = deltaParts.length > 0 ? deltaParts.join(", ") : "Delta yok";
  const olusturmaZamani = formatDateTimeDetail(correction.olusturma_zamani);

  return `${durum} / ${tip} / ${correction.etkilenen_tarih} / ${eskiDeger} -> ${yeniDeger} / ${delta} / ${olusturmaZamani}`;
}

function PersonelRevizyonCorrectionPanel({
  personelId,
  canViewRevizyon,
  canCreateRevizyon,
  isLoading,
  errorMessage,
  talepler,
  corrections
}: {
  personelId: number;
  canViewRevizyon: boolean;
  canCreateRevizyon: boolean;
  isLoading: boolean;
  errorMessage: string | null;
  talepler: RevizyonTalebi[];
  corrections: RevizyonCorrectionEvent[];
}) {
  const acikTalepSayisi = talepler.filter(
    (talep) => talep.durum === "TASLAK" || talep.durum === "ONAY_BEKLIYOR"
  ).length;
  const onayliTalepSayisi = talepler.filter((talep) => talep.durum === "ONAYLANDI").length;
  const aktifCorrectionSayisi = corrections.filter((correction) => !correction.iptal_edildi_mi).length;
  const sonTalepler = talepler.slice(0, 3);
  const sonCorrections = corrections.slice(0, 3);

  return (
    <DossierSection
      title="Revizyon / Correction İzleri"
      description="Kapalı dönem düzeltme talepleri ve üretilen correction etkileri. Ham snapshot değişmez; correction görünürlüğü rapor motoru overlay’i değildir."
    >
      {!canViewRevizyon ? (
        <DossierRecord label="Yetki" value="Revizyon kayıtlarını görüntüleme yetkiniz yok." />
      ) : null}

      {canViewRevizyon && isLoading ? <DossierRecord label="Durum" value="Yükleniyor..." /> : null}
      {canViewRevizyon && !isLoading && errorMessage ? (
        <DossierRecord label="Durum" value={errorMessage} />
      ) : null}

      {canViewRevizyon && !isLoading && !errorMessage ? (
        <>
          <DossierRecord label="Toplam Talep" value={String(talepler.length)} />
          <DossierRecord label="Açık Talep" value={String(acikTalepSayisi)} />
          <DossierRecord label="Onaylanan Talep" value={String(onayliTalepSayisi)} />
          <DossierRecord
            label="Aktif Correction"
            value={
              aktifCorrectionSayisi > 0
                ? `${aktifCorrectionSayisi} (aktif correction etiketi)`
                : "0"
            }
          />

          <div className="universal-btn-group" style={{ marginBottom: "0.75rem", flexWrap: "wrap" }}>
            <Link
              className="universal-btn-aux"
              to={`/haftalik-kapanis/revizyonlar?personel_id=${personelId}`}
              data-testid="personel-revizyon-tumunu-gor"
            >
              Tümünü Gör
            </Link>
            {canCreateRevizyon ? (
              <Link
                className="universal-btn-save"
                to={buildRevizyonTalebiCreatePath({ personel_id: personelId })}
                data-testid="personel-revizyon-talebi-ac"
              >
                Revizyon Talebi Aç
              </Link>
            ) : null}
          </div>

          {talepler.length === 0 && corrections.length === 0 ? (
            <DossierRecord label="Kayıt" value="Bu personel için revizyon veya correction kaydı yok." />
          ) : null}

          {sonTalepler.map((talep) => (
            <div key={`revizyon-talebi-${talep.id}`}>
              <DossierRecord label={`Talep #${talep.id}`} value={formatRevizyonTalebiSummary(talep)} />
              <Link
                className="universal-btn-aux"
                to={`/haftalik-kapanis/revizyonlar/${talep.id}`}
              >
                Talep detayına git
              </Link>
            </div>
          ))}

          {sonCorrections.map((correction) => (
            <div key={`revizyon-correction-${correction.id}`}>
              <DossierRecord
                label={`Correction #${correction.id}${correction.iptal_edildi_mi ? "" : " · Aktif"}`}
                value={formatRevizyonCorrectionSummary(correction)}
              />
              <Link
                className="universal-btn-aux"
                to={`/haftalik-kapanis/corrections/${correction.id}`}
              >
                Correction detayına git
              </Link>
            </div>
          ))}
        </>
      ) : null}
    </DossierSection>
  );
}

export function PersonelPuantajOzetSection({
  personel,
  canViewPuantaj,
  canViewRevizyon,
  canCreateRevizyon = false,
  canViewFinans,
  canViewBordro = false,
  isActive
}: {
  personel: Personel;
  canViewPuantaj: boolean;
  canViewRevizyon: boolean;
  canCreateRevizyon?: boolean;
  canViewFinans: boolean;
  canViewBordro?: boolean;
  isActive: boolean;
}) {
  const devamPrimiOzeti = useDevamPrimiEligibilityOzeti(personel);
  const puantajEksikGunOzeti = usePuantajEksikGunOzeti(personel, canViewBordro && isActive);
  const {
    finansKayitlari,
    isLoading: isFinansLoading,
    errorMessage: finansErrorMessage,
    hasDonem: hasFinansDonem,
    canFetch: canFetchFinans,
    fetchResolved: finansFetchResolved
  } = usePersonelFinansAdaylari({ personel, canViewFinans, isActive });
  const finansAdayToplamlari = useMemo(
    () => computeFinansAdayToplamlari(finansKayitlari),
    [finansKayitlari]
  );
  const showFinansAdayToplamlari =
    canViewFinans &&
    hasFinansDonem &&
    !isFinansLoading &&
    !finansErrorMessage &&
    hasFinansAdayToplami(finansAdayToplamlari);
  const showBordroAdayFinansLoading = canFetchFinans && !finansFetchResolved;
  const showBordroAdayBos =
    puantajEksikGunOzeti?.snapshotId == null &&
    !showFinansAdayToplamlari &&
    !showBordroAdayFinansLoading &&
    (!canFetchFinans || finansFetchResolved);
  const showMesaiFinansNotu = showFinansAdayToplamlari && hasMesaiFinansKaydi(finansKayitlari);
  const [revizyonTalepleri, setRevizyonTalepleri] = useState<RevizyonTalebi[]>([]);
  const [revizyonCorrections, setRevizyonCorrections] = useState<RevizyonCorrectionEvent[]>([]);
  const [isRevizyonLoading, setIsRevizyonLoading] = useState(false);
  const [revizyonErrorMessage, setRevizyonErrorMessage] = useState<string | null>(null);
  const sgkPrimGunu = typeof puantajEksikGunOzeti?.hesaplananPrimGunu === "number"
    ? `${puantajEksikGunOzeti.hesaplananPrimGunu} Gün`
    : "-";
  const eksikGun = typeof puantajEksikGunOzeti?.eksikGunSayisi === "number"
    ? `${puantajEksikGunOzeti.eksikGunSayisi} Gün`
    : "-";
  const eksikGunNedeni = puantajEksikGunOzeti?.eksikGunKodu
    ? `${puantajEksikGunOzeti.eksikGunKodu} · ${puantajEksikGunOzeti.eksikGunAciklamasi ?? "-"}`
    : "-";
  const takvimGun = typeof personel.sgk_ayin_takvim_gun_sayisi === "number" ? `${personel.sgk_ayin_takvim_gun_sayisi} Gün` : "-";
  const donem = formatDetailValue(personel.sgk_donem);
  const hesaplamaModu = formatSgkHesaplamaModuLabel(personel.sgk_hesaplama_modu);

  useEffect(() => {
    let isCancelled = false;

    if (!canViewRevizyon || !isActive) {
      setRevizyonTalepleri([]);
      setRevizyonCorrections([]);
      setIsRevizyonLoading(false);
      setRevizyonErrorMessage(null);
      return;
    }

    setIsRevizyonLoading(true);
    setRevizyonErrorMessage(null);

    Promise.all([
      fetchRevizyonTalepleri({ personel_id: personel.id }),
      fetchRevizyonCorrections({ personel_id: personel.id })
    ])
      .then(([talepler, corrections]) => {
        if (isCancelled) {
          return;
        }

        setRevizyonTalepleri(sortRevizyonTalepleriByLatest(talepler));
        setRevizyonCorrections(sortCorrectionsByLatest(corrections));
      })
      .catch(() => {
        if (isCancelled) {
          return;
        }

        setRevizyonTalepleri([]);
        setRevizyonCorrections([]);
        setRevizyonErrorMessage("Revizyon ve correction kayıtları yüklenemedi.");
      })
      .finally(() => {
        if (!isCancelled) {
          setIsRevizyonLoading(false);
        }
      });

    return () => {
      isCancelled = true;
    };
  }, [canViewRevizyon, isActive, personel.id]);

  return (
    <div className="personel-dosya-sections" data-testid="personel-puantaj-ozet-section">
      <section className="personel-puantaj-summary-card" data-testid="personel-sgk-prim-gun-card">
        <span className="personel-puantaj-summary-kicker">SGK Prim Günü</span>
        <strong className="personel-puantaj-summary-value" data-testid="personel-sgk-prim-gun">
          {sgkPrimGunu}
        </strong>
        <p className="personel-puantaj-summary-note">
          Immutable backend snapshot sonucudur; frontend SGK hesabı veya tahmini üretmez.
        </p>
      </section>

      {devamPrimiOzeti ? (
        <section
          className="personel-puantaj-summary-card personel-devam-primi-card"
          data-testid="personel-devam-primi-card"
        >
          <span className="personel-puantaj-summary-kicker">Devam Primi</span>
          <div className="personel-devam-primi-meta">
            <div className="personel-devam-primi-row">
              <span className="personel-devam-primi-label">Dönem</span>
              <span className="personel-devam-primi-value" data-testid="personel-devam-primi-donem">
                {formatDevamPrimiDonemLabel(devamPrimiOzeti.donem)}
              </span>
            </div>
            <div className="personel-devam-primi-row">
              <span className="personel-devam-primi-label">Durum</span>
              <span
                className={`personel-devam-primi-durum ${devamPrimiDurumToneClass(devamPrimiOzeti.durum)}`}
                data-testid="personel-devam-primi-durum"
              >
                {devamPrimiOzeti.durumLabel}
              </span>
            </div>
          </div>
          <p className="personel-puantaj-summary-note" data-testid="personel-devam-primi-aciklama">
            {devamPrimiOzeti.aciklama}
          </p>
          {devamPrimiOzeti.kayitKapsamiNotu ? (
            <p className="personel-devam-primi-scope-note">{devamPrimiOzeti.kayitKapsamiNotu}</p>
          ) : null}
        </section>
      ) : null}

      <section
        className="personel-puantaj-summary-card personel-devam-primi-card"
        data-testid="personel-bordro-aday-ozet-card"
      >
        <span className="personel-puantaj-summary-kicker">Bu Ay Bordroya Yansıyacak Adaylar</span>
        {!puantajEksikGunOzeti || puantajEksikGunOzeti.isLoading ? (
          <p
            className="personel-puantaj-summary-note"
            data-testid="personel-bordro-aday-ozet-beklemede"
          >
            {BORDRO_ADAY_OZETI_HENUZ_OLUSMADI}
          </p>
        ) : puantajEksikGunOzeti.snapshotId != null ? (
          <>
            <div className="personel-devam-primi-meta">
              <div className="personel-devam-primi-row">
                <span className="personel-devam-primi-label">SGK Prim / Eksik Gün</span>
                <span
                  className="personel-devam-primi-value"
                  data-testid="personel-bordro-aday-eksik-gun"
                >
                  {puantajEksikGunOzeti.hesaplananPrimGunu ?? "-"} / {puantajEksikGunOzeti.eksikGunSayisi ?? "-"}
                </span>
              </div>
              <div className="personel-devam-primi-row">
                <span className="personel-devam-primi-label">Eksik Gün Kodu</span>
                <span
                  className="personel-devam-primi-value"
                  data-testid="personel-bordro-aday-gunluk-kesinti"
                >
                  {puantajEksikGunOzeti.eksikGunKodu ?? "-"}
                </span>
              </div>
              <div className="personel-devam-primi-row">
                <span className="personel-devam-primi-label">Ücret Modeli / SGK Ödeneği</span>
                <span
                  className="personel-devam-primi-value"
                  data-testid="personel-bordro-aday-dakika-kesinti"
                >
                  {puantajEksikGunOzeti.ucretModeliLabel ?? "-"} / {puantajEksikGunOzeti.sgkOdenekDurumuLabel ?? "-"}
                </span>
              </div>
              <div className="personel-devam-primi-row">
                <span className="personel-devam-primi-label">Readiness</span>
                <span
                  className="personel-devam-primi-value"
                  data-testid="personel-bordro-aday-ucret-korunan"
                >
                  {puantajEksikGunOzeti.durumLabel}
                </span>
              </div>
            </div>
            <p className="personel-puantaj-summary-note">
              Snapshot #{puantajEksikGunOzeti.snapshotId} rev {puantajEksikGunOzeti.snapshotRevisionNo};
              kaynak süreç ve belgeler backend owner tarafından mühürlenmiştir.
            </p>
            {puantajEksikGunOzeti.blockerEtiketleri.length > 0 ? (
              <p className="personel-devam-primi-scope-note">
                Blocker: {puantajEksikGunOzeti.blockerEtiketleri.join(", ")}
              </p>
            ) : null}
          </>
        ) : showBordroAdayFinansLoading ? (
          <p
            className="personel-puantaj-summary-note"
            data-testid="personel-bordro-aday-ozet-yukleniyor"
          >
            Finans aday tutarları yükleniyor...
          </p>
        ) : showBordroAdayBos ? (
          <p className="personel-puantaj-summary-note" data-testid="personel-bordro-aday-ozet-bos">
            {BORDRO_ADAY_KALEM_GORMUNUYOR}
          </p>
        ) : null}

        {showFinansAdayToplamlari ? (
          <div className="personel-devam-primi-meta" data-testid="personel-bordro-aday-finans-toplamlari">
            <span className="personel-devam-primi-label">Finans aday tutarları</span>
            {finansAdayToplamlari.mahsupAdayTutari > 0 ? (
              <div className="personel-devam-primi-row">
                <span className="personel-devam-primi-label">Maaştan mahsup edilecek aday</span>
                <span
                  className="personel-devam-primi-value"
                  data-testid="personel-bordro-aday-finans-mahsup"
                >
                  {formatFinansKayitTutar(finansAdayToplamlari.mahsupAdayTutari)}
                </span>
              </div>
            ) : null}
            {finansAdayToplamlari.kesintiAdayTutari > 0 ? (
              <div className="personel-devam-primi-row">
                <span className="personel-devam-primi-label">Kesinti adayı</span>
                <span
                  className="personel-devam-primi-value"
                  data-testid="personel-bordro-aday-finans-kesinti"
                >
                  {formatFinansKayitTutar(finansAdayToplamlari.kesintiAdayTutari)}
                </span>
              </div>
            ) : null}
            {finansAdayToplamlari.ekOdemeAdayTutari > 0 ? (
              <div className="personel-devam-primi-row">
                <span className="personel-devam-primi-label">Ek ödeme adayı</span>
                <span
                  className="personel-devam-primi-value"
                  data-testid="personel-bordro-aday-finans-ek-odeme"
                >
                  {formatFinansKayitTutar(finansAdayToplamlari.ekOdemeAdayTutari)}
                </span>
              </div>
            ) : null}
            <p className="personel-puantaj-summary-note">
              Finans aday tutarları kayıtlı tutarların toplamıdır; bordro hesabı veya kesin ödeme
              sonucu değildir. Detaylar aşağıdaki finans kayıtlarında listelenir.
            </p>
            {showMesaiFinansNotu ? (
              <p className="personel-devam-primi-scope-note">
                Mesai kalemi manuel finans kaydıdır; puantaj fazla mesai hesabı değildir.
              </p>
            ) : null}
          </div>
        ) : null}
      </section>

      <PersonelFinansAdaylariSection
        finansKayitlari={finansKayitlari}
        isLoading={isFinansLoading}
        errorMessage={finansErrorMessage}
        canViewFinans={canViewFinans}
        hasDonem={hasFinansDonem}
        canFetch={canFetchFinans}
        fetchResolved={finansFetchResolved}
      />

      <PersonelBordroGatewaySection canViewBordro={canViewBordro} />

      <DossierSection
        title="Aylık Puantaj Özeti"
        description="Bu dosya SGK prim günü, eksik gün ve hesaplama modunu tek bakışta gösterir."
      >
        <DossierRecord label="Dönem" value={donem} />
        <DossierRecord label="SGK Prim Günü" value={sgkPrimGunu} />
        <DossierRecord label="Eksik Gün" value={eksikGun} />
        <DossierRecord label="Eksik Gün Nedeni" value={eksikGunNedeni} />
        <DossierRecord label="Takvim Gün Sayısı" value={takvimGun} />
        <DossierRecord label="Hesaplama Modu" value={hesaplamaModu} />
        {puantajEksikGunOzeti ? (
          <>
            <DossierRecord label="Authoritative Durum" value={puantajEksikGunOzeti.durumLabel} />
            <DossierRecord
              label="Kaynak Süreç / Puantaj / Belge"
              value={`${puantajEksikGunOzeti.kaynakSurecIdleri.join(", ") || "-"} / ${puantajEksikGunOzeti.kaynakPuantajIdleri.join(", ") || "-"} / ${puantajEksikGunOzeti.kaynakBelgeIdleri.join(", ") || "-"}`}
            />
            <DossierRecord
              label="Ücret Modeli / Şirket Politikası"
              value={`${puantajEksikGunOzeti.ucretModeliLabel ?? "-"} / ${puantajEksikGunOzeti.sirketPolitikaSurumId ?? "-"}`}
            />
            <DossierRecord
              label="SGK Ödenek Durumu"
              value={puantajEksikGunOzeti.sgkOdenekDurumuLabel ?? "-"}
            />
            <DossierRecord
              label="Blocker"
              value={puantajEksikGunOzeti.blockerEtiketleri.join(", ") || "Yok"}
            />
            <DossierRecord
              label="Snapshot / Revision"
              value={puantajEksikGunOzeti.snapshotId == null ? "-" : `#${puantajEksikGunOzeti.snapshotId} / ${puantajEksikGunOzeti.snapshotRevisionNo}`}
            />
            <DossierRecord label="SGK Hesap Hash" value={puantajEksikGunOzeti.sgkHesapHash ?? "-"} />
            <DossierRecord label="Katalog Sürümü" value={puantajEksikGunOzeti.katalogSurumu ?? "-"} />
            <DossierRecord label="Mevzuat Manifest Hash" value={puantajEksikGunOzeti.kaynakManifestHash ?? "-"} />
          </>
        ) : null}
      </DossierSection>

      <PersonelRevizyonCorrectionPanel
        personelId={personel.id}
        canViewRevizyon={canViewRevizyon}
        canCreateRevizyon={canCreateRevizyon}
        isLoading={isRevizyonLoading}
        errorMessage={revizyonErrorMessage}
        talepler={revizyonTalepleri}
        corrections={revizyonCorrections}
      />

      <div className="personel-kart-placeholder">
        <h3>Günlük Puantaj Dosyası</h3>
        <p>Günlük giriş-çıkış kayıtları ve detaylı puantaj düzenleme akışı ayrı puantaj ekranında kalır.</p>
        {canViewPuantaj ? (
          <Link to="/puantaj" state={{ prefillPersonelId: personel.id }} className="universal-btn-aux">
            Puantaj ekranına git
          </Link>
        ) : (
          <p className="personel-kart-placeholder-note">Puantaj görüntüleme yetkiniz yok.</p>
        )}
      </div>
    </div>
  );
}
