import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

const service = readFileSync("api/src/Services/SirketCalismaPolitikasiService.php", "utf8");
const roles = readFileSync("api/src/Auth/RolePermissions.php", "utf8");
const controller = readFileSync("api/src/Controllers/SirketCalismaPolitikasiController.php", "utf8");

describe("S98 sirket calisma dual-control successor", () => {
  it("clamps predecessor end to predecessor start for same-day supersede", () => {
    expect(service).toContain("clamped to predecessor start");
    expect(service).toContain("if ($end < $prevStart)");
    expect(service).toContain("$end = $prevStart");
  });

  it("links successor draft to open approved parent with bumped revision", () => {
    expect(service).toContain("WHERE state = 'ONAYLANDI' AND gecerlilik_bitis IS NULL");
    expect(service).toContain("$revision = ((int) $openApproved['revision_no']) + 1");
    expect(service).toContain("$parentId = (int) $openApproved['id']");
  });

  it("grants prepare/approve split for company policy dual-control actors", () => {
    const ikBlock = roles.slice(roles.indexOf("'IK_BORDRO'"), roles.indexOf("'SGK_KARAR_ONAY_YETKILISI'"));
    const apprBlock = roles.slice(
      roles.indexOf("'SGK_KARAR_ONAY_YETKILISI'"),
      roles.indexOf("];", roles.indexOf("'SGK_KARAR_ONAY_YETKILISI'")) + 2
    );
    expect(ikBlock).toContain("sirket_parametreleri.view");
    expect(ikBlock).toContain("sirket_parametreleri.manage");
    expect(ikBlock).not.toContain("bordro_kesinlestirme.approve");
    expect(apprBlock).toContain("sirket_parametreleri.view");
    expect(apprBlock).toContain("bordro_kesinlestirme.approve");
    expect(apprBlock).not.toContain("sirket_parametreleri.manage");
  });

  it("allows preparer or approver to open karar ozeti", () => {
    expect(controller).toContain("sirket_parametreleri.manage");
    expect(controller).toContain("bordro_kesinlestirme.approve");
  });
});
