import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

describe("Request raw body owner", () => {
  it("exposes getRawBody for policy/tatil request-hash callers", () => {
    const source = readFileSync(resolve(process.cwd(), "api/src/Http/Request.php"), "utf8");
    expect(source).toContain("function getRawBody");
    expect(source).toContain("loadRawBody");
    expect(source).toContain("php://input");

    const politika = readFileSync(
      resolve(process.cwd(), "api/src/Controllers/SirketCalismaPolitikasiController.php"),
      "utf8"
    );
    expect(politika).toContain("getRawBody()");
  });
});
