// @vitest-environment jsdom

import { describe, expect, it } from "vitest";
import { createQrRequestNonce } from "../../src/api/qr.api";
import { startQrScanner } from "../../src/features/self-service/qr/qr-scanner";

const UUID_V4 =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

describe("S3C QR scanner UI helpers", () => {
  it("createQrRequestNonce returns UUID v4 shape", () => {
    const a = createQrRequestNonce();
    const b = createQrRequestNonce();
    expect(a).toMatch(UUID_V4);
    expect(b).toMatch(UUID_V4);
    expect(a).not.toBe(b);
  });

  it("exports startQrScanner for scan page wiring", () => {
    expect(typeof startQrScanner).toBe("function");
    expect(startQrScanner.name).toBe("startQrScanner");
  });
});
