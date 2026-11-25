// Refresh official dates and names: node scripts/update-japanese-holidays.mjs
// Offline backend sync / CI consistency check: append --sync / --check.
import { mkdir, readFile, writeFile } from "node:fs/promises";

const source = "https://www8.cao.go.jp/chosei/shukujitsu/syukujitsu.csv";
const frontendPath = new URL("../lib/japanese-holidays.json", import.meta.url);
const backendPath = new URL("../../../backend-laravel-app/resources/calendar/japanese-holidays.json", import.meta.url);
const mode = process.argv[2];
if (mode && !["--sync", "--check"].includes(mode)) throw new Error(`Unknown mode: ${mode}`);

let calendar;
if (mode) {
  calendar = JSON.parse(await readFile(frontendPath, "utf8"));
} else {
  const response = await fetch(source);
  if (!response.ok) throw new Error(`Holiday download failed: ${response.status}`);
  const csv = new TextDecoder("shift_jis").decode(await response.arrayBuffer());
  const entries = csv.split(/\r?\n/).flatMap((line) => {
    const match = /^(\d{4})\/(\d{1,2})\/(\d{1,2}),(.+)$/.exec(line);
    return match ? [[`${match[1]}-${match[2].padStart(2, "0")}-${match[3].padStart(2, "0")}`, match[4].trim()]] : [];
  }).sort(([a], [b]) => a.localeCompare(b));
  const names = Object.fromEntries(entries);
  const dates = Object.keys(names);
  calendar = { source, firstYear: Number(dates[0]?.slice(0, 4)), lastYear: Number(dates.at(-1)?.slice(0, 4)), dates, names };
}
if (calendar.source !== source || calendar.dates?.length < 100 || !calendar.dates.includes("2026-09-22")
  || calendar.dates.some((date) => !/^\d{4}-\d{2}-\d{2}$/.test(date) || !calendar.names?.[date])
  || Object.keys(calendar.names ?? {}).length !== calendar.dates.length) {
  throw new Error("Unexpected Cabinet Office holiday data; the existing calendar was not changed.");
}
const content = `${JSON.stringify(calendar, null, 2)}\n`;
if (mode === "--check") {
  if (await readFile(backendPath, "utf8") !== content || await readFile(frontendPath, "utf8") !== content) {
    throw new Error("Holiday copies differ; run node scripts/update-japanese-holidays.mjs --sync before building/releasing.");
  }
} else {
  await mkdir(new URL(".", backendPath), { recursive: true });
  if (!mode) await writeFile(frontendPath, content, "utf8");
  await writeFile(backendPath, content, "utf8");
}
console.log(`Japanese holidays ${mode === "--check" ? "verified" : "synced"}: ${calendar.firstYear}-${calendar.lastYear}, ${calendar.dates.length} dates`);
