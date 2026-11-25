import type { AttendanceDailyBreak, AttendanceDailyDetail, AttendanceDailyUpdatePayload, AttendanceMonthCloseCheckItem } from "@/types";

export function buildDailyUpdatePayload(original: AttendanceDailyDetail, current: AttendanceDailyDetail, breaks: AttendanceDailyBreak[]): AttendanceDailyUpdatePayload {
  const payload: AttendanceDailyUpdatePayload = {};
  for (const field of ["workTypeId", "remark", "supervisorComment", "approvalComment"] as const) {
    if ((current[field] ?? null) !== (original[field] ?? null)) Object.assign(payload, {[field]: current[field] ?? null});
  }
  if (current.approvalStatus !== original.approvalStatus && ["PENDING", "APPROVED", "RETURNED"].includes(current.approvalStatus ?? "")) {
    payload.approvalStatus = current.approvalStatus;
  }
  for (const [field, timeKey, dayKey] of [["clockInAt", "clockInTime", "clockInNextDay"], ["clockOutAt", "clockOutTime", "clockOutNextDay"]] as const) {
    if (isoToTime(current[field]) !== isoToTime(original[field]) || isNextDay(current[field],current.targetDate) !== isNextDay(original[field],original.targetDate)) {
      Object.assign(payload, {[timeKey]:isoToTime(current[field]) || null,[dayKey]:isNextDay(current[field],current.targetDate)});
    }
  }
  const clean = (items: AttendanceDailyBreak[]) => normalizeBreaks(items).filter(item=>item.startTime||item.endTime).map(item=>({startTime:item.startTime??"",endTime:item.endTime??"",startNextDay:Boolean(item.startNextDay),endNextDay:Boolean(item.endNextDay)}));
  if (JSON.stringify(clean(breaks)) !== JSON.stringify(clean(original.breaks??[]))) payload.breaks = clean(breaks);
  return payload;
}

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

export function formatCheckItem(item: AttendanceMonthCloseCheckItem) {
  const unit = item.code === "PERIOD_NOT_FINISHED" ? "暦日"
    : ["UNSUBMITTED_DAILY", "PENDING_APPROVAL", "RETURNED_APPROVAL"].includes(item.code) ? "人日" : "件";
  return `${item.label} ${item.count}${unit}`;
}

export function formatCheckItems(items?: AttendanceMonthCloseCheckItem[], emptyMessage = "未処理項目はありません。") {
  if (!items || items.length === 0) {
    return emptyMessage;
  }

  return items.map(formatCheckItem).join(" / ");
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
