import { describe, expect, it } from "vitest";
import type { ApiResponse } from "../../src/types/api";
import {
  normalizeManagerQrAttendanceResponse
} from "../../src/api/qr.api";
import { resolveDemoApiResponse } from "../../src/api/mock-demo";

const item = {
  personel_id: 1,
  ad_soyad: "Ayşe Yılmaz",
  sicil_no: "MED-001",
  sube_id: 1,
  sube: "Merkez",
  date_from: "2026-08-15",
  date_to: "2026-08-15",
  first_entry: "2026-08-15T08:54:00+03:00",
  last_exit: null,
  last_movement: "2026-08-15T08:54:00+03:00",
  last_movement_type: "GIRIS",
  inside: true,
  interval_count: 0,
  missing_entry: false,
  missing_exit: true,
  branch_mismatch: false,
  anomalies: ["MISSING_CIKIS"],
  matched_seconds: 0,
  source_event_count: 1
};

function response(data: unknown): ApiResponse<unknown> {
  return { data, meta: {}, errors: [] };
}

describe("manager QR read contract", () => {
  it("normalizes a populated production-shaped response", () => {
    const result = normalizeManagerQrAttendanceResponse(
      response({
        from: "2026-08-15",
        to: "2026-08-15",
        items: [item],
        total: 1,
        limit: 50,
        offset: 0,
        has_next: false,
        algorithm_version: "QR_INTERVAL_V1"
      })
    );

    expect(result.items[0]).toMatchObject({
      ad_soyad: "Ayşe Yılmaz",
      inside: true,
      missing_exit: true,
      anomalies: ["MISSING_CIKIS"]
    });
  });

  it("accepts a valid empty response without inventing summary rows", () => {
    const result = normalizeManagerQrAttendanceResponse(
      response({
        from: "2026-08-15",
        to: "2026-08-15",
        items: [],
        total: 0,
        limit: 50,
        offset: 0,
        has_next: false,
        algorithm_version: "QR_INTERVAL_V1"
      })
    );

    expect(result.items).toEqual([]);
    expect(result.total).toBe(0);
  });

  it("rejects an invalid response shape", () => {
    expect(() => normalizeManagerQrAttendanceResponse(response({ items: [] }))).toThrow(
      "/puantaj/qr-hareketleri yaniti gecersiz."
    );
  });

  it("returns a Turkish forbidden error envelope for unauthorized demo roles", () => {
    const result = resolveDemoApiResponse("/puantaj/qr-hareketleri?from=2026-08-15&to=2026-08-15", {
      method: "GET",
      headers: { "X-Demo-Role": "PERSONEL" }
    });

    expect(result?.data).toBeNull();
    expect(result?.errors[0]).toMatchObject({
      code: "FORBIDDEN",
      message: "Bu islem icin yetkiniz yok."
    });
  });

  it("keeps interval, anomaly, and gateway fields in the canonical item", () => {
    const result = normalizeManagerQrAttendanceResponse(
      response({
        from: "2026-08-15",
        to: "2026-08-15",
        items: [{ ...item, interval_count: 2, branch_mismatch: true, anomalies: ["BRANCH_MISMATCH"] }],
        total: 1,
        limit: 100,
        offset: 0,
        has_next: false,
        algorithm_version: "QR_INTERVAL_V1"
      })
    );

    expect(result.items[0]).toMatchObject({
      interval_count: 2,
      branch_mismatch: true,
      anomalies: ["BRANCH_MISMATCH"]
    });
  });
});
