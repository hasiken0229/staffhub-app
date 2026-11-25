import type { ComponentProps, ReactNode } from "react";
import { AdminPortalShell } from "@/components/admin-portal-shell";
import { EmployeePortalSection } from "@/components/dashboard-sections/employee-portal-section";
import { LoginSection } from "@/components/login-section";
import type { AdminSectionKey } from "@/lib/dashboard-defaults";
import type { AuthAudience } from "@/types";

type AdminDashboardViewProps = {
  isAuthenticated: boolean;
  checkingSession: boolean;
  recovery: { audience: AuthAudience; verified: boolean; message: string; retrying: boolean } | null;
  onRetrySession: () => void;
  onLogout: () => void;
  loginProps: ComponentProps<typeof LoginSection>;
  currentAudience: AuthAudience | "";
  employeePortalSectionProps: ComponentProps<typeof EmployeePortalSection>;
  adminShellProps: Omit<ComponentProps<typeof AdminPortalShell>, "children">;
  adminSections: Record<AdminSectionKey, ReactNode>;
  activeSection: AdminSectionKey;
};

export function AdminDashboardView({
  isAuthenticated,
  checkingSession,
  recovery,
  onRetrySession,
  onLogout,
  loginProps,
  currentAudience,
  employeePortalSectionProps,
  adminShellProps,
  adminSections,
  activeSection,
}: AdminDashboardViewProps) {
  if (recovery) {
    return (
      <main className="admin-shell">
        <section className="panel" aria-busy={recovery.retrying}>
          <h1>{recovery.verified ? (recovery.audience === "ADMIN" ? "管理ホーム" : "職員ホーム") : "ログイン状態の確認"}</h1>
          <p role="alert">{recovery.verified ? "ホームの情報を取得できませんでした。" : "ログイン状態を確認できませんでした。"} {recovery.message}</p>
          <div className="button-row">
            <button type="button" disabled={recovery.retrying} onClick={onRetrySession}>{recovery.retrying ? "再読込中..." : "再試行"}</button>
            <button type="button" className="secondary" onClick={onLogout}>ログアウト</button>
          </div>
        </section>
      </main>
    );
  }
  if (checkingSession) {
    return <main className="admin-shell"><p role="status">ログイン状態とホームの情報を確認しています。</p></main>;
  }
  if (!isAuthenticated) {
    return <LoginSection {...loginProps} />;
  }

  if (currentAudience === "EMPLOYEE") {
    return <EmployeePortalSection {...employeePortalSectionProps} />;
  }

  return <AdminPortalShell {...adminShellProps}>{adminSections[activeSection]}</AdminPortalShell>;
}
