import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

function read(path: string): string {
  return readFileSync(resolve(process.cwd(), path), "utf8");
}

describe("post-PR470 self-service gap closure sources", () => {
  it("registers bordro okudum, rapor talep, and inbox notifier routes", () => {
    const router = read("api/src/Router.php");
    expect(router).toContain("'/me/bordrolar'");
    expect(router).toContain("'/me/rapor-talepleri'");
    expect(router).toContain("'/me/raporlar'");
    expect(router).toContain("bordroOkudum");

    const migration = read("api/migrations/096_personel_bordro_okumalari.sql");
    expect(migration).toContain("personel_bordro_okumalari");
    expect(migration).not.toMatch(/^\s*INSERT\s+/im);

    const bordro = read("api/src/Services/SelfService/PersonelBordroOkumaService.php");
    expect(bordro).toContain("KESINLESTI");
    expect(bordro).toContain("ON DUPLICATE KEY UPDATE");

    const raporOwner = read("api/src/Controllers/SureclerController.php");
    expect(raporOwner).toContain("createSelfRapor");
    expect(raporOwner).toContain("'Raporlu_Hastalik'");
    expect(raporOwner).toContain("PersonelBelgeLinkedRaporAttachmentService");
    expect(raporOwner).toContain("IS_KAZASI excluded");

    const belgeLink = read("api/src/Services/PersonelBelge/PersonelBelgeLinkedRaporAttachmentService.php");
    expect(belgeLink).toContain("PersonelBelgeKayitRepository::insertAudit");
    expect(belgeLink).toContain("deleteKey");

    const notifier = read("api/src/Services/SelfService/SelfRequestInboxNotifier.php");
    expect(notifier).toContain("SELF_IZIN_REQUEST");
    expect(notifier).toContain("SELF_AVANS_REQUEST");
    expect(notifier).toContain("SELF_RAPOR_REQUEST");
  });

  it("wires shell inbox deep links for managers with correction decide", () => {
    const shell = read("src/components/shell/ShellHeaderActions.tsx");
    expect(shell).toContain("resolveInboxNotificationDestination");
    expect(shell).toContain("attendance.correction.decide");
    expect(shell).toContain("navigateTo(notification.route)");
  });
});
