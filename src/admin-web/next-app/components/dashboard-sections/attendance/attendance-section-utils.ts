import type { AttendanceDailyBreak, AttendanceMonthCloseCheckItem } from "@/types";

export function isoToTime(value?: string | null) {
  if (!value) {
    return "";
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return "";
  }
  return `${date.getHours()}`.padStart(2, "0") + ":" + `${date.getMinutes()}`.padStart(2, "0");
}

export function normalizeBreaks(breaks?: AttendanceDailyBreak[]): AttendanceDailyBreak[] {
  const initial = breaks && breaks.length > 0 ? breaks : [{ startTime: "", endTime: "" }];
  return initial.map((item, index) => ({
    segmentNo: item.segmentNo ?? index + 1,
    startTime: item.startTime ?? isoToTime(item.startAt),
    endTime: item.endTime ?? isoToTime(item.endAt),
    startNextDay: Boolean(item.startNextDay),
    endNextDay: Boolean(item.endNextDay),
  }));
}

export function formatHandlingStatus(value?: string | null) {
  return {
    OPEN: "未対応",
    IN_PROGRESS: "対応中",
    RESOLVED: "対応済み",
    IGNORED: "対象外",
  }[value ?? "OPEN"] ?? value ?? "-";
}

export function formatCheckItems(items?: AttendanceMonthCloseCheckItem[]) {
  if (!items || items.length === 0) {
    return "未処理項目はありません。";
  }

  return items.map((item) => `${item.label} ${item.count}件`).join(" / ");
}

export function formatErrorHistory(row: {
  histories?: Array<{
    newStatus: string;
    comment?: string | null;
    handledAt: string;
    handledByName?: string | null;
  }>;
}, formatDateTime: (value?: string | null) => string) {
  const histories = row.histories ?? [];
  if (histories.length === 0) {
    return "-";
  }

  return histories
    .slice(-3)
    .map((history) => `${formatDateTime(history.handledAt)} ${history.handledByName ?? "-"} ${formatHandlingStatus(history.newStatus)}${history.comment ? `: ${history.comment}` : ""}`)
    .join(" / ");
}

export function isNextDay(value?: string | null, targetDate?: string | null) {
  if (!value || !targetDate) {
    return false;
  }
  return value.slice(0, 10) > targetDate;
}
