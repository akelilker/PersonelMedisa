/**
 * Production-only stand-in for `./mock-demo`.
 *
 * `vite.config.ts` aliases every `./mock-demo` import (the static one in
 * `api-client.ts` and the dynamic ones in feature APIs) to this module when the
 * build runs in production mode. That keeps the demo/mock seed payload out of
 * the deployed artifact while leaving dev/demo and test runs untouched.
 *
 * Contract: mirrors `mock-demo.resolveDemoApiResponse`. Production never wants
 * demo responses, so this always reports "no demo response".
 */
import type { ApiResponse } from "../types/api";

export function resolveDemoApiResponse(_path: string, _init?: RequestInit): ApiResponse<unknown> | null {
  return null;
}
