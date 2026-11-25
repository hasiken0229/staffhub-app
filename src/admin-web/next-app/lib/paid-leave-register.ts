import { formatEmployeeStatus } from "@/lib/api/formatters";
import type { FiscalLeaveYear, PaidLeaveRegister } from "@/types/paid-leave-register";

export const registerTitle = "年次有給休暇管理簿";
export const fiscalEntryKind = {
  OPENING: "年度開始", USE: "取得", GRANT: "付与", ADJUSTMENT: "調整", EXPIRE: "失効", BALANCE: "残高記録", CARRY: "繰越",
};
export const entryStatus = { RECORDED: "記帳済", REFERENCE: "参考・集計外", FUTURE: "予定・集計外" };
export const openingLabel = "4月1日・調整後の残";

export function daysText(value: number | null) {
  if (value === null) return "未確認";
  const rounded = Number(value.toFixed(3));
  return rounded === value ? `${value}日` : `約${rounded}日`;
}
export function minutesText(value: number | null) {
  if (value === null) return "未確認";
  const minutes = Math.abs(value);
  const sign = value < 0 ? "−" : "";
  return `${sign}${Math.floor(minutes / 60)}時間${minutes % 60 ? `${minutes % 60}分` : ""}`;
}
export function detailEntries(year: FiscalLeaveYear) {
  return year.entries.filter(row => row.kind !== "OPENING");
}
export function fiscalOpeningValue(year: FiscalLeaveYear, unit: keyof FiscalLeaveYear["opening"]) {
  return year.opening[unit] ?? (year.openingReason === "NOT_EMPLOYED" ? "入職前" : null);
}
export function fiscalOpeningText(year: FiscalLeaveYear, unit: keyof FiscalLeaveYear["opening"]) {
  const value = fiscalOpeningValue(year, unit);
  return typeof value === "string" ? value : unit === "days" ? daysText(value) : minutesText(value);
}
export function fiscalTableTitle(year: number) {
  return `${year}年度　取得・残数の明細`;
}

function csvCell(value: string | number | null) {
  if (value === null) return '""';
  const raw = String(value);
  const dangerous = typeof value === "string" && (/^[\s\uFEFF]*[=+\-@＝＋－＠]/u.test(raw) || /^[\t\r\n]/.test(raw));
  return '"' + (dangerous ? "'" : "") + raw.replaceAll('"', '""') + '"';
}
export function createRegisterCsv(report: PaidLeaveRegister) {
  const { employee, fiscalLedger } = report;
  const sheet: (string | number | null)[][] = [
    [registerTitle], ["職員番号", employee.code, "氏名", employee.name, "在籍区分", formatEmployeeStatus(employee.status)],
    ["対象期間", report.from, report.to],
  ];
  for (const year of fiscalLedger.years) {
    const days = fiscalOpeningValue(year, "days");
    const annual = fiscalOpeningValue(year, "annualMinutes");
    const total = fiscalOpeningValue(year, "totalMinutes");
    sheet.push([], [fiscalTableTitle(year.fiscalYear)], ["日付", "記帳区分", "日単位の取得（日）", "日数の増減（日）", "日数残（日）",
      "時間単位の取得（分）", "時間の増減（分）", "時間有給の年度枠残（分）", "時間有給の総残（分）", "年度開始残への反映"],
      [year.openingDate, openingLabel, null, null, days, null, null, annual, total, null]);
    for (const row of detailEntries(year)) {
      sheet.push([row.date, entryStatus[row.status], row.usedDays, row.dayChange, row.daysAfter,
        row.usedMinutes, row.minutesChange, row.annualMinutesAfter, row.totalMinutesAfter, row.includedInOpening ? "年度開始残に含む" : null]);
    }
    if (year.closingDate && year.closing) {
      sheet.push([year.closingDate, "集計日の残", null, null, year.closing.days, null, null,
        year.closing.annualMinutes, year.closing.totalMinutes, null]);
    }
  }
  return "\uFEFF" + sheet.map(row => row.map(csvCell).join(",")).join("\r\n") + "\r\n";
}
