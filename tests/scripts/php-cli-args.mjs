import { spawnSync } from "node:child_process";
import { dirname, resolve } from "node:path";

/**
 * Quiet PHP CLI args for JSON / runtime test runners.
 * Suppresses display_errors so startup diagnostics do not pollute stdout,
 * and optionally loads Windows extensions that are commented out in php.ini.
 *
 * @param {string[]} [extraWindowsExtensions]
 * @returns {string[]}
 */
export function phpQuietCliArgs(extraWindowsExtensions = []) {
  const args = ["-d", "display_errors=0"];

  if (process.platform !== "win32") {
    for (const name of extraWindowsExtensions) {
      args.push("-d", `extension=${name}`);
    }
    return args;
  }

  let phpPath = "php";
  try {
    phpPath = spawnSync("where.exe", ["php"], { encoding: "utf8" }).stdout.split(/\r?\n/)[0].trim() || "php";
  } catch {
    phpPath = "php";
  }

  const extensionDir = resolve(dirname(phpPath), "ext");
  args.push("-d", `extension_dir=${extensionDir}`);

  for (const name of extraWindowsExtensions) {
    const dll = name.endsWith(".dll") ? name : `php_${name}.dll`;
    args.push("-d", `extension=${dll}`);
  }

  return args;
}
