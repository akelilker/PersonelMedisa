import fs from "fs";

const tracePath = process.argv[2];
const trace = JSON.parse(fs.readFileSync(tracePath, "utf8"));
const events = trace.traceEvents ?? [];

let minTs = Infinity;
for (const e of events) {
  if (e.ts) minTs = Math.min(minTs, e.ts);
}

const rel = (ts) => ((ts - minTs) / 1000).toFixed(1);

// Navigation timing per frame
const navMarks = [];
for (const e of events) {
  if (
    [
      "navigationStart",
      "fetchStart",
      "responseEnd",
      "domLoading",
      "domInteractive",
      "domContentLoadedEventStart",
      "domContentLoadedEventEnd",
      "domComplete",
      "loadEventStart",
      "loadEventEnd",
      "firstPaint",
      "firstContentfulPaint",
      "largestContentfulPaint::Candidate",
    ].includes(e.name)
  ) {
    navMarks.push({
      name: e.name,
      ms: rel(e.ts),
      url: e.args?.data?.documentLoaderURL ?? e.args?.data?.url ?? "",
      frame: (e.args?.frame ?? e.tid ?? "").toString().slice(0, 8),
    });
  }
}

console.log("=== NAV / PAINT MARKS ===");
const seen = new Set();
for (const m of navMarks) {
  const key = m.name + m.ms + m.url;
  if (seen.has(key)) continue;
  seen.add(key);
  console.log(`${m.ms}ms\t${m.name}\t${m.url || ""}`);
}

// Resource timing
const resources = new Map();
for (const e of events) {
  if (e.name === "ResourceSendRequest") {
    const id = e.args?.data?.requestId;
    if (!id) continue;
    resources.set(id, {
      url: e.args?.data?.url ?? "",
      start: e.ts,
      mime: e.args?.data?.mimeType ?? "",
    });
  }
  if (e.name === "ResourceFinish") {
    const id = e.args?.data?.requestId;
    const r = resources.get(id);
    if (r) {
      r.end = e.ts;
      r.dur = (e.ts - r.start) / 1000;
    }
  }
}

const resList = [...resources.values()]
  .filter((r) => r.dur != null && r.url)
  .sort((a, b) => b.dur - a.dur);

console.log("\n=== SLOWEST RESOURCES (ms) ===");
for (const r of resList.slice(0, 25)) {
  const short = r.url.length > 100 ? "..." + r.url.slice(-97) : r.url;
  console.log(`${r.dur.toFixed(0)}ms\t${short}`);
}

// Long task detail - find child FunctionCall
const longAt = [56357, 69234, 79982];
for (const target of longAt) {
  const windowUs = 2000000;
  const center = minTs + target * 1000;
  const calls = events
    .filter(
      (e) =>
        e.name === "FunctionCall" &&
        e.dur >= 20000 &&
        e.ts >= center - 50000 &&
        e.ts <= center + windowUs
    )
    .map((e) => ({
      dur: e.dur / 1000,
      at: rel(e.ts),
      url: (e.args?.data?.url ?? e.args?.url ?? "").slice(-90),
      fn: e.args?.data?.functionName ?? "",
    }))
    .sort((a, b) => b.dur - a.dur);
  console.log(`\n=== HEAVY JS near ${target}ms ===`);
  for (const c of calls.slice(0, 8)) console.log(`  ${c.dur.toFixed(0)}ms @${c.at}ms ${c.fn} ${c.url}`);
}

// Input latency
const inputLat = events.filter((e) => e.name === "InputLatency::MouseMove");
let maxInput = 0;
for (const e of inputLat) {
  const d = e.dur ?? 0;
  if (d > maxInput) maxInput = d;
}
console.log("\n=== INPUT ===");
console.log("MouseMove events:", inputLat.length);
console.log("Max mouse move handler (ms):", (maxInput / 1000).toFixed(1));

// Main thread busy %
let mainBusy = 0;
let mainTotal = 0;
for (const e of events) {
  if (e.cat?.includes("devtools.timeline") && e.name === "RunTask" && e.dur) {
    mainBusy += e.dur;
    mainTotal = Math.max(mainTotal, e.ts + e.dur - minTs);
  }
}
console.log("\n=== MAIN THREAD ===");
console.log("RunTask total busy (ms):", (mainBusy / 1000).toFixed(0));
console.log("Trace span (ms):", (mainTotal / 1000).toFixed(0));
console.log("Busy ratio:", ((mainBusy / mainTotal) * 100).toFixed(1) + "%");
