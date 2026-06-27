const CODE_LABELS: Record<string, string> = {
  ADMIN: "管理者",
  EMPLOYEE: "職員",
  DEVICE: "打刻端末",
  SYSTEM: "システム",
  CARD: "カード",
  CARD_ASSIGNED: "カード割当",
  CARD_REVOKED: "カード解除",
  ATTENDANCE_ACCEPTED: "打刻受付",
  ATTENDANCE_REJECTED: "打刻拒否",
  ATTENDANCE_EVENT: "打刻記録",
  ATTENDANCE_DAILY: "日次勤怠",
  ATTENDANCE_DAILY_APPROVED: "日次勤怠承認",
  ATTENDANCE_DAILY_RETURNED: "日次勤怠差戻し",
  ATTENDANCE_DAILY_MANUAL_EDITED: "日次勤怠手修正",
  ATTENDANCE_DAILY_MANUAL_EDIT_RESET: "日次勤怠修正取消",
  ATTENDANCE_DAILY_EDIT_REQUEST: "勤怠修正申請",
  ATTENDANCE_DAILY_EDIT_REQUEST_CREATED: "勤怠修正申請作成",
  ATTENDANCE_DAILY_EDIT_REQUEST_APPROVED: "勤怠修正申請承認",
  ATTENDANCE_DAILY_EDIT_REQUEST_RETURNED: "勤怠修正申請差戻し",
  ATTENDANCE_MONTH: "勤怠月締",
  ATTENDANCE_MONTH_CLOSE: "勤怠月締め",
  ATTENDANCE_MONTH_REOPEN: "勤怠月締め解除",
  LEAVE_REQUEST: "休暇申請",
  LEAVE_REQUEST_CREATED: "休暇申請作成",
  LEAVE_REQUEST_APPROVED: "休暇申請承認",
  LEAVE_REQUEST_REJECTED: "休暇申請却下",
  LEAVE_REQUEST_RETURNED: "休暇申請差戻し",
  LEAVE_REQUEST_CANCELLED: "休暇申請取消",
  PAID_LEAVE_GRANT: "有給付与",
  PAID_LEAVE_GRANTED: "有給付与",
  PAID_LEAVE_ADJUSTMENT: "有給調整",
  PAID_LEAVE_ADJUSTED: "有給調整",
  NOTICE: "お知らせ",
  NOTICE_CREATED: "お知らせ作成",
  NOTICE_TARGETED: "個別お知らせ",
  NOTICE_ALL_STAFF: "全職員お知らせ",
  PAYROLL_STATEMENT: "給与明細",
  PAYROLL_STATEMENT_UPSERT: "給与明細登録",
  PAYROLL_STATEMENT_DELETE: "給与明細削除",
  PAYROLL_VIEWED: "給与明細閲覧",
  PAYROLL_IMPORT_BATCH: "給与取込バッチ",
  PAYROLL_BATCH_DELETE: "給与取込バッチ削除",
  PAYROLL_BATCH_ZIP: "給与一括PDF出力",
  BONUS_BATCH_ZIP: "賞与一括PDF出力",
  PAYROLL_PUBLISHED: "給与明細公開",
  BONUS_PUBLISHED: "賞与明細公開",
  PAYROLL_CSV: "給与取込",
  BONUS_CSV: "賞与取込",
  EMPLOYEE_CSV: "職員取込",
  MONTHLY_ATTENDANCE_CSV: "月次勤怠出力",
  DAILY_ATTENDANCE_CSV: "日次勤怠出力",
  DAILY_ATTENDANCE_PDF: "日次勤怠帳票出力",
  MONTHLY_PAYROLL_CSV: "給与ソフト向け出力",
  MONTHLY_WORKS_PDF: "職員別勤務表出力",
  FULL_TIME: "常勤",
  PART_TIME: "非常勤",
  CONTRACT: "契約",
  TEMPORARY: "臨時",
  PAID: "有給",
  SPECIAL: "特別休暇",
  ABSENCE: "欠勤",
  MISSING_CLOCK_OUT: "退勤未打刻",
  SHORT_BREAK_OVER_8: "休憩不足",
  LEAVE_WITH_WORK: "休暇日の勤務",
  CARD_READER: "カード読取",
  CARD_NOT_REGISTERED: "未登録カード",
  PENDING: "承認待ち",
  APPROVED: "承認済み",
  RETURNED: "差戻し",
  REJECTED: "却下",
  CANCELLED: "取消",
  OPEN: "未締め",
  CLOSED: "締め済み",
  CREATED: "作成",
  UPDATED: "更新",
  DELETED: "削除",
};

const DETAIL_KEY_LABELS: Record<string, string> = {
  employee_id: "職員",
  employeeId: "職員",
  employee_code: "職員番号",
  employeeCode: "職員番号",
  employee_name: "職員名",
  employeeName: "職員名",
  card_uid: "カード番号",
  cardUid: "カード番号",
  old_value: "変更前",
  oldValue: "変更前",
  new_value: "変更後",
  newValue: "変更後",
  status: "状態",
  approval_status: "承認状態",
  approvalStatus: "承認状態",
  action: "操作",
  action_type: "操作",
  actionType: "操作",
  target_date: "対象日",
  targetDate: "対象日",
  target_month: "対象月",
  targetMonth: "対象月",
  source_type: "元データ",
  sourceType: "元データ",
  reason: "理由",
  comment: "コメント",
  file_name: "ファイル名",
  fileName: "ファイル名",
  count: "件数",
  processedCount: "処理件数",
  successCount: "成功件数",
  errorCount: "エラー件数",
};

const ENVIRONMENT_LABELS: Record<string, string> = {
  fileinfo: "ファイル形式の確認機能",
  zip: "圧縮ファイル作成機能",
  mbstring: "日本語文字コード変換機能",
  pdo: "データベース接続機能",
};

export function formatDisplayCode(value?: string | null) {
  const normalized = normalizeCode(value);
  if (!normalized) {
    return "-";
  }

  return CODE_LABELS[normalized] ?? fallbackCodeLabel(normalized);
}

export function formatAuditActor(actorType?: string | null, actorId?: number | null) {
  const label = formatDisplayCode(actorType);
  return actorId == null ? label : `${label} #${actorId}`;
}

export function formatAuditDetail(detailJson?: Record<string, unknown> | null, fallback?: string | null) {
  const detail = flattenDetail(detailJson ?? {});
  const pairs = Object.entries(detail)
    .slice(0, 4)
    .map(([key, value]) => `${formatDetailKey(key)}: ${formatDetailValue(key, value)}`);

  if (pairs.length > 0) {
    return pairs.join(" / ");
  }

  if (!fallback || fallback === "-") {
    return "-";
  }

  return fallback.replace(/\b[A-Z][A-Z0-9_]{2,}\b/g, (matched) => formatDisplayCode(matched));
}

export function formatEnvironmentLabel(key?: string | null, label?: string | null) {
  const normalized = (key ?? "").trim().toLowerCase();
  return ENVIRONMENT_LABELS[normalized] ?? label?.replace(/^PHP拡張\s*/i, "") ?? "-";
}

export function formatEnvironmentPurpose(key?: string | null, purpose?: string | null) {
  const normalized = (key ?? "").trim().toLowerCase();
  switch (normalized) {
    case "fileinfo":
      return "取込ファイルの種類確認、給与明細の保存";
    case "zip":
      return "給与取込バッチの一括出力";
    case "mbstring":
      return "日本語文字コードの変換";
    case "pdo":
      return "データベースへの接続";
    default:
      return purpose?.replace(/\b(CSV|PDF|ZIP|PHP|UTF|Shift_JIS)\b/g, "") || "-";
  }
}

export function formatEnvironmentMessage(status?: string | null, missingCount = 0) {
  return status === "OK"
    ? "必要なサーバー機能は有効です。"
    : `必要なサーバー機能が${missingCount}件不足しています。取込や出力が失敗する可能性があります。`;
}

function normalizeCode(value?: string | null) {
  const normalized = (value ?? "").trim().toUpperCase();
  return normalized || "";
}

function fallbackCodeLabel(value: string) {
  const tokenLabels: Record<string, string> = {
    ATTENDANCE: "勤怠",
    DAILY: "日次",
    EDIT: "修正",
    REQUEST: "申請",
    LEAVE: "休暇",
    PAYROLL: "給与",
    STATEMENT: "明細",
    IMPORT: "取込",
    BATCH: "バッチ",
    MONTH: "月",
    CLOSE: "締め",
    REOPEN: "締め解除",
    NOTICE: "お知らせ",
    CARD: "カード",
    CREATED: "作成",
    UPDATED: "更新",
    DELETE: "削除",
    DELETED: "削除",
    APPROVED: "承認",
    RETURNED: "差戻し",
    REJECTED: "却下",
    CANCELLED: "取消",
    VIEWED: "閲覧",
    GRANT: "付与",
    ADJUSTMENT: "調整",
  };

  const label = value
    .split("_")
    .map((token) => tokenLabels[token] ?? "")
    .join("");

  return label || "未分類";
}

function flattenDetail(value: Record<string, unknown>, prefix = ""): Record<string, unknown> {
  const result: Record<string, unknown> = {};

  Object.entries(value).forEach(([key, child]) => {
    const nextKey = prefix ? `${prefix}.${key}` : key;
    if (child && typeof child === "object" && !Array.isArray(child)) {
      Object.assign(result, flattenDetail(child as Record<string, unknown>, nextKey));
      return;
    }
    result[nextKey] = child;
  });

  return result;
}

function formatDetailKey(key: string) {
  const leaf = key.split(".").at(-1) ?? key;
  return DETAIL_KEY_LABELS[leaf] ?? leaf.replace(/_/g, " ");
}

function formatDetailValue(key: string, value: unknown) {
  if (value == null) {
    return "なし";
  }

  if (typeof value === "boolean") {
    return value ? "はい" : "いいえ";
  }

  if (typeof value === "number") {
    return String(value);
  }

  const text = String(value);
  const leaf = key.split(".").at(-1) ?? key;
  if (/(_type|Type|status|Status|action|Action|reason|Reason)$/.test(leaf) || /^[A-Z0-9_]+$/.test(text)) {
    return formatDisplayCode(text);
  }

  return text.replace(/\b[A-Z][A-Z0-9_]{2,}\b/g, (matched) => formatDisplayCode(matched));
}
