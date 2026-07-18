import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import { validateAllowlist } from "../../scripts/api-parity/allowlist.mjs";
import { classifyParity, pathCovers } from "../../scripts/api-parity/classify.mjs";
import { runApiParityGate } from "../../scripts/api-parity/index.mjs";
import {
  normalizePath,
  normalizeRegexPath,
  normalizeTemplatePath,
  stripPathNoise
} from "../../scripts/api-parity/normalize.mjs";

const root = join(dirname(fileURLToPath(import.meta.url)), "../..");

describe("api-parity normalize", () => {
  it("normalizes dynamic path forms to :id", () => {
    expect(normalizeTemplatePath("/surecler/${id}")).toBe("/surecler/:id");
    expect(normalizePath("/surecler/:id")).toBe("/surecler/:id");
    expect(normalizePath("/surecler/{id}")).toBe("/surecler/:id");
    expect(normalizePath("/surecler/123")).toBe("/surecler/:id");
    expect(normalizeRegexPath("^/surecler/(\\d+)$")).toBe("/surecler/:id");
  });

  it("preserves semantic param names", () => {
    expect(normalizeTemplatePath("/gunluk-puantaj/${personelId}/${encodeURIComponent(tarih)}")).toBe(
      "/gunluk-puantaj/:personelId/:tarih"
    );
    expect(normalizeTemplatePath("/bildirimler/${bildirimId}/iptal")).toBe(
      "/bildirimler/:bildirimId/iptal"
    );
  });

  it("strips query strings from parity keys", () => {
    expect(stripPathNoise("/haftalik-kapanis/yillik-fazla-calisma?personel_id=1&yil=2026")).toBe(
      "/haftalik-kapanis/yillik-fazla-calisma"
    );
    expect(
      normalizeTemplatePath(
        "/haftalik-kapanis/yillik-fazla-calisma?personel_id=${personelId}&yil=${yil}"
      )
    ).toBe("/haftalik-kapanis/yillik-fazla-calisma");
  });

  it("normalizes trailing slash, double slash, and /api prefix", () => {
    expect(normalizePath("/api/surecler/")).toBe("/surecler");
    expect(normalizePath("/personelmedisa/api/zimmetler")).toBe("/zimmetler");
    expect(normalizePath("//referans//departmanlar//")).toBe("/referans/departmanlar");
  });

  it("pathCovers matches parametric templates to concrete paths", () => {
    expect(pathCovers("/raporlar/:id", "/raporlar/bildirim")).toBe(true);
    expect(pathCovers("/raporlar/:id", "/raporlar/izin")).toBe(true);
    expect(pathCovers("/surecler/:id", "/zimmetler/:id")).toBe(false);
    expect(pathCovers("/raporlar/bildirim", "/raporlar/:id")).toBe(false);
  });
});

describe("api-parity allowlist schema", () => {
  it("rejects invalid allowlist schema", () => {
    const { errors } = validateAllowlist({
      entries: [
        {
          method: "POST",
          path: "zimmetler",
          category: "known_active_gap",
          reason: "",
          owner: ""
        }
      ]
    });
    expect(errors.length).toBeGreaterThan(0);
    expect(errors.some((e) => e.includes("path"))).toBe(true);
    expect(errors.some((e) => e.includes("reason"))).toBe(true);
  });

  it("rejects duplicate allowlist keys", () => {
    const entry = {
      method: "POST",
      path: "/zimmetler",
      category: "known_active_gap",
      gapId: "P1-02",
      reason: "dup test",
      owner: "ZimmetlerController",
      targetPhase: "S78-C2"
    };
    const { errors } = validateAllowlist({ entries: [entry, { ...entry }] });
    expect(errors.some((e) => e.includes("duplicate"))).toBe(true);
  });

  it("requires targetPhase for known_active_gap and approved_deferred", () => {
    const { errors } = validateAllowlist({
      entries: [
        {
          method: "POST",
          path: "/zimmetler",
          category: "known_active_gap",
          gapId: "P1-02",
          reason: "missing phase",
          owner: "ZimmetlerController"
        }
      ]
    });
    expect(errors.some((e) => e.includes("targetPhase"))).toBe(true);
  });
});

describe("api-parity classify", () => {
  it("detects client-only as fatal", () => {
    const { fatals } = classifyParity({
      client: [{ method: "POST", normalizedPath: "/new-only" }],
      demo: [],
      e2e: [],
      php: [],
      allowlist: []
    });
    expect(fatals.some((f) => f.classification === "CLIENT_ONLY")).toBe(true);
  });

  it("detects mock-only as fatal", () => {
    const { fatals } = classifyParity({
      client: [],
      demo: [{ method: "GET", normalizedPath: "/isg/makineler" }],
      e2e: [{ method: "GET", normalizedPath: "/isg/makineler" }],
      php: [],
      allowlist: []
    });
    expect(fatals.some((f) => f.classification === "MOCK_ONLY")).toBe(true);
  });

  it("emits PHP-only as warning", () => {
    const { warnings, fatals } = classifyParity({
      client: [],
      demo: [],
      e2e: [],
      php: [{ method: "GET", normalizedPath: "/health" }],
      allowlist: []
    });
    expect(fatals.length).toBe(0);
    expect(warnings.some((w) => w.classification === "PHP_ONLY")).toBe(true);
  });

  it("detects method drift between client and php", () => {
    const { fatals } = classifyParity({
      client: [
        { method: "PUT", normalizedPath: "/widgets/:id" },
        { method: "GET", normalizedPath: "/widgets/:id" }
      ],
      demo: [],
      e2e: [],
      php: [
        { method: "POST", normalizedPath: "/widgets/:id" },
        { method: "GET", normalizedPath: "/widgets/:id" }
      ],
      allowlist: []
    });
    expect(fatals.some((f) => f.classification === "METHOD_DRIFT")).toBe(true);
  });

  it("marks allowlisted known gap as non-fatal", () => {
    const { fatals, results } = classifyParity({
      client: [{ method: "PUT", normalizedPath: "/surecler/:id" }],
      demo: [{ method: "PUT", normalizedPath: "/surecler/:id" }],
      e2e: [{ method: "PUT", normalizedPath: "/surecler/:id" }],
      php: [],
      allowlist: [
        {
          method: "PUT",
          path: "/surecler/:id",
          category: "known_active_gap",
          gapId: "P1-01",
          reason: "test",
          owner: "SureclerController",
          targetPhase: "S78-C1"
        }
      ]
    });
    expect(fatals.length).toBe(0);
    expect(results.some((r) => r.classification === "ALLOWLISTED_KNOWN_GAP")).toBe(true);
  });

  it("marks allowlisted deferred as non-fatal", () => {
    const { fatals, results } = classifyParity({
      client: [{ method: "POST", normalizedPath: "/serbest-zaman/olusum" }],
      demo: [{ method: "POST", normalizedPath: "/serbest-zaman/olusum" }],
      e2e: [],
      php: [],
      allowlist: [
        {
          method: "POST",
          path: "/serbest-zaman/olusum",
          category: "approved_deferred",
          gapId: "D-SZ-03",
          reason: "deferred test",
          owner: "SerbestZaman",
          targetPhase: "S78-D"
        }
      ]
    });
    expect(fatals.length).toBe(0);
    expect(results.some((r) => r.classification === "ALLOWLISTED_DEFERRED")).toBe(true);
  });

  it("detects stale allowlist entries", () => {
    const { staleAllowlist, fatals } = classifyParity({
      client: [
        { method: "GET", normalizedPath: "/health" },
        { method: "GET", normalizedPath: "/health" }
      ],
      demo: [{ method: "GET", normalizedPath: "/health" }],
      e2e: [{ method: "GET", normalizedPath: "/health" }],
      php: [{ method: "GET", normalizedPath: "/health" }],
      allowlist: [
        {
          method: "GET",
          path: "/health",
          category: "known_active_gap",
          gapId: "STALE",
          reason: "no longer a gap",
          owner: "Router",
          targetPhase: "S78-C1"
        }
      ]
    });
    expect(staleAllowlist.length).toBe(1);
    expect(fatals.some((f) => String(f.detail ?? "").includes("stale"))).toBe(true);
  });
});

describe("api-parity integration (canonical repo)", () => {
  it("passes the gate on canonical sources", () => {
    const report = runApiParityGate(root);
    expect(report.allowlistErrors).toEqual([]);
    expect(report.summary.pass).toBe(true);
    expect(report.summary.fatal).toBe(0);
  });

  it("surfaces S78-A P1 gaps as known_active_gap without claiming PHP ownership", () => {
    const report = runApiParityGate(root);
    const byGap = new Map();
    for (const g of report.knownGaps) {
      const m = g.match(/\[KNOWN_ACTIVE_GAP:(P1-\d+)\]\s+(\w+)\s+(\S+)/);
      expect(m).not.toBeNull();
      if (!m) continue;
      const [, gapId, method, path] = m;
      if (!byGap.has(gapId)) byGap.set(gapId, []);
      byGap.get(gapId).push(`${method} ${path}`);
    }

    expect(byGap.get("P1-01")).toBeUndefined();
    expect(byGap.get("P1-02")).toBeUndefined();
    expect(byGap.has("P1-03")).toBe(false);

    const php = report.inventories.php;
    const hasPhp = (method: string, path: string) =>
      php.some((r) => r.method === method && r.normalizedPath === path);
    expect(hasPhp("PUT", "/surecler/:id")).toBe(true);
    expect(hasPhp("POST", "/surecler/:id/iptal")).toBe(true);
    expect(hasPhp("GET", "/surecler/:id")).toBe(true);
    expect(hasPhp("POST", "/zimmetler")).toBe(true);
    expect(hasPhp("GET", "/zimmetler")).toBe(true);
    expect(hasPhp("POST", "/referans/departmanlar")).toBe(true);

    const departman = report.results.find(
      (r) => r.method === "POST" && r.normalizedPath === "/referans/departmanlar"
    );
    expect(departman?.classification).toBe("FULL_PARITY");

    for (const [method, path] of [
      ["GET", "/surecler/:id"],
      ["PUT", "/surecler/:id"],
      ["POST", "/surecler/:id/iptal"],
      ["GET", "/zimmetler"],
      ["POST", "/zimmetler"]
    ] as const) {
      const row = report.results.find((r) => r.method === method && r.normalizedPath === path);
      expect(row?.classification).toBe("FULL_PARITY");
    }

    expect(report.summary.knownGaps).toBe(0);
  });

  it("allowlist file has no remaining active P1 gaps", () => {
    const doc = JSON.parse(
      readFileSync(join(root, "scripts/api-parity-allowlist.json"), "utf8")
    );
    const { entries, errors } = validateAllowlist(doc);
    expect(errors).toEqual([]);
    expect(entries.some((e) => e.gapId === "P1-03")).toBe(false);
    expect(entries.some((e) => e.gapId === "P1-01")).toBe(false);
    expect(entries.some((e) => e.gapId === "P1-02")).toBe(false);
    expect(entries.every((e) => e.category !== "known_active_gap")).toBe(true);
  });
});
