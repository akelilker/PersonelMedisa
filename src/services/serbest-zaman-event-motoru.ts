import type { FazlaCalismaOdemeTercihi } from "../types/fazla-calisma-odeme-tercihi";
import type {
  SerbestZamanBakiye,
  SerbestZamanEvent,
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

export function findOlusumByOdemeTercihiId(
  events: readonly SerbestZamanEvent[],
  odemeTercihiId: number
): SerbestZamanOlusumEvent | null {
  for (const event of events) {
    if (
      event.event_tipi === "SERBEST_ZAMAN_OLUSUM" &&
      event.kaynak_odeme_tercihi_id === odemeTercihiId
    ) {
      return event;
    }
  }

  return null;
}

export function findOlusumBySnapshotId(
  events: readonly SerbestZamanEvent[],
  snapshotId: number
): SerbestZamanOlusumEvent | null {
  for (const event of events) {
    if (
      event.event_tipi === "SERBEST_ZAMAN_OLUSUM" &&
      event.kaynak_snapshot_id === snapshotId
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

  if (findOlusumByOdemeTercihiId(mevcutEvents, tercih.id)) {
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
  mevcutEvents: readonly SerbestZamanEvent[];
  referans_tarih?: string;
  aciklama?: string;
}): OlusturKullanimEventSonuc {
  const { personel_id, dakika, event_tarihi, mevcutEvents, aciklama, referans_tarih } = params;

  if (!Number.isFinite(personel_id) || personel_id < 1) {
    return { ok: false, code: "NO_ELIGIBLE_BALANCE" };
  }

  if (!Number.isFinite(dakika) || dakika <= 0) {
    return { ok: false, code: "ZERO_DAKIKA" };
  }

  if (!isValidEventTarihi(event_tarihi)) {
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
      aciklama
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

  let toplam_hak_dakika = 0;
  let suresi_dolan_dakika = 0;

  for (const event of olusumEvents) {
    toplam_hak_dakika += event.dakika;
    if (referans > event.son_kullanim_tarihi) {
      suresi_dolan_dakika += event.dakika;
    }
  }

  let kullanilan_dakika = 0;

  for (const event of events) {
    if (event.event_tipi === "SERBEST_ZAMAN_KULLANIM" && event.personel_id === personel_id) {
      kullanilan_dakika += event.dakika;
    }
  }

  const kalan_dakika = Math.max(toplam_hak_dakika - suresi_dolan_dakika - kullanilan_dakika, 0);

  return {
    personel_id,
    toplam_hak_dakika,
    kullanilan_dakika,
    kalan_dakika,
    suresi_dolan_dakika,
    event_sayisi: olusumEvents.length
  };
}
