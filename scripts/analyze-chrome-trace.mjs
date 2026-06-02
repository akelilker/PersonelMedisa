import fs from "fs";
import readline from "readline";

const tracePath = process.argv[2];
if (!tracePath) {
  console.error("Usage: node analyze-chrome-trace.mjs <trace.json>");
  process.exit(1);
}

const raw = fs.readFileSync(tracePath, "utf8");
const trace = JSON.parse(raw);

const meta = trace.metadata ?? {};
const events = trace.traceEvents ?? [];

console.log("=== TRACE META ===");
console.log("URL:", meta.cruxFieldData?.[0]?.normalizedUrl ?? "(unknown)");
console.log("Start:", meta.startTime);
console.log("Events:", events.length);

const byName = new Map();
const longTasks = [];
const scripts = [];
const network = [];
const layouts = [];
const paints = [];
const gc = [];
let minTs = Infinity;
let maxTs = -Infinity;

for (const e of events) {
  const ts = e.ts ?? 0;
  const dur = e.dur ?? 0;
  if (ts) {
    minTs = Math.min(minTs, ts);
    maxTs = Math.max(maxTs, ts + dur);
  }
  const name = e.name ?? "";
  byName.set(name, (byName.get(name) ?? 0) + 1);

  if (name === "RunTask" && dur >= 50000) {
    longTasks.push({ ts, dur, cat: e.cat, args: e.args });
  }
  if (
    (name === "EvaluateScript" || name === "FunctionCall" || name === "v8.compile") &&
    dur >= 10000
  ) {
    scripts.push({ name, ts, dur, url: e.args?.data?.url ?? e.args?.url ?? "" });
  }
  if (name === "Layout" && dur >= 5000) {
    layouts.push({ ts, dur });
  }
  if ((name === "Paint" || name === "PaintImage") && dur >= 5000) {
    paints.push({ ts, dur });
  }
  if (name.includes("GC") || name === "MajorGC" || name === "MinorGC") {
    if (dur >= 5000) gc.push({ name, ts, dur });
  }
  if (name === "ResourceSendRequest" || name === "ResourceReceiveResponse") {
    network.push(e);
  }
}

const durationMs = (maxTs - minTs) / 1000;
console.log("Duration (ms):", Math.round(durationMs));

// Web Vitals markers in trace
const vitals = {};
for (const e of events) {
  if (e.name === "largestContentfulPaint::Candidate") {
    vitals.lcp = (e.ts - minTs) / 1000;
  }
  if (e.name === "firstContentfulPaint") {
    vitals.fcp = (e.ts - minTs) / 1000;
  }
  if (e.name === "navigationStart") {
    vitals.navStart = e.ts;
  }
  if (e.name === "InteractiveTime") {
    vitals.tti = (e.ts - minTs) / 1000;
  }
  if (e.name === "DomContentLoaded") {
    vitals.dcl = (e.ts - minTs) / 1000;
  }
  if (e.name === "Load") {
    vitals.load = (e.ts - minTs) / 1000;
  }
}

console.log("\n=== TIMING (from trace start, ms) ===");
for (const [k, v] of Object.entries(vitals)) {
  if (typeof v === "number" && k !== "navStart") console.log(k + ":", Math.round(v));
}

longTasks.sort((a, b) => b.dur - a.dur);
scripts.sort((a, b) => b.dur - a.dur);
layouts.sort((a, b) => b.dur - a.dur);
paints.sort((a, b) => b.dur - a.dur);
gc.sort((a, b) => b.dur - a.dur);

console.log("\n=== LONG TASKS (>=50ms):", longTasks.length, "===");
for (const t of longTasks.slice(0, 15)) {
  console.log(
    `  ${(t.dur / 1000).toFixed(1)}ms @${((t.ts - minTs) / 1000).toFixed(0)}ms cat=${t.cat}`
  );
}

console.log("\n=== TOP SCRIPT (>=10ms):", scripts.length, "total ===");
for (const s of scripts.slice(0, 12)) {
  const u = (s.url || "").slice(-80);
  console.log(`  ${(s.dur / 1000).toFixed(1)}ms ${s.name} ${u}`);
}

console.log("\n=== TOP LAYOUT (>=5ms):", layouts.length);
for (const l of layouts.slice(0, 8)) {
  console.log(`  ${(l.dur / 1000).toFixed(1)}ms`);
}

console.log("\n=== TOP PAINT (>=5ms):", paints.length);
for (const p of paints.slice(0, 8)) {
  console.log(`  ${(p.dur / 1000).toFixed(1)}ms`);
}

console.log("\n=== GC spikes (>=5ms):", gc.length);
for (const g of gc.slice(0, 8)) {
  console.log(`  ${(g.dur / 1000).toFixed(1)}ms ${g.name}`);
}

// Frame / dropped frames
let dropped = 0;
let frames = 0;
for (const e of events) {
  if (e.name === "DroppedFrame") dropped++;
  if (e.name === "BeginFrame") frames++;
}
console.log("\n=== FRAMES ===");
console.log("BeginFrame:", frames, "DroppedFrame:", dropped);

// Top event names by count
const topNames = [...byName.entries()].sort((a, b) => b[1] - a[1]).slice(0, 20);
console.log("\n=== TOP EVENT TYPES (count) ===");
for (const [n, c] of topNames) console.log(`  ${c}\t${n}`);

// User timing marks
const userMarks = events.filter(
  (e) => e.cat?.includes("blink.user_timing") || e.name?.startsWith("mark_")
);
if (userMarks.length) {
  console.log("\n=== USER TIMING ===");
  for (const e of userMarks.slice(0, 20)) {
    console.log(e.name, e.args);
  }
}
