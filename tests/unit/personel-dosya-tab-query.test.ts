import { describe, expect, it } from "vitest";
import {
  personelTabQueryValue,
  resolvePersonelTab
} from "../../src/features/personeller/components/personel-dosya/personel-dosya-tab-query";

describe("personel-dosya-tab-query", () => {
  it("maps legacy genel/ucret aliases to genel-bilgiler", () => {
    expect(resolvePersonelTab("genel")).toBe("genel-bilgiler");
    expect(resolvePersonelTab("ucret")).toBe("genel-bilgiler");
  });

  it("accepts canonical tab ids", () => {
    expect(resolvePersonelTab("disiplin")).toBe("disiplin");
    expect(resolvePersonelTab("surec-gecmisi")).toBe("surec-gecmisi");
  });

  it("rejects unknown tab values", () => {
    expect(resolvePersonelTab("unknown")).toBeNull();
    expect(resolvePersonelTab("")).toBeNull();
    expect(resolvePersonelTab(null)).toBeNull();
  });

  it("writes canonical query values", () => {
    expect(personelTabQueryValue("genel-bilgiler")).toBe("genel-bilgiler");
    expect(personelTabQueryValue("egitim-belgeler")).toBe("egitim-belgeler");
  });
});
