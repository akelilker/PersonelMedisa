import { mkdir, readFile, readdir, writeFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { dirname, join, resolve } from 'node:path';

const repoRoot = resolve(process.argv[2] ?? process.cwd());
const outputPath = resolve(
  process.argv[3] ?? join(repoRoot, 'api/runtime-build/canonical-migrations.php'),
);
const ledgerPath = join(repoRoot, 'api/src/Database/migration_ledger.sql');
const migrationsDirectory = join(repoRoot, 'api/migrations');

const sources = [{ version: '000', name: 'migration_ledger.sql', path: ledgerPath }];
for (const name of (await readdir(migrationsDirectory)).sort()) {
  const match = /^(\d+)_([A-Za-z0-9_-]+)\.sql$/.exec(name);
  if (match) {
    sources.push({
      version: match[1].padStart(3, '0'),
      name,
      path: join(migrationsDirectory, name),
    });
  }
}

const entries = [];
const versions = new Set();
for (const source of sources) {
  if (versions.has(source.version)) {
    throw new Error(`Duplicate canonical migration version: ${source.version}`);
  }
  const bytes = await readFile(source.path);
  if (bytes.length === 0) {
    throw new Error(`Canonical migration source is empty: ${source.name}`);
  }
  versions.add(source.version);
  entries.push({
    version: source.version,
    name: source.name,
    checksum: createHash('sha256').update(bytes).digest('hex'),
    sqlBase64: bytes.toString('base64'),
  });
}

entries.sort((left, right) =>
  Number(left.version) - Number(right.version) || left.name.localeCompare(right.name),
);
if (entries.length === 0 || entries[0].version !== '000') {
  throw new Error('Canonical migration ledger bootstrap is missing.');
}

const phpString = (value) => `'${value.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;
const output = [
  '<?php',
  '',
  'declare(strict_types=1);',
  '',
  'return [',
  ...entries.flatMap((entry) => [
    '    [',
    `        'version' => ${phpString(entry.version)},`,
    `        'name' => ${phpString(entry.name)},`,
    `        'checksum' => ${phpString(entry.checksum)},`,
    `        'sql_base64' => ${phpString(entry.sqlBase64)},`,
    '    ],',
  ]),
  '];',
  '',
].join('\n');

await mkdir(dirname(outputPath), { recursive: true });
await writeFile(outputPath, output, 'utf8');
console.log(`Generated ${entries.length} canonical migrations at ${outputPath}`);
