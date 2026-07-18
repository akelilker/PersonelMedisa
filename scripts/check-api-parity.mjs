#!/usr/bin/env node
/**
 * CLI: npm run check:api-parity
 */

import { runApiParityGate, printParityReport } from "./api-parity/index.mjs";

const report = runApiParityGate();
printParityReport(report);
process.exit(report.summary.pass ? 0 : 1);
