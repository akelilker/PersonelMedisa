import { describe, expect, it } from "vitest";
import {
  ExitPostcheckError,
  FORBIDDEN_EXIT_POSTCHECK_ROUTE,
  resolveExitSurecFromList,
  resolveSurecIdFromApplyResult,
  validateExitSurecRecord,
  verifyPersonnelExitSurec,
} from "../../scripts/ops/personel-lifecycle-exit-postcheck.mjs";

describe("MG personnel exit postcheck route parity", () => {
  it("documents the forbidden non-canonical route", () => {
    expect(FORBIDDEN_EXIT_POSTCHECK_ROUTE).toBe("/personeller/{id}/surecler");
  });

  it("PASS: apply entity_id uses canonical GET /surecler/{surec_id}", async () => {
    const calls = [];
    const api = async (pathname) => {
      calls.push(pathname);
      if (pathname === "/surecler/901") {
        return {
          status: 200,
          json: {
            data: {
              id: 901,
              personel_id: 202,
              surec_turu: "ISTEN_AYRILMA",
              baslangic_tarihi: "2026-07-30",
              aciklama: "İşveren feshi",
              state: "AKTIF",
            },
          },
        };
      }
      throw new Error(`unexpected route ${pathname}`);
    };

    const result = await verifyPersonnelExitSurec(api, {
      token: "t",
      personelId: 202,
      surecId: 901,
      expectedExitDate: "2026-07-30",
      expectedAciklama: "İşveren feshi",
    });

    expect(calls).toEqual(["/surecler/901"]);
    expect(result).toMatchObject({
      surec_id: 901,
      personel_id: 202,
      route: "/surecler/901",
    });
  });

  it("PASS: without surec_id uses canonical GET /surecler?personel_id=", async () => {
    const calls = [];
    const api = async (pathname) => {
      calls.push(pathname);
      if (pathname === "/surecler?personel_id=208&limit=50") {
        return {
          status: 200,
          json: {
            data: {
              items: [
                {
                  id: 700,
                  personel_id: 208,
                  surec_turu: "IZIN",
                  state: "AKTIF",
                },
                {
                  id: 802,
                  personel_id: 208,
                  surec_turu: "ISTEN_AYRILMA",
                  baslangic_tarihi: "2026-07-30",
                  aciklama: "İşveren feshi",
                  state: "AKTIF",
                },
              ],
            },
          },
        };
      }
      throw new Error(`unexpected route ${pathname}`);
    };

    const result = await verifyPersonnelExitSurec(api, {
      token: "t",
      personelId: 208,
      surecId: null,
      expectedExitDate: "2026-07-30",
      expectedAciklama: "İşveren feshi",
    });

    expect(calls).toEqual(["/surecler?personel_id=208&limit=50"]);
    expect(result.surec_id).toBe(802);
    expect(result.route).toBe("/surecler?personel_id=208");
  });

  it("BLOCKED: wrong personel, type, date, aciklama and empty list", async () => {
    expect(
      validateExitSurecRecord(
        { id: 1, personel_id: 999, surec_turu: "ISTEN_AYRILMA", baslangic_tarihi: "2026-07-30", state: "AKTIF" },
        { personelId: 202, expectedExitDate: "2026-07-30" }
      ).code
    ).toBe("EXIT_SUREC_PERSONEL_MISMATCH");

    expect(
      validateExitSurecRecord(
        { id: 1, personel_id: 202, surec_turu: "IZIN", baslangic_tarihi: "2026-07-30", state: "AKTIF" },
        { personelId: 202 }
      ).code
    ).toBe("EXIT_SUREC_TURU_MISMATCH");

    expect(
      validateExitSurecRecord(
        {
          id: 1,
          personel_id: 202,
          surec_turu: "ISTEN_AYRILMA",
          baslangic_tarihi: "2026-01-01",
          aciklama: "x",
          state: "AKTIF",
        },
        { personelId: 202, expectedExitDate: "2026-07-30", expectedAciklama: "İşveren feshi" }
      ).code
    ).toBe("EXIT_SUREC_DATE_MISMATCH");

    expect(
      validateExitSurecRecord(
        {
          id: 1,
          personel_id: 202,
          surec_turu: "ISTEN_AYRILMA",
          baslangic_tarihi: "2026-07-30",
          aciklama: "farklı",
          state: "AKTIF",
        },
        { personelId: 202, expectedExitDate: "2026-07-30", expectedAciklama: "İşveren feshi" }
      ).code
    ).toBe("EXIT_SUREC_ACIKLAMA_MISMATCH");

    expect(resolveExitSurecFromList([], 202)).toBeNull();
    expect(resolveExitSurecFromList([{ id: 1, personel_id: 202, surec_turu: "ISTEN_AYRILMA", state: "IPTAL" }], 202)).toBeNull();
  });

  it("distinguishes route read failures from business-data misses", async () => {
    await expect(
      verifyPersonnelExitSurec(async () => ({ status: 500, json: null }), {
        token: "t",
        personelId: 202,
        surecId: 1,
      })
    ).rejects.toMatchObject({ code: "POSTCHECK_ROUTE_READ_FAILED" });

    await expect(
      verifyPersonnelExitSurec(async () => ({ status: 404, json: null }), {
        token: "t",
        personelId: 202,
        surecId: 1,
      })
    ).rejects.toMatchObject({ code: "EXIT_SUREC_NOT_FOUND" });

    await expect(
      verifyPersonnelExitSurec(async (pathname) => {
        if (pathname.startsWith("/surecler?personel_id=")) {
          return { status: 200, json: { data: { items: [] } } };
        }
        throw new Error(`unexpected ${pathname}`);
      }, {
        token: "t",
        personelId: 202,
        surecId: null,
      })
    ).rejects.toMatchObject({ code: "EXIT_SUREC_NOT_FOUND" });

    try {
      await verifyPersonnelExitSurec(async () => ({ status: 404, json: null }), {
        token: "t",
        personelId: 202,
        surecId: null,
      });
      throw new Error("expected throw");
    } catch (error) {
      expect(error).toBeInstanceOf(ExitPostcheckError);
      expect((error as ExitPostcheckError).code).toBe("POSTCHECK_ROUTE_NOT_FOUND");
    }
  });

  it("resolves surec_id from apply satir_sonuclari by mutation_id", () => {
    const applyRows = [
      { mutation_id: "mg-exit-202", owner: "PersonelIstenAyrilmaService", entity_id: 901, durum: "APPLIED" },
      { mutation_id: "mg-exit-208", owner: "PersonelIstenAyrilmaService", entity_id: 902, durum: "APPLIED" },
    ];
    expect(resolveSurecIdFromApplyResult(applyRows, { mutationId: "mg-exit-202" })).toBe(901);
    expect(resolveSurecIdFromApplyResult(applyRows, { mutationId: "mg-exit-208" })).toBe(902);
    expect(resolveSurecIdFromApplyResult(applyRows, { mutationId: "missing" })).toBeNull();
  });
});
