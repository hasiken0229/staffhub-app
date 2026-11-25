import { useEffect, useRef, useState } from "react";
import { EMPLOYEE_NAVIGATION_KEY } from "@/lib/api/core";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { DailyAttendanceFields, DailyEditRequestForm } from "./employee-portal/daily-edit-request-form";
import { EmployeePortalLists } from "./employee-portal/employee-portal-lists";
import { EmployeePortalShell } from "./employee-portal/employee-portal-shell";
import { EmployeeAttention, EmployeePortalHomeCards } from "./employee-portal/employee-portal-home-cards";
import type { EmployeePortalSectionProps } from "./employee-portal/employee-portal-types";
import { LeaveRequestForm } from "./employee-portal/leave-request-form";
import { useEmployeePortalForms } from "./employee-portal/use-employee-portal-forms";

import { useEmployeePortalReads } from "./employee-portal/use-employee-portal-reads";
import { employeeDayState, employeeDayStateLabel } from "@/lib/employee-attendance-state";

const tabs = ["today", "home", "leave", "daily-edit", "requests", "payroll", "notices", "ledger"] as const;
export type EmployeePortalTab = (typeof tabs)[number];

function sessionTab(state: unknown, sessionId: string): EmployeePortalTab {
  const entry = (state as { staffhubEmployee?: { sessionId?: string; tab?: EmployeePortalTab } } | null)?.staffhubEmployee;
  return entry?.sessionId === sessionId && entry.tab && tabs.includes(entry.tab) ? entry.tab : "home";
}

export function EmployeePortalSection(props: EmployeePortalSectionProps) {
  const employeeName = props.data.employeePortal.home.employee?.name ?? props.data.currentUserName ?? "職員";
  const [activeTab, setActiveTab] = useState<EmployeePortalTab>("home");
  const { leaveRequestFormProps, dailyEditRequestFormProps, confirmationDialogProps } = useEmployeePortalForms(props, activeTab === "leave");
  const reads = useEmployeePortalReads(props, activeTab, dailyEditRequestFormProps.values.editTargetMonth);
  const day = props.data.employeePortal.home.todayAttendance;
  const dayHeading = useRef<HTMLHeadingElement>(null);
  useEffect(() => { if (activeTab === "today") dayHeading.current?.focus(); }, [activeTab]);
  useEffect(() => {
    const sessionId = sessionStorage.getItem(EMPLOYEE_NAVIGATION_KEY) ?? crypto.randomUUID();
    sessionStorage.setItem(EMPLOYEE_NAVIGATION_KEY, sessionId);
    const initial = sessionTab(history.state, sessionId);
    history.replaceState({ ...history.state, staffhubEmployee: { sessionId, tab: initial } }, "");
    setActiveTab(initial);
    function restore(event: PopStateEvent) { setActiveTab(sessionTab(event.state, sessionId)); }
    window.addEventListener("popstate", restore);
    return () => window.removeEventListener("popstate", restore);
  }, []);

  function navigate(tab: EmployeePortalTab) {
    if (tab === activeTab) return;
    const sessionId = sessionStorage.getItem(EMPLOYEE_NAVIGATION_KEY);
    history.pushState({ ...history.state, staffhubEmployee: { sessionId, tab } }, "");
    setActiveTab(tab);
  }

  return (
    <EmployeePortalShell
      employeeName={employeeName}
      data={props.data}
      actions={props.actions}
      onTabChange={navigate}
      activeTab={activeTab}
      homeLead={activeTab === "home" ? <EmployeePortalHomeCards data={props.data} onTabChange={navigate} freshness={reads.freshness} onRefresh={reads.refreshToday} /> : undefined}
    >
      {activeTab === "today" ? <section className="panel" aria-label="今日の勤怠詳細">
        <h2 ref={dayHeading} tabIndex={-1}>{day?.targetDate} 今日の勤怠詳細</h2>
        {day ? <><p>{employeeDayStateLabel(day)}</p><DailyAttendanceFields row={day} />
          {["NOT_DUE", "WORKING", "FUTURE"].includes(employeeDayState(day)) ? <p>勤務予定の終了後に修正できます。お急ぎの場合は管理者へご連絡ください。</p> : null}</> : <p>勤怠を取得できていません。</p>}
        <button type="button" onClick={() => { if (day) dailyEditRequestFormProps.selectHistoryMonth(day.targetDate.slice(0, 7)); navigate("daily-edit"); }}>この月の勤怠を見る</button>
      </section> : null}
      {activeTab !== "home" && activeTab !== "today" && reads.sectionStatus === "ready" ? <button type="button" className="secondary" onClick={reads.refreshSection}>最新情報を再読込</button> : null}
      {reads.sectionStatus === "loading" ? <p role="status">{activeTab === "home" ? "差戻し・未読件数" : "この画面の情報"}を取得しています…</p> : null}
      {reads.sectionStatus === "error" ? <div role="alert" className="banner"><p>{activeTab === "home" ? "差戻し・未読件数" : "この画面の情報"}を取得できませんでした。</p><button type="button" onClick={reads.refreshSection}>この項目を再試行</button></div> : null}
      {reads.sectionStatus === "ready" ? <>
      {activeTab === "home" ? <EmployeeAttention data={props.data} onTabChange={navigate} /> : null}
      {activeTab === "leave" ? <LeaveRequestForm {...leaveRequestFormProps} /> : null}
      {activeTab === "daily-edit" ? <DailyEditRequestForm {...dailyEditRequestFormProps} /> : null}
      {activeTab === "requests" || activeTab === "payroll" || activeTab === "notices" || activeTab === "ledger" ? (
        <EmployeePortalLists key={activeTab} data={props.data} actions={{ ...props.actions, onNotificationRead: reads.onNotificationRead }} formatters={props.formatters} activeTab={activeTab} onTabChange={navigate} />
      ) : null}
      </> : null}
      <ConfirmDialog {...confirmationDialogProps} />
    </EmployeePortalShell>
  );
}
