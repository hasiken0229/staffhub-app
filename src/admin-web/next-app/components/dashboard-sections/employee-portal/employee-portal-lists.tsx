import { loadEmployeeBalanceAtDate, type EmployeeBalanceAtDate } from "@/lib/api/employee-balance";
import { formatStatementPayDate } from "@/lib/api/formatters";
import { useEffect, useRef, useState } from "react";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { DataTable } from "@/components/data-table";
import { MobileRecordList } from "@/components/dashboard-sections/employee-portal/mobile-record-list";
import { ApprovalStatusBadge, ReadStatusBadge } from "@/components/status-badge";
import { formatDurationMinutes } from "@/lib/api/formatters";
import type { EmployeePortalTab } from "../employee-portal-section";
import type { EmployeePortalSectionProps } from "./employee-portal-types";
import { formatLeavePeriod, formatTimeLeaveType } from "../leave/leave-section-utils";

type EmployeePortalListsProps = {
  data: EmployeePortalSectionProps["data"];
  actions: Pick<
    EmployeePortalSectionProps["actions"],
    "onAttendanceDailyEditRequestCancel" | "onLeaveRequestCancel" | "onLoadEmployeePayrollDetail" | "onNotificationRead" | "onPayrollDownload"
  >;
  formatters: EmployeePortalSectionProps["formatters"];
  onTabChange?: (tab: EmployeePortalTab) => void;
  activeTab: Extract<EmployeePortalTab, "requests" | "payroll" | "notices" | "ledger">;
};

export function EmployeePortalLists(props: EmployeePortalListsProps) {
  const [unreadOnly, setUnreadOnly] = useState(true);
  const [ledgerBalance, setLedgerBalance] = useState<EmployeeBalanceAtDate | null>(null);
  const activeActions = useRef(new Set<string>());
  const [pendingActionKeys, setPendingActionKeys] = useState<string[]>([]);
  const [actionError, setActionError] = useState("");
  const [dailyEditRequestIdToCancel, setDailyEditRequestIdToCancel] = useState<number | null>(null);
  const [leaveRequestIdToCancel, setLeaveRequestIdToCancel] = useState<number | null>(null);
  const [expandedPayrollId, setExpandedPayrollId] = useState<number | null>(null);
  const [mobilePayrollLayout, setMobilePayrollLayout] = useState(false);
  const payrollList = useRef<HTMLElement>(null);

  useEffect(() => {
    if (props.activeTab !== "payroll" || !window.matchMedia) return;
    const query = window.matchMedia("(max-width: 720px)");
    const syncLayout = () => setMobilePayrollLayout(query.matches);
    syncLayout();
    query.addEventListener("change", syncLayout);
    return () => query.removeEventListener("change", syncLayout);
  }, [props.activeTab]);

  useEffect(() => {
    if (expandedPayrollId == null || expandedPayrollId !== props.data.selectedPayrollDetailId) return;
    payrollList.current?.querySelector<HTMLElement>(".payroll-detail-card h3")?.focus();
  }, [expandedPayrollId, props.data.selectedPayrollDetailId, mobilePayrollLayout]);

  async function runListAction(key: string, action: () => Promise<void>) {
    if (activeActions.current.has(key)) return;
    activeActions.current.add(key);
    setPendingActionKeys([...activeActions.current]);
    setActionError("");
    try { await action(); }
    catch (error) { setActionError(error instanceof Error ? error.message : "処理できませんでした。再取得して状態を確認してください。"); }
    finally { activeActions.current.delete(key); setPendingActionKeys([...activeActions.current]); }
  }

  async function handleLeaveRequestCancel() {
    if (leaveRequestIdToCancel === null) {
      return;
    }

    try {
      await runListAction(`cancel-${leaveRequestIdToCancel}`, () => props.actions.onLeaveRequestCancel(leaveRequestIdToCancel));
    } catch {
      // 失敗内容は共通エラーバナーに表示する。
    } finally {
      setLeaveRequestIdToCancel(null);
    }
  }

  const feedback = actionError ? <p role="alert" className="banner">{actionError}</p> : pendingActionKeys.length ? <p role="status">処理中...</p> : null;

  if (props.activeTab === "requests") {
    const leave = props.data.employeePortal.leaveRequests.find((row) => row.id === leaveRequestIdToCancel);
    const edit = props.data.employeePortal.attendanceDailyEditRequests?.find((row) => row.id === dailyEditRequestIdToCancel);
    const leaveType = leave?.requestCategory === "TIME_LEAVE" ? formatTimeLeaveType(leave.timeLeaveType) : leave?.leaveTypeName;
    const leavePeriod = leave && leave.requestCategory !== "TIME_LEAVE" && leave.startDate === leave.endDate
      ? props.formatters.formatDateOnly(leave.startDate) : leave ? formatLeavePeriod(leave, props.formatters.formatDateOnly) : "";
    const halfDay = leave?.dayUnit === "HALF" ? (leave.halfDayType === "AM" ? "（午前半休）" : leave.halfDayType === "PM" ? "（午後半休）" : "（半休）") : "";
    return (
      <>
      <section className="portal-list-group section-enter delay-2" aria-label="申請状況">
        {feedback}
        <div className="desktop-only"><DataTable
          id="employee-leave-list"
          title="休暇申請の一覧"
          rows={props.data.employeePortal.leaveRequests}
          emptyMessage="休暇申請はまだありません"
          columns={[
            {
              key: "leaveTypeName",
              header: "区分",
              render: (row) => (row.requestCategory === "TIME_LEAVE" ? "時間休暇" : row.leaveTypeName),
            },
            {
              key: "period",
              header: "期間",
              render: (row) =>
                row.requestCategory === "TIME_LEAVE"
                  ? `${props.formatters.formatDateOnly(row.targetDate)} ${row.startTime ?? ""}-${row.endTime ?? ""}`
                  : `${props.formatters.formatDateOnly(row.startDate)} - ${props.formatters.formatDateOnly(row.endDate)}`,
            },
            {
              key: "quantityDays",
              header: "日数/分",
              render: (row) => (row.requestCategory === "TIME_LEAVE" ? formatDurationMinutes(row.quantityMinutes ?? null) : `${row.quantityDays ?? "-"}日`),
            },
            { key: "status", header: "状態", render: (row) => <ApprovalStatusBadge value={row.status} format={props.formatters.formatApprovalStatus} /> },
            { key: "decisionComment", header: "承認・差戻しコメント", render: (row) => row.decisionComment ?? "-" },
            {
              key: "cancel",
              header: "操作",
              render: (row) =>
                row.status === "PENDING" || row.status === "RETURNED" ? (
                  <button type="button" className="table-action" onClick={() => setLeaveRequestIdToCancel(row.id)}>
                    取消
                  </button>
                ) : (
                  "-"
                ),
            },
          ]}
        /></div>
        <div className="mobile-only"><MobileRecordList title="休暇申請の一覧" rows={props.data.employeePortal.leaveRequests} emptyMessage="休暇申請はまだありません"
          renderTitle={(row) => row.requestCategory === "TIME_LEAVE" ? "時間休暇" : row.leaveTypeName}
          renderMeta={(row) => <ApprovalStatusBadge value={row.status} format={props.formatters.formatApprovalStatus} />}
          renderFields={(row) => [
            { label: "対象日", value: row.requestCategory === "TIME_LEAVE" ? `${row.targetDate} ${row.startTime}-${row.endTime}` : `${row.startDate} ～ ${row.endDate}` },
            { label: "申請量", value: row.requestCategory === "TIME_LEAVE" ? formatDurationMinutes(row.quantityMinutes ?? 0) : `${row.quantityDays}日` },
            { label: "コメント", value: row.decisionComment ?? "-" },
          ]}
          renderActions={(row) => ["PENDING", "RETURNED"].includes(row.status) ? <button type="button" className="table-action" disabled={pendingActionKeys.includes(`cancel-${row.id}`)} onClick={() => setLeaveRequestIdToCancel(row.id)}>この休暇申請を取消</button> : null} /></div>

        <div className="desktop-only"><DataTable
          title="日次修正申請の一覧"
          rows={props.data.employeePortal.attendanceDailyEditRequests ?? []}
          emptyMessage="日次修正申請はまだありません"
          columns={[
            { key: "targetDate", header: "対象日", render: (row) => props.formatters.formatDateOnly(row.targetDate) },
            { key: "time", header: "出退勤", render: (row) => `${row.clockInTime ?? "-"} - ${row.clockOutTime ?? "-"}` },
            { key: "status", header: "状態", render: (row) => <ApprovalStatusBadge value={row.status} format={props.formatters.formatApprovalStatus} /> },
            { key: "comment", header: "コメント", render: (row) => row.decisionComment ?? row.employeeComment ?? "-" },
            { key: "cancel", header: "操作", render: (row) => props.actions.onAttendanceDailyEditRequestCancel && ["PENDING", "RETURNED"].includes(row.status) ? <button type="button" className="table-action" disabled={pendingActionKeys.includes(`daily-cancel-${row.id}`)} onClick={() => setDailyEditRequestIdToCancel(row.id)}>取消</button> : "-" },
          ]}
        /></div>
        <div className="mobile-only"><MobileRecordList title="日次修正申請の一覧" rows={props.data.employeePortal.attendanceDailyEditRequests ?? []} emptyMessage="日次修正申請はまだありません"
          renderTitle={(row) => row.targetDate} renderMeta={(row) => <ApprovalStatusBadge value={row.status} format={props.formatters.formatApprovalStatus} />}
          renderFields={(row) => [{ label: "出退勤", value: `${row.clockInTime ?? "-"} ～ ${row.clockOutTime ?? "-"}` }, { label: "コメント", value: row.decisionComment ?? row.employeeComment ?? "-" }]}
          renderActions={(row) => props.actions.onAttendanceDailyEditRequestCancel && ["PENDING", "RETURNED"].includes(row.status) ? <button type="button" className="table-action" disabled={pendingActionKeys.includes(`daily-cancel-${row.id}`)} onClick={() => setDailyEditRequestIdToCancel(row.id)}>この修正申請を取消</button> : null} /></div>
      </section>
      <ConfirmDialog open={Boolean(edit)} title="日次修正申請を取り消しますか？" message={edit ? `${props.formatters.formatDateOnly(edit.targetDate)}の日次修正申請（出勤 ${edit.clockInTime ?? "未入力"} ／ 退勤 ${edit.clockOutTime ?? "未入力"}）を取り消します。` : ""} confirmLabel="修正申請を取り消す"
        onCancel={() => setDailyEditRequestIdToCancel(null)} busy={pendingActionKeys.some((key) => key.startsWith("daily-cancel-"))}
        onConfirm={() => {
          if (dailyEditRequestIdToCancel === null || !props.actions.onAttendanceDailyEditRequestCancel) return;
          const id = dailyEditRequestIdToCancel;
          void runListAction(`daily-cancel-${id}`, () => props.actions.onAttendanceDailyEditRequestCancel!(id)).finally(() => setDailyEditRequestIdToCancel(null));
        }} />
      <ConfirmDialog
        open={Boolean(leave)}
        title="休暇申請を取り消しますか？"
        message={leave ? `${leavePeriod}　${leaveType}${halfDay}の申請を取り消します。` : ""}
        confirmLabel="申請を取り消す"
        onCancel={() => setLeaveRequestIdToCancel(null)}
        busy={pendingActionKeys.some((key) => key.startsWith("cancel-"))}
        onConfirm={() => void handleLeaveRequestCancel()}
      />
      </>
    );
  }

  if (props.activeTab === "payroll") {
    function togglePayroll(id: number) {
      if (activeActions.current.has("payroll-detail")) return;
      setExpandedPayrollId(null);
      if (expandedPayrollId !== id) {
        void runListAction("payroll-detail", async () => {
          await props.actions.onLoadEmployeePayrollDetail(id);
          setExpandedPayrollId(id);
        });
      }
    }
    function detailFor(row: { id: number }) {
      return row.id === expandedPayrollId && row.id === props.data.selectedPayrollDetailId ? props.data.selectedPayrollDetailCard : null;
    }
    function detailButton(row: { id: number }) {
      const open = Boolean(detailFor(row));
      return <button type="button" className="table-action" disabled={pendingActionKeys.includes("payroll-detail")}
        aria-expanded={open} onClick={() => togglePayroll(row.id)}>{open ? "明細を閉じる" : "明細を見る"}</button>;
    }
    return (
      <section ref={payrollList} className="portal-list-group section-enter delay-2" aria-label="給与明細">
        {feedback}
        <div className="desktop-only">
          <DataTable
            id="employee-payroll-list"
            renderExpandedRow={mobilePayrollLayout ? undefined : detailFor}
            title="給与・賞与明細"
            rows={props.data.employeePortal.payroll}
            emptyMessage="公開済みの明細はありません"
            columns={[
              { key: "statementTypeLabel", header: "種別", render: (row) => row.statementTypeLabel ?? "-" },
              { key: "targetYearMonth", header: "対象年月・版", render: (row) => `${row.targetYearMonth}${(row.revision ?? 1) > 1 ? ` 訂正版 第${row.revision}版` : ""}` },
              { key: "replacedAt", header: "差替日時", render: (row) => props.formatters.formatDateTime(row.replacedAt) },
              { key: "payDate", header: "支給日", render: (row) => formatStatementPayDate(row.payDate) },
              { key: "publishedAt", header: "公開日時", render: (row) => props.formatters.formatDateTime(row.publishedAt) },
              {
                key: "download",
                header: "操作",
                render: (row) => (
                  <div className="button-row">
                    {detailButton(row)}
                    <button type="button" className="table-action" disabled={pendingActionKeys.includes(`download-${row.id}`)} onClick={() => void runListAction(`download-${row.id}`, () => props.actions.onPayrollDownload(row.id, row.originalFileName))}>
                      PDF保存
                    </button>
                  </div>
                ),
              },
            ]}
          />
        </div>
        <div className="mobile-only">
          <MobileRecordList
            id="employee-payroll-mobile-list"
            renderExpandedRow={mobilePayrollLayout ? detailFor : undefined}
            title="給与・賞与明細"
            rows={props.data.employeePortal.payroll}
            emptyMessage="公開済みの明細はありません"
            renderTitle={(row) => `${row.statementTypeLabel ?? "明細"} ${row.targetYearMonth}${(row.revision ?? 1) > 1 ? ` 訂正版 第${row.revision}版` : ""}`}
            renderMeta={(row) => `支給日 ${formatStatementPayDate(row.payDate)}`}
            renderFields={(row) => [
              { label: "公開日時", value: props.formatters.formatDateTime(row.publishedAt) },
              { label: "ファイル", value: row.originalFileName },
              ...((row.revision ?? 1) > 1 ? [{ label: "差替日時", value: props.formatters.formatDateTime(row.replacedAt) }] : []),
            ]}
            renderActions={(row) => (
              <>
                {detailButton(row)}
                <button type="button" className="secondary table-action" disabled={pendingActionKeys.includes(`download-${row.id}`)} onClick={() => void runListAction(`download-${row.id}`, () => props.actions.onPayrollDownload(row.id, row.originalFileName))}>
                  PDF保存
                </button>
              </>
            )}
          />
        </div>
      </section>
    );
  }

  if (props.activeTab === "notices") {
    const notices = props.data.employeePortal.notifications.filter((row) => !unreadOnly || !row.isRead);
    const empty = unreadOnly ? "未読のお知らせはありません" : "お知らせはありません";
    return (
      <section className="portal-list-group section-enter delay-2" aria-label="通知">
        <div className="button-row" aria-label="通知の表示"><button type="button" aria-pressed={unreadOnly} onClick={() => setUnreadOnly(true)}>未読のみ</button><button type="button" aria-pressed={!unreadOnly} onClick={() => setUnreadOnly(false)}>すべての通知</button></div>
        {feedback}
        <div className="desktop-only"><DataTable
          id="employee-notices-list"
          title="お知らせ"
          rows={notices}
          emptyMessage={empty}
          columns={[
            { key: "title", header: "件名", render: (row) => row.title },
            { key: "body", header: "本文", render: (row) => row.body || "-" },
            { key: "sentAt", header: "配信日時", render: (row) => props.formatters.formatDateTime(row.sentAt) },
            { key: "sourceType", header: "種別", render: (row) => formatNotificationSourceType(row.sourceType) },
            {
              key: "read",
              header: "既読",
              render: (row) =>
                row.isRead ? (
                  <ReadStatusBadge isRead={row.isRead} />
                ) : (
                  <button type="button" className="table-action" disabled={pendingActionKeys.includes(`read-${row.id}`)} onClick={() => void runListAction(`read-${row.id}`, () => props.actions.onNotificationRead(row.id, row.sourceType))}>
                    既読にする
                  </button>
                ),
            },
          ]}
        /></div>
        <div className="mobile-only"><MobileRecordList title="お知らせ" rows={notices} emptyMessage={empty}
          renderTitle={(row) => row.title} renderMeta={(row) => <ReadStatusBadge isRead={row.isRead} />}
          renderBody={(row) => <p className="notice-body">{row.body || "-"}</p>}
          renderFields={(row) => [{ label: "配信日時", value: props.formatters.formatDateTime(row.sentAt) }, { label: "種別", value: formatNotificationSourceType(row.sourceType) }]}
          renderActions={(row) => !row.isRead ? <button type="button" disabled={pendingActionKeys.includes(`read-${row.id}`)} onClick={() => void runListAction(`read-${row.id}`, () => props.actions.onNotificationRead(row.id, row.sourceType))}>この通知を既読にする</button> : null} /></div>
      </section>
    );
  }

  return (
    <section className="portal-list-group section-enter delay-2" aria-label="有給台帳">
      {feedback}
      {props.data.employeePortal.home.checkedAt ? <div className="panel">
        <button type="button" className="secondary" disabled={pendingActionKeys.includes("balance")} onClick={() => {
          const today = props.data.employeePortal.home.checkedAt!.slice(0, 10);
          void runListAction("balance", async () => {
            const balance = await loadEmployeeBalanceAtDate(today);
            if (balance.asOf !== today) throw new Error("照会日が一致しません。残数を再取得してください。");
            setLedgerBalance(balance);
          });
        }}>現在の申請可能残数を見る</button>
        {ledgerBalance?.asOf === props.data.employeePortal.home.checkedAt.slice(0, 10) ? <p role="status">{ledgerBalance.asOf} 時点の申請可能残数　{ledgerBalance.availableDays ?? ledgerBalance.currentBalance}日{ledgerBalance.hourlyBalance ? " ／ 時間有給 " + formatDurationMinutes(ledgerBalance.hourlyBalance.availableMinutes) : ""}</p> : null}
        {props.onTabChange ? <button type="button" className="secondary" onClick={() => props.onTabChange?.("leave")}>休暇申請へ</button> : null}
      </div> : null}
      <div className="desktop-only"><DataTable
        title="有給台帳"
        rows={props.data.employeePortal.leaveLedger}
        emptyMessage="有給台帳はまだありません"
        columns={[
          { key: "occurredOn", header: "日付", render: (row) => props.formatters.formatDateOnly(row.occurredOn) },
          { key: "entryType", header: "区分", render: (row) => props.formatters.formatLeaveLedgerEntryType(row.entryType) },
          { key: "daysDelta", header: "増減", render: (row) => `${row.daysDelta}日` },
          { key: "balanceAfter", header: "残高", render: (row) => `${row.balanceAfter ?? "-"}日` },
          { key: "sourceType", header: "元データ", render: (row) => formatLedgerSourceType(row.sourceType) },
          { key: "note", header: "備考", render: (row) => row.note ?? "-" },
        ]}
      /></div>
        <div className="mobile-only"><MobileRecordList title="有給台帳" rows={props.data.employeePortal.leaveLedger} emptyMessage="有給台帳はまだありません"
        renderTitle={(row) => props.formatters.formatDateOnly(row.occurredOn)} renderMeta={(row) => props.formatters.formatLeaveLedgerEntryType(row.entryType)}
        renderFields={(row) => [{ label: "増減", value: `${row.daysDelta}日` }, { label: "残高", value: `${row.balanceAfter ?? "-"}日` }, { label: "元データ", value: formatLedgerSourceType(row.sourceType) }, { label: "備考", value: row.note ?? "-" }]} /></div>
    </section>
  );
}

function formatNotificationSourceType(value?: string | null) {
  return (
    {
      PERSONAL: "個別通知",
      NOTICE: "お知らせ",
      PAYROLL: "給与明細",
      BONUS: "賞与明細",
    }[value ?? ""] ?? value ?? "-"
  );
}

function formatLedgerSourceType(value?: string | null) {
  return (
    {
      LEAVE_REQUEST: "休暇申請",
      PAID_LEAVE_GRANT: "有給付与",
      PAID_LEAVE_ADJUSTMENT: "有給調整",
      PERSONAL: "個別通知",
    }[value ?? ""] ?? value ?? "-"
  );
}
