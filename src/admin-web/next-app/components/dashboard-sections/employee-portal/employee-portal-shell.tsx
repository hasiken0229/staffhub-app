import type { ReactNode } from "react";
import { PortalModeTabs } from "@/components/portal-mode-tabs";
import type { EmployeePortalTab } from "../employee-portal-section";
import type { EmployeePortalSectionProps } from "./employee-portal-types";

type EmployeePortalShellProps = {
  employeeName: string;
  data: EmployeePortalSectionProps["data"];
  actions: Pick<EmployeePortalSectionProps["actions"], "onLogout" | "onModeChange" | "onRefresh">;
  activeTab: EmployeePortalTab;
  onTabChange: (tab: EmployeePortalTab) => void;
  children: ReactNode;
};

const employeePortalTabs: Array<{ key: EmployeePortalTab; label: string }> = [
  { key: "home", label: "ホーム" },
  { key: "leave", label: "休暇申請" },
  { key: "daily-edit", label: "勤怠修正" },
  { key: "requests", label: "申請状況" },
  { key: "payroll", label: "給与明細" },
  { key: "notices", label: "通知" },
  { key: "ledger", label: "有給台帳" },
];

export function EmployeePortalShell(props: EmployeePortalShellProps) {
  return (
    <main className="admin-shell employee-portal-shell">
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
          <nav className="employee-portal-tabs" aria-label="職員画面メニュー">
            {employeePortalTabs.map((tab) => (
              <button
                key={tab.key}
                type="button"
                className={props.activeTab === tab.key ? "active" : ""}
                aria-current={props.activeTab === tab.key ? "page" : undefined}
                onClick={() => props.onTabChange(tab.key)}
              >
                {tab.label}
              </button>
            ))}
          </nav>
          <div className="workspace-actions employee-portal-actions">
            <button onClick={props.actions.onRefresh} type="button">
              {props.data.isPending ? "読み込み中..." : "最新情報を再読込"}
            </button>
          </div>
        </header>

        {props.data.errorMessage ? <p className="banner">{props.data.errorMessage}</p> : null}
        {props.data.isPending ? <EmployeeLoadingSkeleton /> : null}

        {props.children}
      </div>
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
