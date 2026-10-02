import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const read = (path: string) => readFileSync(resolve(process.cwd(), path), "utf8");

describe("post-PR472 rapor canonical owners", () => {
  it("creates RAPOR via SureclerController owner with belge attachment service", () => {
    const controller = read("api/src/Controllers/SureclerController.php");
    expect(controller).toContain("public static function createSelfRapor");
    expect(controller).toContain("normalizeAndValidateCreatePayload");
    expect(controller).toContain("assertPeriodOpenForOperationalSurec");
    expect(controller).toContain("assertNoCoveringAbsenceOverlap");
    expect(controller).toContain("PersonelBelgeLinkedRaporAttachmentService::attachOptionalFile");
    expect(controller).toMatch(/notifyRaporRequest[\s\S]*catch \(\\Throwable \$notifyError\)/);
  });
});
