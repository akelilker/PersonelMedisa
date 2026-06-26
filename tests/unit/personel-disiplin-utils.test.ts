import { describe, expect, it } from "vitest";
import {
  isCezaFinansKalem,
  isDisiplinSurecSignal,
  sortCezaFinansKalemleri,
  sortDisiplinSurecSignals
} from "../../src/features/personeller/components/personel-dosya/personel-disiplin-utils";
import type { FinansKalem } from "../../src/types/finans";
import type { Surec } from "../../src/types/surec";

function makeSurec(overrides: Partial<Surec> & Pick<Surec, "id" | "surec_turu">): Surec {
  return {
    personel_id: 1,
    baslangic_tarihi: "2026-03-01",
    state: "AKTIF",
    ...overrides
  };
}

describe("personel-disiplin-utils", () => {
  it("isDisiplinSurecSignal devamsizlik ve puantaj sapmalarini yakalar", () => {
    expect(isDisiplinSurecSignal(makeSurec({ id: 1, surec_turu: "DEVAMSIZLIK" }))).toBe(true);
    expect(isDisiplinSurecSignal(makeSurec({ id: 2, surec_turu: "GEC_GELDI" }))).toBe(true);
    expect(isDisiplinSurecSignal(makeSurec({ id: 3, surec_turu: "IZIN", alt_tur: "YILLIK_IZIN" }))).toBe(false);
    expect(isDisiplinSurecSignal(makeSurec({ id: 4, surec_turu: "DEVAMSIZLIK", state: "IPTAL" }))).toBe(false);
  });

  it("sortDisiplinSurecSignals en yeni kaydi once getirir", () => {
    const sorted = sortDisiplinSurecSignals([
      makeSurec({ id: 1, surec_turu: "DEVAMSIZLIK", baslangic_tarihi: "2026-01-01" }),
      makeSurec({ id: 2, surec_turu: "DEVAMSIZLIK", baslangic_tarihi: "2026-04-01" })
    ]);

    expect(sorted.map((item) => item.id)).toEqual([2, 1]);
  });

  it("sortCezaFinansKalemleri sadece ilgili personelin CEZA kayitlarini dondurur", () => {
    const items: FinansKalem[] = [
      { id: 1, personel_id: 1, donem: "2026-04", kalem_turu: "AVANS", tutar: 100 },
      { id: 2, personel_id: 1, donem: "2026-05", kalem_turu: "CEZA", tutar: 250 },
      { id: 3, personel_id: 2, donem: "2026-05", kalem_turu: "CEZA", tutar: 500 }
    ];

    expect(isCezaFinansKalem(items[1])).toBe(true);
    expect(sortCezaFinansKalemleri(items, 1).map((item) => item.id)).toEqual([2]);
  });
});
