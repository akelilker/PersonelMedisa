import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import {
  ISTEN_AYRILMA_CANCEL_NOT_ALLOWED_CODE,
  ISTEN_AYRILMA_CANCEL_NOT_ALLOWED_MESSAGE,
  ISTEN_AYRILMA_SUREC_TURU,
  isSurecTuruCancelBlocked
} from "../../src/lib/surec-cancel-policy";

const root = process.cwd();

function read(relativePath: string): string {
  return readFileSync(resolve(root, relativePath), "utf8");
}

const CONTROLLER_PATH = "api/src/Controllers/SureclerController.php";
const PAGE_PATH = "src/features/surecler/pages/SurecTakipPage.tsx";
const HOOK_PATH = "src/hooks/useSurecler.ts";
const DEMO_PATH = "src/api/mock-demo.ts";

function cancelFunctionBody(controller: string): string {
  const cancelStart = controller.indexOf("public static function cancel(");
  const nextMethod = controller.indexOf("private static function normalizeAndValidateUpdatePayload(");
  expect(cancelStart).toBeGreaterThan(-1);
  expect(nextMethod).toBeGreaterThan(cancelStart);

  return controller.slice(cancelStart, nextMethod);
}

describe("ISTEN_AYRILMA generic cancel guard source contract", () => {
  it("policy blocks only ISTEN_AYRILMA (generic cancel contract preserved)", () => {
    expect(ISTEN_AYRILMA_SUREC_TURU).toBe("ISTEN_AYRILMA");
    expect(ISTEN_AYRILMA_CANCEL_NOT_ALLOWED_CODE).toBe("ISTEN_AYRILMA_CANCEL_NOT_ALLOWED");
    expect(ISTEN_AYRILMA_CANCEL_NOT_ALLOWED_MESSAGE).toContain("manuel iptal edilemez");
    expect(ISTEN_AYRILMA_CANCEL_NOT_ALLOWED_MESSAGE).toContain("Yeniden aktif akışı");

    expect(isSurecTuruCancelBlocked("ISTEN_AYRILMA")).toBe(true);
    expect(isSurecTuruCancelBlocked("isten_ayrilma")).toBe(true);
    expect(isSurecTuruCancelBlocked(" ISTEN_AYRILMA ")).toBe(true);

    // Other surec types keep the existing cancel behaviour.
    for (const other of ["IZIN", "RAPOR", "IS_KAZASI", "DEVAMSIZLIK", "", null, undefined]) {
      expect(isSurecTuruCancelBlocked(other as string | null | undefined)).toBe(false);
    }
  });

  it("SureclerController::cancel is fail-closed for ISTEN_AYRILMA before any write", () => {
    const cancelBody = cancelFunctionBody(read(CONTROLLER_PATH));

    expect(cancelBody).toContain("ISTEN_AYRILMA_CANCEL_NOT_ALLOWED");
    expect(cancelBody).toContain("JsonResponse::error(");
    expect(cancelBody).toContain("409");
    expect(cancelBody).toContain("İşten ayrılma süreci manuel iptal edilemez. Yeniden aktif akışı kullanılmalıdır.");
    // Scoped to the exit type only; the generic state transitions stay untouched.
    expect(cancelBody).toContain("=== 'ISTEN_AYRILMA'");
    expect(cancelBody).toContain("$state === 'TAMAMLANDI'");
    expect(cancelBody).toContain("UPDATE surecler");

    // Guard runs before the idempotent replay and before the UPDATE mutation.
    expect(cancelBody.indexOf("ISTEN_AYRILMA_CANCEL_NOT_ALLOWED")).toBeLessThan(
      cancelBody.indexOf("// Idempotent: already cancelled.")
    );
    expect(cancelBody.indexOf("ISTEN_AYRILMA_CANCEL_NOT_ALLOWED")).toBeLessThan(
      cancelBody.indexOf("UPDATE surecler")
    );
    // Still no hard delete (soft cancel contract).
    expect(cancelBody).not.toContain("DELETE FROM surecler");
  });

  it("exit guard reason is the orphan PASIF path owned by yeniden-aktif", () => {
    const cancelBody = cancelFunctionBody(read(CONTROLLER_PATH));
    expect(cancelBody).toContain("PersonelIstenAyrilmaService");
    expect(cancelBody).toContain("resolveSingleOpenExitSurec");

    const yenidenAktif = read("api/src/Services/Personel/PersonelYenidenAktifService.php");
    // The symmetric flow still requires exactly one open exit surec.
    expect(yenidenAktif).toContain("resolveSingleOpenExitSurec");
    expect(yenidenAktif).toContain("ERROR_EXIT_SUREC_MISSING");
  });

  it("Surec Takip page hides the cancel action for ISTEN_AYRILMA rows only", () => {
    const page = read(PAGE_PATH);
    expect(page).toContain("isSurecTuruCancelBlocked");
    expect(page).toContain("canCancelThisSurec");
    expect(page).toContain("return canCancelSurec && !isSurecTuruCancelBlocked(surec.surec_turu);");
    expect(page).toContain("{canCancelThisSurec(surec) ? (");
  });

  it("useSurecler hooks fail closed before calling the cancel API", () => {
    const hook = read(HOOK_PATH);
    expect(hook).toContain("isSurecTuruCancelBlocked");
    expect(hook).toContain("ISTEN_AYRILMA_CANCEL_NOT_ALLOWED_MESSAGE");

    const openStart = hook.indexOf("const openCancelSurecDialog = useCallback(");
    const openEnd = hook.indexOf("const closeCancelSurecDialog = useCallback(");
    const confirmEnd = hook.indexOf(
      "[cancelingSurecId, listKey, pendingCancelSurec, refreshPageOne]",
      openEnd
    );
    expect(openStart).toBeGreaterThan(-1);
    expect(openEnd).toBeGreaterThan(openStart);
    expect(confirmEnd).toBeGreaterThan(openEnd);
    const openBody = hook.slice(openStart, openEnd);
    const confirmBody = hook.slice(openEnd, confirmEnd);

    // Blocked row never opens the dialog, so pendingCancelSurec stays null.
    expect(openBody).toContain("isSurecTuruCancelBlocked(surec.surec_turu)");
    expect(openBody.indexOf("isSurecTuruCancelBlocked(surec.surec_turu)")).toBeLessThan(
      openBody.indexOf("setPendingCancelSurec(surec)")
    );
    // Defense in depth: confirm also refuses before cancelSurec is called.
    expect(confirmBody).toContain("isSurecTuruCancelBlocked(surec.surec_turu)");
    expect(confirmBody.indexOf("isSurecTuruCancelBlocked(surec.surec_turu)")).toBeLessThan(
      confirmBody.indexOf("await cancelSurec(surec.id)")
    );
  });

  it("demo API mirrors the backend 409 contract", () => {
    const demo = read(DEMO_PATH);
    const cancelStart = demo.indexOf("const surecCancelMatch");
    const cancelEnd = demo.indexOf('if (pathname === "/bildirimler" && method === "GET")', cancelStart);
    const cancelBody = demo.slice(cancelStart, cancelEnd);
    expect(cancelBody).toContain("isSurecTuruCancelBlocked(surec.surec_turu)");
    expect(cancelBody).toContain("ISTEN_AYRILMA_CANCEL_NOT_ALLOWED_CODE");
    expect(cancelBody).toContain('surec.state = "IPTAL";');
  });
});
