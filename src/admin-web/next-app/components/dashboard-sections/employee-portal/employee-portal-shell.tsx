import type { ReactNode } from "react";
import { PortalModeTabs } from "@/components/portal-mode-tabs";
import { EmployeePortalHomeCards } from "./employee-portal-home-cards";
import type { EmployeePortalSectionProps } from "./employee-portal-types";

type EmployeePortalShellProps = {
  employeeName: string;
  data: EmployeePortalSectionProps["data"];
  actions: Pick<EmployeePortalSectionProps["actions"], "onLogout" | "onModeChange" | "onRefresh">;
  formatters: EmployeePortalSectionProps["formatters"];
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

        <EmployeePortalHomeCards data={props.data} formatters={props.formatters} />

        {props.data.errorMessage ? <p className="banner">{props.data.errorMessage}</p> : null}
        {props.data.isPending ? <EmployeeLoadingSkeleton /> : null}

        {props.children}
      </div>

      <nav className="portal-bottom-nav mobile-only" aria-label="職員ポータルメニュー">
        <a href="#employee-portal-home"><span aria-hidden="true">H</span>ホーム</a>
        <a href="#leave-request-form"><span aria-hidden="true">休</span>休暇申請</a>
        <a href="#employee-payroll-list-mobile"><span aria-hidden="true">給</span>給与明細</a>
      </nav>
    </main>
  );
}

function EmployeeLoadingSkeleton() {
  return (
    <section className="panel skeleton-panel" aria-label="読み込み中">
      <span />
      <span />
      <span />
    </section>
  );
}
