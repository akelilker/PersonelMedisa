import type { FazlaCalismaOdemeTercihi } from "../types/fazla-calisma-odeme-tercihi";
import type {
  SerbestZamanBakiye,
  SerbestZamanDuzeltmeEvent,
  SerbestZamanEvent,
  SerbestZamanHedefEvent,
  SerbestZamanHedefEventTipi,
  SerbestZamanIptalEvent,
  SerbestZamanKullanimEvent,
  SerbestZamanOlusumEvent
} from "../types/serbest-zaman";
import { hesaplaSerbestZamanDakika } from "./serbest-zaman-donusum";

export const SERBEST_ZAMAN_KULLANIM_SURE_AY = 6;

export type OlusumEventHataKodu =
  | "ALREADY_EXISTS"
  | "NOT_ELIGIBLE"
  | "ZERO_DAKIKA"
  | "NOT_PERSISTED";

export type OlusturOlusumEventSonuc =
  | { ok: true; event: SerbestZamanOlusumEvent }
  | { ok: false; code: OlusumEventHataKodu };

export type KullanimEventHataKodu = "ZERO_DAKIKA" | "NO_ELIGIBLE_BALANCE" | "INSUFFICIENT_BALANCE";

export type OlusturKullanimEventSonuc =
  | { ok: true; event: SerbestZamanKullanimEvent }
  | { ok: false; code: KullanimEventHataKodu };

export type HedefEventHataKodu =
  | "TARGET_NOT_FOUND"
  | "TARGET_PERSONEL_MISMATCH"
  | "TARGET_ALREADY_CANCELLED"
  | "ALREADY_CANCELLED"
  | "ZERO_DAKIKA"
  | "INSUFFICIENT_BALANCE"
  | "UNSUPPORTED_TARGET_EVENT";

export type OlusturIptalEventSonuc =
  | { ok: true; event: SerbestZamanIptalEvent }
  | { ok: false; code: HedefEventHataKodu };

export type OlusturDuzeltmeEventSonuc =
  | { ok: true; event: SerbestZamanDuzeltmeEvent }
  | { ok: false; code: HedefEventHataKodu };

function parseIsoDate(value: string): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value.trim());
  if (!match) {
    return null;
  }

  return new Date(
    Number.parseInt(match[1], 10),
    Number.parseInt(match[2], 10) - 1,
    Number.parseInt(match[3], 10)
  );
}

function formatIsoDate(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const d = String(date.getDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}

function bugunIsoDate(): string {
  return formatIsoDate(new Date());
}

function isValidEventTarihi(value: string): boolean {
  return /^\d{4}-\d{2}-\d{2}$/.test(value.trim());
}

function isHedefEventTipi(value: string): value is SerbestZamanHedefEventTipi {
  return value === "SERBEST_ZAMAN_OLUSUM" || value === "SERBEST_ZAMAN_KULLANIM";
}

export function extractEventTarihi(secimZamani?: string, referansZamani?: string): string {
  if (secimZamani) {
    const datePart = secimZamani.trim().slice(0, 10);
    if (/^\d{4}-\d{2}-\d{2}$/.test(datePart)) {
      return datePart;
    }
  }

  if (referansZamani) {
    const datePart = referansZamani.trim().slice(0, 10);
    if (/^\d{4}-\d{2}-\d{2}$/.test(datePart)) {
      return datePart;
    }
  }

  return bugunIsoDate();
}

export function hesaplaSonKullanimTarihi(eventTarihi: string): string {
  const parsed = parseIsoDate(eventTarihi);
  if (!parsed) {
    return eventTarihi;
  }

  const targetMonthIndex = parsed.getMonth() + SERBEST_ZAMAN_KULLANIM_SURE_AY;
  const targetYear = parsed.getFullYear() + Math.floor(targetMonthIndex / 12);
  const normalizedMonth = ((targetMonthIndex % 12) + 12) % 12;
  const lastDayOfTargetMonth = new Date(targetYear, normalizedMonth + 1, 0).getDate();
  const day = Math.min(parsed.getDate(), lastDayOfTargetMonth);

  return formatIsoDate(new Date(targetYear, normalizedMonth, day));
}

export function findHedefEventById(
  events: readonly SerbestZamanEvent[],
  hedefEventId: number
): SerbestZamanHedefEvent | null {
  for (const event of events) {
    if (
      (event.event_tipi === "SERBEST_ZAMAN_OLUSUM" ||
        event.event_tipi === "SERBEST_ZAMAN_KULLANIM") &&
      event.id === hedefEventId
    ) {
      return event;
    }
  }

  return null;
}

function buildIptalHedefIds(events: readonly SerbestZamanEvent[], personel_id: number): Set<number> {
  const ids = new Set<number>();

  for (const event of events) {
    if (event.event_tipi === "SERBEST_ZAMAN_IPTAL" && event.personel_id === personel_id) {
      ids.add(event.hedef_event_id);
    }
  }

  return ids;
}

function buildDuzeltmeOverrides(
  events: readonly SerbestZamanEvent[],
  personel_id: number,
  iptalHedefIds: ReadonlySet<number>
): Map<number, number> {
  const overrides = new Map<number, number>();

  const duzeltmeler = events
    .filter(
      (event): event is SerbestZamanDuzeltmeEvent =>
        event.event_tipi === "SERBEST_ZAMAN_DUZELTME" && event.personel_id === personel_id
    )
    .sort((a, b) => (a.id ?? 0) - (b.id ?? 0));

  for (const event of duzeltmeler) {
    if (!iptalHedefIds.has(event.hedef_event_id)) {
      overrides.set(event.hedef_event_id, event.yeni_dakika);
    }
  }

  return overrides;
}

function etkinDakika(
  hamDakika: number,
  eventId: number | undefined,
  iptalHedefIds: ReadonlySet<number>,
  overrides: ReadonlyMap<number, number>
): number | null {
  if (eventId !== undefined && iptalHedefIds.has(eventId)) {
    return null;
  }

  if (eventId !== undefined && overrides.has(eventId)) {
    return overrides.get(eventId)!;
  }

  return hamDakika;
}

function validateHedefForMutation(params: {
  mevcutEvents: readonly SerbestZamanEvent[];
  personel_id: number;
  hedef_event_id: number;
  hedef_event_tipi: string;
  forIptal: boolean;
}): { ok: true; hedef: SerbestZamanHedefEvent } | { ok: false; code: HedefEventHataKodu } {
  const { mevcutEvents, personel_id, hedef_event_id, hedef_event_tipi, forIptal } = params;

  if (!isHedefEventTipi(hedef_event_tipi)) {
    return { ok: false, code: "UNSUPPORTED_TARGET_EVENT" };
  }

  const hedef = findHedefEventById(mevcutEvents, hedef_event_id);
  if (!hedef) {
    return { ok: false, code: "TARGET_NOT_FOUND" };
  }

  if (hedef.event_tipi !== hedef_event_tipi) {
    return { ok: false, code: "UNSUPPORTED_TARGET_EVENT" };
  }

  if (hedef.personel_id !== personel_id) {
    return { ok: false, code: "TARGET_PERSONEL_MISMATCH" };
  }

  const iptalHedefIds = buildIptalHedefIds(mevcutEvents, personel_id);

  if (iptalHedefIds.has(hedef_event_id)) {
    return {
      ok: false,
      code: forIptal ? "ALREADY_CANCELLED" : "TARGET_ALREADY_CANCELLED"
    };
  }

  if (forIptal) {
    for (const event of mevcutEvents) {
      if (
        event.event_tipi === "SERBEST_ZAMAN_IPTAL" &&
        event.personel_id === personel_id &&
        event.hedef_event_id === hedef_event_id
      ) {
        return { ok: false, code: "ALREADY_CANCELLED" };
      }
    }
  }

  return { ok: true, hedef };
}

function isEventCancelled(events: readonly SerbestZamanEvent[], eventId: number | undefined): boolean {
  if (eventId === undefined) {
    return false;
  }

  return events.some(
    (event) => event.event_tipi === "SERBEST_ZAMAN_IPTAL" && event.hedef_event_id === eventId
  );
}

export function findActiveOlusumByOdemeTercihiId(
  events: readonly SerbestZamanEvent[],
  odemeTercihiId: number
): SerbestZamanOlusumEvent | null {
  for (const event of events) {
    if (
      event.event_tipi === "SERBEST_ZAMAN_OLUSUM" &&
      event.kaynak_odeme_tercihi_id === odemeTercihiId &&
      !isEventCancelled(events, event.id)
    ) {
      return event;
    }
  }

  return null;
}

/** Active-only lookup (cancelled olusum is not active). */
export function findOlusumByOdemeTercihiId(
  events: readonly SerbestZamanEvent[],
  odemeTercihiId: number
): SerbestZamanOlusumEvent | null {
  return findActiveOlusumByOdemeTercihiId(events, odemeTercihiId);
}

export function findOlusumBySnapshotId(
  events: readonly SerbestZamanEvent[],
  snapshotId: number
): SerbestZamanOlusumEvent | null {
  for (const event of events) {
    if (
      event.event_tipi === "SERBEST_ZAMAN_OLUSUM" &&
      event.kaynak_snapshot_id === snapshotId &&
      !isEventCancelled(events, event.id)
    ) {
      return event;
    }
  }

  return null;
}

export function olusturOlusumEvent(params: {
  tercih: FazlaCalismaOdemeTercihi;
  mevcutEvents: readonly SerbestZamanEvent[];
  referansZamani?: string;
}): OlusturOlusumEventSonuc {
  const { tercih, mevcutEvents, referansZamani } = params;

  if (tercih.id === undefined) {
    return { ok: false, code: "NOT_PERSISTED" };
  }

  if (tercih.odeme_tipi !== "SERBEST_ZAMAN") {
    return { ok: false, code: "NOT_ELIGIBLE" };
  }

  if (findActiveOlusumByOdemeTercihiId(mevcutEvents, tercih.id)) {
    return { ok: false, code: "ALREADY_EXISTS" };
  }

  const dakika = hesaplaSerbestZamanDakika({
    fazla_calisma_dakika: tercih.fazla_calisma_dakika
  });

  if (dakika <= 0) {
    return { ok: false, code: "ZERO_DAKIKA" };
  }

  const event_tarihi = extractEventTarihi(tercih.secim_zamani, referansZamani);
  const son_kullanim_tarihi = hesaplaSonKullanimTarihi(event_tarihi);

  return {
    ok: true,
    event: {
      personel_id: tercih.personel_id,
      kaynak_snapshot_id: tercih.snapshot_id,
      kaynak_odeme_tercihi_id: tercih.id,
      event_tipi: "SERBEST_ZAMAN_OLUSUM",
      dakika,
      event_tarihi,
      son_kullanim_tarihi,
      aciklama: `FM snapshot ${tercih.snapshot_id} serbest zaman olusumu`
    }
  };
}

export function olusturKullanimEvent(params: {
  personel_id: number;
  dakika: number;
  event_tarihi: string;
  islem_anahtari: string;
  mevcutEvents: readonly SerbestZamanEvent[];
  referans_tarih?: string;
  aciklama?: string;
}): OlusturKullanimEventSonuc {
  const {
    personel_id,
    dakika,
    event_tarihi,
    islem_anahtari,
    mevcutEvents,
    aciklama,
    referans_tarih
  } = params;

  if (!Number.isFinite(personel_id) || personel_id < 1) {
    return { ok: false, code: "NO_ELIGIBLE_BALANCE" };
  }

  if (!Number.isFinite(dakika) || dakika <= 0) {
    return { ok: false, code: "ZERO_DAKIKA" };
  }

  if (!isValidEventTarihi(event_tarihi)) {
    return { ok: false, code: "ZERO_DAKIKA" };
  }

  const anahtar = islem_anahtari.trim();
  if (!anahtar) {
    return { ok: false, code: "ZERO_DAKIKA" };
  }

  const bakiye = hesaplaSerbestZamanBakiye({
    personel_id,
    events: mevcutEvents,
    referans_tarih
  });

  if (bakiye.kalan_dakika <= 0) {
    return { ok: false, code: "NO_ELIGIBLE_BALANCE" };
  }

  if (dakika > bakiye.kalan_dakika) {
    return { ok: false, code: "INSUFFICIENT_BALANCE" };
  }

  return {
    ok: true,
    event: {
      personel_id,
      event_tipi: "SERBEST_ZAMAN_KULLANIM",
      dakika,
      event_tarihi: event_tarihi.trim().slice(0, 10),
      islem_anahtari: anahtar,
      aciklama
    }
  };
}

export function olusturIptalEvent(params: {
  personel_id: number;
  hedef_event_id: number;
  hedef_event_tipi: SerbestZamanHedefEventTipi;
  event_tarihi: string;
  islem_anahtari: string;
  mevcutEvents: readonly SerbestZamanEvent[];
  aciklama?: string;
}): OlusturIptalEventSonuc {
  const {
    personel_id,
    hedef_event_id,
    hedef_event_tipi,
    event_tarihi,
    islem_anahtari,
    mevcutEvents,
    aciklama
  } = params;

  if (!Number.isFinite(personel_id) || personel_id < 1) {
    return { ok: false, code: "TARGET_PERSONEL_MISMATCH" };
  }

  if (!Number.isFinite(hedef_event_id) || hedef_event_id < 1) {
    return { ok: false, code: "TARGET_NOT_FOUND" };
  }

  if (!isValidEventTarihi(event_tarihi)) {
    return { ok: false, code: "UNSUPPORTED_TARGET_EVENT" };
  }

  const anahtar = islem_anahtari.trim();
  if (!anahtar) {
    return { ok: false, code: "UNSUPPORTED_TARGET_EVENT" };
  }

  const hedefSonuc = validateHedefForMutation({
    mevcutEvents,
    personel_id,
    hedef_event_id,
    hedef_event_tipi,
    forIptal: true
  });

  if (!hedefSonuc.ok) {
    return hedefSonuc;
  }

  return {
    ok: true,
    event: {
      personel_id,
      event_tipi: "SERBEST_ZAMAN_IPTAL",
      hedef_event_id,
      hedef_event_tipi,
      event_tarihi: event_tarihi.trim().slice(0, 10),
      islem_anahtari: anahtar,
      aciklama
    }
  };
}

export function olusturDuzeltmeEvent(params: {
  personel_id: number;
  hedef_event_id: number;
  hedef_event_tipi: SerbestZamanHedefEventTipi;
  yeni_dakika: number;
  event_tarihi: string;
  islem_anahtari: string;
  mevcutEvents: readonly SerbestZamanEvent[];
  referans_tarih?: string;
  aciklama: string;
}): OlusturDuzeltmeEventSonuc {
  const {
    personel_id,
    hedef_event_id,
    hedef_event_tipi,
    yeni_dakika,
    event_tarihi,
    islem_anahtari,
    mevcutEvents,
    referans_tarih,
    aciklama
  } = params;

  if (!Number.isFinite(personel_id) || personel_id < 1) {
    return { ok: false, code: "TARGET_PERSONEL_MISMATCH" };
  }

  if (!Number.isFinite(hedef_event_id) || hedef_event_id < 1) {
    return { ok: false, code: "TARGET_NOT_FOUND" };
  }

  if (!Number.isFinite(yeni_dakika) || yeni_dakika <= 0) {
    return { ok: false, code: "ZERO_DAKIKA" };
  }

  if (!isValidEventTarihi(event_tarihi)) {
    return { ok: false, code: "ZERO_DAKIKA" };
  }

  const anahtar = islem_anahtari.trim();
  const gerekce = aciklama.trim();
  if (!anahtar || !gerekce) {
    return { ok: false, code: "ZERO_DAKIKA" };
  }

  const hedefSonuc = validateHedefForMutation({
    mevcutEvents,
    personel_id,
    hedef_event_id,
    hedef_event_tipi,
    forIptal: false
  });

  if (!hedefSonuc.ok) {
    return hedefSonuc;
  }

  if (hedef_event_tipi === "SERBEST_ZAMAN_KULLANIM") {
    const simulated: SerbestZamanDuzeltmeEvent = {
      personel_id,
      event_tipi: "SERBEST_ZAMAN_DUZELTME",
      hedef_event_id,
      hedef_event_tipi,
      yeni_dakika,
      event_tarihi: event_tarihi.trim().slice(0, 10),
      islem_anahtari: anahtar,
      aciklama: gerekce
    };

    const bakiye = hesaplaSerbestZamanBakiye({
      personel_id,
      events: [...mevcutEvents, simulated],
      referans_tarih
    });

    const kullanilabilir = bakiye.toplam_hak_dakika - bakiye.suresi_dolan_dakika;
    if (bakiye.kullanilan_dakika > kullanilabilir) {
      return { ok: false, code: "INSUFFICIENT_BALANCE" };
    }
  }

  return {
    ok: true,
    event: {
      personel_id,
      event_tipi: "SERBEST_ZAMAN_DUZELTME",
      hedef_event_id,
      hedef_event_tipi,
      yeni_dakika,
      event_tarihi: event_tarihi.trim().slice(0, 10),
      islem_anahtari: anahtar,
      aciklama: gerekce
    }
  };
}

export function hesaplaSerbestZamanBakiye(params: {
  personel_id: number;
  events: readonly SerbestZamanEvent[];
  referans_tarih?: string;
}): SerbestZamanBakiye {
  const { personel_id, events } = params;
  const referans = params.referans_tarih ?? bugunIsoDate();

  const olusumEvents = events.filter(
    (event): event is SerbestZamanOlusumEvent =>
      event.event_tipi === "SERBEST_ZAMAN_OLUSUM" && event.personel_id === personel_id
  );

  const iptalHedefIds = buildIptalHedefIds(events, personel_id);
  const duzeltmeOverrides = buildDuzeltmeOverrides(events, personel_id, iptalHedefIds);

  let toplam_hak_dakika = 0;
  let suresi_dolan_dakika = 0;
  let aktif_olusum_sayisi = 0;

  for (const event of olusumEvents) {
    const dakika = etkinDakika(event.dakika, event.id, iptalHedefIds, duzeltmeOverrides);
    if (dakika === null) {
      continue;
    }

    aktif_olusum_sayisi += 1;
    toplam_hak_dakika += dakika;
    if (referans > event.son_kullanim_tarihi) {
      suresi_dolan_dakika += dakika;
    }
  }

  let kullanilan_dakika = 0;

  for (const event of events) {
    if (event.event_tipi === "SERBEST_ZAMAN_KULLANIM" && event.personel_id === personel_id) {
      const dakika = etkinDakika(event.dakika, event.id, iptalHedefIds, duzeltmeOverrides);
      if (dakika !== null) {
        kullanilan_dakika += dakika;
      }
    }
  }

  const kalan_dakika = Math.max(toplam_hak_dakika - suresi_dolan_dakika - kullanilan_dakika, 0);

  return {
    personel_id,
    toplam_hak_dakika,
    kullanilan_dakika,
    kalan_dakika,
    suresi_dolan_dakika,
    event_sayisi: aktif_olusum_sayisi
  };
}
