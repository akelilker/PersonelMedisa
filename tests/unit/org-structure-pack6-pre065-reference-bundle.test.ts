import { beforeEach, describe, expect, it, vi } from "vitest";

const apiRequestMock = vi.fn();

vi.mock("../../src/api/api-client", () => ({
  apiRequest: (...args: unknown[]) => apiRequestMock(...args)
}));

import {
  fetchBirimOptions,
  fetchBolumOptions,
  fetchDepartmanOptions,
  fetchGorevOptions,
  fetchPersonelTipiOptions,
  fetchPozisyonOptions
} from "../../src/api/referans.api";

describe("Pack6 pre-065 reference bundle degrade", () => {
  beforeEach(() => {
    apiRequestMock.mockReset();
  });

  it("keeps legacy refs usable while Pack6 endpoints return ORG_STRUCTURE_SCHEMA_NOT_READY", async () => {
    apiRequestMock.mockImplementation(async (path: string) => {
      if (String(path).includes("/referans/bolumler")
        || String(path).includes("/referans/birimler")
        || String(path).includes("/referans/pozisyonlar")
      ) {
        throw Object.assign(new Error("Org structure schema hazir degil."), {
          status: 409,
          code: "ORG_STRUCTURE_SCHEMA_NOT_READY"
        });
      }
      if (String(path).includes("/referans/departmanlar")) {
        return { data: [{ id: 1, ad: "İdari İşler" }] };
      }
      if (String(path).includes("/referans/gorevler")) {
        return { data: [{ id: 2, ad: "Asistan" }] };
      }
      if (String(path).includes("/referans/personel-tipleri")) {
        return { data: [{ id: 1, ad: "Tam Zamanli" }] };
      }
      throw new Error(`unexpected path ${path}`);
    });

    const [
      departmanOptions,
      bolumOptions,
      birimOptions,
      gorevOptions,
      pozisyonOptions,
      personelTipiOptions
    ] = await Promise.all([
      fetchDepartmanOptions(),
      fetchBolumOptions(),
      fetchBirimOptions(),
      fetchGorevOptions(),
      fetchPozisyonOptions(),
      fetchPersonelTipiOptions()
    ]);

    expect(departmanOptions).toEqual([{ id: 1, label: "İdari İşler" }]);
    expect(gorevOptions).toEqual([{ id: 2, label: "Asistan" }]);
    expect(personelTipiOptions).toEqual([{ id: 1, label: "Tam Zamanli" }]);
    expect(bolumOptions).toEqual([]);
    expect(birimOptions).toEqual([]);
    expect(pozisyonOptions).toEqual([]);
  });
});
