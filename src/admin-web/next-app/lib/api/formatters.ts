function parseDateValue(value?: string | null) {
  if (!value) {
    return null;
  }

  const trimmed = value.trim();
  if (!trimmed) {
    return null;
  }

  const plainDateMatch = trimmed.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (plainDateMatch) {
    const [, year, month, day] = plainDateMatch;
    return new Date(Number(year), Number(month) - 1, Number(day));
  }

  const normalized = trimmed.replace(" ", "T");
  const parsed = new Date(normalized);
  if (Number.isNaN(parsed.getTime())) {
    return null;
  }

  return parsed;
}

function pad2(value: number) {
  return String(value).padStart(2, "0");
}

export function formatDateOnly(value?: string | null) {
  const parsed = parseDateValue(value);
  if (!parsed) {
    return "-";
  }

  return `${parsed.getFullYear()}/${parsed.getMonth() + 1}/${parsed.getDate()}`;
}

export function formatMonthDay(value?: string | null) {
  const parsed = parseDateValue(value);
  if (!parsed) {
    return "-";
  }

  return `${parsed.getMonth() + 1}/${parsed.getDate()}`;
}

export function formatTimeOnly(value?: string | null) {
  const parsed = parseDateValue(value);
  if (!parsed) {
    return "-";
  }

  return `${pad2(parsed.getHours())}:${pad2(parsed.getMinutes())}`;
}

export function formatDateTime(value?: string | null) {
  const parsed = parseDateValue(value);
  if (!parsed) {
    return "-";
  }

  return `${parsed.getFullYear()}/${parsed.getMonth() + 1}/${parsed.getDate()} ${pad2(parsed.getHours())}:${pad2(parsed.getMinutes())}`;
}

export function formatEmploymentType(value?: string | null) {
  switch ((value ?? "").toUpperCase()) {
    case "FULL_TIME":
      return "常勤";
    case "PART_TIME":
      return "非常勤";
    case "CONTRACT":
      return "契約";
    case "TEMPORARY":
      return "臨時";
    default:
      return value || "-";
  }
}

export function formatEmployeeStatus(value?: string | null) {
  switch ((value ?? "").toUpperCase()) {
    case "ACTIVE":
      return "在職";
    case "INACTIVE":
      return "停止";
    case "RETIRED":
      return "退職";
    default:
      return value || "-";
  }
}

export function formatApprovalStatus(value?: string | null) {
  switch ((value ?? "").toUpperCase()) {
    case "PENDING":
      return "承認待ち";
    case "APPROVED":
      return "承認済み";
    case "RETURNED":
      return "差戻し";
    case "REJECTED":
      return "却下";
    case "CANCELLED":
      return "取消";
    default:
      return value || "-";
  }
}

export function formatCloseStatus(value?: string | null) {
  switch ((value ?? "").toUpperCase()) {
    case "OPEN":
      return "未締め";
    case "CLOSED":
      return "締め済み";
    default:
      return value || "-";
  }
}

export function formatReceiveStatus(value?: string | null) {
  switch ((value ?? "").toUpperCase()) {
    case "ACCEPTED":
      return "受付済み";
    case "REJECTED":
      return "受付不可";
    case "PENDING":
      return "処理待ち";
    case "OFFLINE_STORED":
      return "一時保存";
    default:
      return value || "-";
  }
}

export function formatNoticeType(value?: string | null) {
  switch ((value ?? "").toUpperCase()) {
    case "GENERAL":
      return "一般";
    case "PAYROLL_INFO":
      return "明細案内";
    case "PAYROLL_PUBLISHED":
      return "給与明細公開";
    case "BONUS_PUBLISHED":
      return "賞与明細公開";
    case "SYSTEM":
      return "システム";
    default:
      return value || "-";
  }
}

export function formatImportType(value?: string | null) {
  switch ((value ?? "").toUpperCase()) {
    case "MONTHLY_PAYROLL_CSV":
      return "給与ソフトCSV出力";
    case "MONTHLY_WORKS_PDF":
      return "月次勤務PDF出力";
    case "PAYROLL_CSV":
      return "給与CSV取込";
    case "BONUS_CSV":
      return "賞与CSV取込";
    case "PAYROLL_PDF_UPLOAD":
      return "給与PDF登録";
    case "BONUS_PDF_UPLOAD":
      return "賞与PDF登録";
    case "EMPLOYEE_CSV":
      return "職員CSV取込";
    default:
      return value || "-";
  }
}

export function formatPayrollBatchStatus(value?: string | null) {
  switch ((value ?? "").toUpperCase()) {
    case "PENDING":
      return "未処理";
    case "PROCESSING":
      return "取込中";
    case "COMPLETED":
      return "完了";
    case "COMPLETED_WITH_ERRORS":
      return "一部失敗";
    case "DELETED":
      return "削除済み";
    default:
      return value || "-";
  }
}

export function formatLeaveLedgerEntryType(value?: string | null) {
  switch ((value ?? "").toUpperCase()) {
    case "GRANT":
      return "付与";
    case "USE":
      return "消化";
    case "ADJUST_PLUS":
      return "調整増";
    case "ADJUST_MINUS":
      return "調整減";
    case "CANCEL_RETURN":
      return "取消戻し";
    case "CARRY_FORWARD":
      return "繰越";
    case "EXPIRE":
      return "失効";
    default:
      return value || "-";
  }
}

export function formatEventType(value?: string | null) {
  if (value === "CLOCK_IN") {
    return "出勤";
  }

  if (value === "CLOCK_OUT") {
    return "退勤";
  }

  return value ?? "-";
}

export function formatWorkMinutes(value?: number | null) {
  if (value == null) {
    return "-";
  }

  const hours = Math.floor(value / 60);
  const minutes = value % 60;
  return `${hours}時間${minutes.toString().padStart(2, "0")}分`;
}
