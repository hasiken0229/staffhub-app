import type { ReactNode } from "react";
import { MetricCard } from "@/components/metric-card";
import { PortalModeTabs } from "@/components/portal-mode-tabs";
import type { EmployeePortalSectionProps } from "./employee-portal-types";

type EmployeePortalShellProps = {
  employeeName: string;
  data: EmployeePortalSectionProps["data"];
  actions: Pick<EmployeePortalSectionProps["actions"], "onLogout" | "onModeChange" | "onRefresh">;
  children: ReactNode;
};

export function EmployeePortalShell(props: EmployeePortalShellProps) {
  return (
    <main className="admin-shell">
      <header className="app-topbar">
        <div className="brand app-topbar-brand">
          <div>
            <h1>勤怠管理システム</h1>
            <p>
              利用中のアカウント: <strong>{props.employeeName}</strong>
              {props.data.employeePortal.home.employee?.employeeCode
                ? ` / 職員番号 ${props.data.employeePortal.home.employee.employeeCode}`
                : ""}
            </p>
          </div>
        </div>
        <div className="app-topbar-actions">
          {props.actions.onModeChange ? (
            <PortalModeTabs
              currentMode={props.data.currentMode ?? "EMPLOYEE"}
              canUseEmployeePortal={Boolean(props.data.canUseEmployeePortal)}
              onModeChange={props.actions.onModeChange}
            />
          ) : null}
          <button type="button" className="secondary" onClick={props.actions.onLogout}>
            ログアウト
          </button>
        </div>
      </header>

      <div className="main-column">
        <header className="workspace-hero section-enter">
          <div>
            <h2>職員画面</h2>
          </div>
          <div className="workspace-actions">
            <button onClick={props.actions.onRefresh} type="button">
              {props.data.isPending ? "読み込み中..." : "最新情報を再読込"}
            </button>
          </div>
        </header>

        <section className="metrics section-enter delay-1">
          <MetricCard label="未承認申請" value={props.data.employeePortal.home.pendingLeaveCount} />
          <MetricCard label="有給残日数" value={props.data.employeePortal.home.paidLeaveBalance} />
          <MetricCard label="未読通知" value={props.data.employeePortal.home.unreadNotificationCount} />
          <MetricCard label="公開済み明細" value={props.data.employeePortal.payroll.length} />
        </section>

        {props.data.errorMessage ? <p className="banner">{props.data.errorMessage}</p> : null}

        {props.children}
      </div>
    </main>
  );
}
