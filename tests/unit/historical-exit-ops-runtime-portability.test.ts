import { mkdirSync, mkdtempSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, describe, expect, it } from "vitest";
import {
  CURL_BINARY_NOT_AVAILABLE,
  FTP_COMMAND_FAILED,
  FTP_NETRC_NOT_CONFIGURED,
  FTP_NETRC_NOT_READABLE,
  assertCurlBinaryAvailable,
  buildFtpFailure,
  defaultCurlBinary,
  isApplyRequested,
  resolveCurlBinary,
  resolveFtpNetrc,
  resolveLiveConfigPath,
  sanitizeDiagnosticText,
} from "../../ops/personnel-lifecycle/lib/historical-exit-backfill-gates.mjs";

const SHA = "1311196c0092486cf4df81413e10edab7acc13ae";
const tempDirs: string[] = [];

function makeTempDir(): string {
  const dir = mkdtempSync(join(tmpdir(), "medisa-ops-portability-"));
  tempDirs.push(dir);
  return dir;
}

afterEach(() => {
  while (tempDirs.length) {
    const dir = tempDirs.pop();
    try {
      rmSync(dir!, { recursive: true, force: true });
    } catch {
      /* ignore */
    }
  }
});

describe("ops runtime portability — curl resolver", () => {
  it("defaults to curl on Linux/macOS and curl.exe on Windows", () => {
    expect(defaultCurlBinary("linux")).toBe("curl");
    expect(defaultCurlBinary("darwin")).toBe("curl");
    expect(defaultCurlBinary("win32")).toBe("curl.exe");
    expect(resolveCurlBinary({ argv: [], env: {}, platform: "linux" }).binary).toBe("curl");
    expect(resolveCurlBinary({ argv: [], env: {}, platform: "win32" }).binary).toBe("curl.exe");
    expect(resolveCurlBinary({ argv: [], env: {}, platform: "linux" }).source).toBe(
      "platform_default"
    );
  });

  it("prefers CLI --curl-bin over env and platform default", () => {
    const resolved = resolveCurlBinary({
      argv: ["--curl-bin=/custom/curl"],
      env: { MEDISA_OPS_CURL_BIN: "/env/curl" },
      platform: "win32",
    });
    expect(resolved).toEqual({ source: "cli", binary: "/custom/curl" });
  });

  it("uses MEDISA_OPS_CURL_BIN when CLI is absent", () => {
    const resolved = resolveCurlBinary({
      argv: [`--expected-sha=${SHA}`],
      env: { MEDISA_OPS_CURL_BIN: "/usr/bin/curl" },
      platform: "win32",
    });
    expect(resolved).toEqual({ source: "env", binary: "/usr/bin/curl" });
  });

  it("fails closed when curl binary is unavailable", () => {
    expect(() =>
      assertCurlBinaryAvailable("definitely-not-a-real-curl-binary-xyz", {
        spawnSyncFn: () => ({ status: 127, error: new Error("ENOENT"), stderr: "not found" }),
      })
    ).toThrow(/not available/i);
    try {
      assertCurlBinaryAvailable("missing-curl", {
        spawnSyncFn: () => ({ status: 127, stderr: "not found" }),
      });
    } catch (e) {
      expect((e as { code?: string }).code).toBe(CURL_BINARY_NOT_AVAILABLE);
      const details = (e as { details?: { curl_bin?: string } }).details;
      expect(details?.curl_bin).toBe("missing-curl");
    }
  });
});

describe("ops runtime portability — ftp netrc resolver", () => {
  it("prefers explicit CLI --ftp-netrc path", () => {
    const dir = makeTempDir();
    const netrc = join(dir, "ftp.curl");
    writeFileSync(netrc, "machine example.com login u password p\n", { mode: 0o600 });
    const resolved = resolveFtpNetrc({
      argv: [`--ftp-netrc=${netrc}`],
      env: { MEDISA_OPS_FTP_NETRC: join(dir, "other.curl") },
    });
    expect(resolved.source).toBe("cli");
    expect(resolved.path).toBe(netrc);
  });

  it("uses MEDISA_OPS_FTP_NETRC when CLI is absent", () => {
    const dir = makeTempDir();
    const netrc = join(dir, "env-ftp.curl");
    writeFileSync(netrc, "machine example.com login u password p\n", { mode: 0o600 });
    const resolved = resolveFtpNetrc({
      argv: [],
      env: { MEDISA_OPS_FTP_NETRC: netrc, HOME: dir, USERPROFILE: dir },
    });
    expect(resolved).toEqual({ source: "env", path: netrc });
  });

  it("uses legacy Windows path only when it exists", () => {
    const home = makeTempDir();
    const privateDir = join(home, "Documents", "medisa-ops-tmp", "org-rollout", "private");
    mkdirSync(privateDir, { recursive: true });
    const legacy = join(privateDir, "ftp.curl");
    writeFileSync(legacy, "machine example.com login u password SECRET_NETRC_VALUE\n", {
      mode: 0o600,
    });
    const resolved = resolveFtpNetrc({
      argv: [],
      env: { HOME: home, USERPROFILE: home },
    });
    expect(resolved.source).toBe("legacy_windows");
    expect(resolved.path).toBe(legacy);
  });

  it("fails FTP_NETRC_NOT_CONFIGURED when no credential path is available", () => {
    const home = makeTempDir();
    try {
      resolveFtpNetrc({ argv: [], env: { HOME: home, USERPROFILE: home } });
      expect.unreachable("should have thrown");
    } catch (e) {
      expect((e as { code?: string }).code).toBe(FTP_NETRC_NOT_CONFIGURED);
      const msg = String((e as Error).message);
      expect(msg).not.toMatch(/password\s*=/i);
      expect(JSON.stringify((e as { details?: unknown }).details ?? {})).not.toMatch(
        /SECRET_NETRC_VALUE/
      );
    }
  });

  it("fails FTP_NETRC_NOT_READABLE for explicit missing path", () => {
    const missing = join(makeTempDir(), "missing-ftp.curl");
    try {
      resolveFtpNetrc({ argv: [`--ftp-netrc=${missing}`], env: {} });
      expect.unreachable("should have thrown");
    } catch (e) {
      expect((e as { code?: string }).code).toBe(FTP_NETRC_NOT_READABLE);
      expect(String((e as Error).message)).toContain("missing");
    }
  });
});

describe("ops runtime portability — live config + apply safety", () => {
  it("resolves live-config CLI > env > existing temp > FTP fetch needed", () => {
    const dir = makeTempDir();
    const explicit = join(dir, "explicit-config.local.php");
    writeFileSync(explicit, "<?php return [];");
    expect(
      resolveLiveConfigPath({
        argv: [`--live-config=${explicit}`],
        env: {},
        tempDir: dir,
      })
    ).toEqual({ source: "cli", path: explicit, needs_ftp_fetch: false });

    const envPath = join(dir, "env-config.local.php");
    expect(
      resolveLiveConfigPath({
        argv: [],
        env: { MEDISA_OPS_LIVE_CONFIG: envPath },
        tempDir: dir,
      })
    ).toEqual({ source: "env", path: envPath, needs_ftp_fetch: true });

    const existing = join(dir, "live-config.local.php");
    writeFileSync(existing, "<?php return [];");
    expect(
      resolveLiveConfigPath({ argv: [], env: {}, tempDir: dir })
    ).toEqual({ source: "temp_existing", path: existing, needs_ftp_fetch: false });

    const emptyTemp = makeTempDir();
    const def = resolveLiveConfigPath({ argv: [], env: {}, tempDir: emptyTemp });
    expect(def.source).toBe("temp_default");
    expect(def.needs_ftp_fetch).toBe(true);
  });

  it("expected-sha + ftp-netrc + curl-bin without --apply remains dry-run only", () => {
    const argv = [
      `--expected-sha=${SHA}`,
      "--curl-bin=curl",
      "--ftp-netrc=/tmp/example.curl",
      "--live-config=/tmp/live-config.local.php",
    ];
    expect(isApplyRequested(["node", "script.mjs", ...argv])).toBe(false);
    expect(isApplyRequested(["node", "script.mjs", ...argv, "--apply"])).toBe(true);
  });
});

describe("ops runtime portability — sanitized failure reporting", () => {
  it("never emits netrc content or jwt_secret in diagnostics", () => {
    const sanitized = sanitizeDiagnosticText(
      "login bob password SECRET_PASS jwt_secret => 'super-secret-jwt' machine x login a password b"
    );
    expect(sanitized).not.toContain("SECRET_PASS");
    expect(sanitized).not.toContain("super-secret-jwt");
    expect(sanitized).toMatch(/REDACTED/);

    const err = buildFtpFailure({
      code: FTP_COMMAND_FAILED,
      message: "FTP command failed",
      curlBin: "curl",
      exitStatus: 67,
      stderr: "Access denied password=SECRET_PASS",
      remote: "api/.deploy-sha",
      local: "/tmp/live-deploy-sha.txt",
      netrcPath: "/home/u/Documents/medisa-ops-tmp/org-rollout/private/ftp.curl",
    });
    expect(err.code).toBe(FTP_COMMAND_FAILED);
    const blob = JSON.stringify(err.details);
    expect(blob).not.toContain("SECRET_PASS");
    expect(blob).not.toMatch(/jwt_secret['"]?\s*:\s*['"][^R]/);
    expect(err.details.remote).toBe("api/.deploy-sha");
    expect(err.details.curl_bin).toBe("curl");
    expect(err.details.exit_status).toBe(67);
  });
});
