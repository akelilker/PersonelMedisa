import { stopDisposableMariaDb } from "./disposable-mariadb.mjs";

export default async function vitestMariaDbTeardown() {
  await stopDisposableMariaDb();
}
