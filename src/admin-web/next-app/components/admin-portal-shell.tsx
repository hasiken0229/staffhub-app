import type { ReactNode } from "react";
import { PageSubNav, type PageSubNavItem } from "@/components/page-sub-nav";
import { PortalModeTabs } from "@/components/portal-mode-tabs";
import { SectionNav } from "@/components/section-nav";
import type { AuthAudience } from "@/types";

type AdminPortalShellProps = {
  activeSection: string;
  currentSectionTitle: string;
  currentUserName?: string;
  currentMode: AuthAudience;
  canUseEmployeePortal: boolean;
  subNavItems: PageSubNavItem[];
  activeSubNavId: string;
  isPending: boolean;
  errorMessage: string;
  navBadges?: Record<string, number>;
  onModeChange: (mode: AuthAudience) => void;
  onActiveSectionChange: (section: string) => void;
  onSubNavChange: (targetId: string) => void;
  onRefresh: () => void;
  onLogout: () => void;
  children: ReactNode;
};

export function AdminPortalShell(props: AdminPortalShellProps) {
  return (
    <main className="admin-shell admin-shell-with-sidebar">
      <aside className="admin-sidebar">
        <div className="sidebar-brand">
          <span className="sidebar-logo" aria-hidden="true">勤</span>
          <div>
            <strong>StaffHub</strong>
            <span>管理メニュー</span>
          </div>
        </div>
        <SectionNav activeSection={props.activeSection} onChange={props.onActiveSectionChange} badges={props.navBadges} />
      </aside>

      <div className="admin-content-column">
        <header className="app-topbar">
          <div className="brand app-topbar-brand">
            <div>
              <h1>勤怠管理システム</h1>
              <p>利用中のアカウント: <strong>{props.currentUserName ?? "管理者"}</strong></p>
            </div>
          </div>
          <div className="app-topbar-actions">
            <PortalModeTabs
              currentMode={props.currentMode}
              canUseEmployeePortal={props.canUseEmployeePortal}
              onModeChange={props.onModeChange}
            />
            <button type="button" className="secondary" onClick={props.onLogout}>
              ログアウト
            </button>
          </div>
        </header>

        <div className="main-column">
          <header className="workspace-hero section-enter">
            <div>
              <h2>{props.currentSectionTitle}</h2>
            </div>
            <PageSubNav items={props.subNavItems} activeTargetId={props.activeSubNavId} onChange={props.onSubNavChange} />
            <div className="workspace-actions">
              <button onClick={props.onRefresh} type="button">
                {props.isPending ? "読み込み中..." : "最新情報を再読込"}
              </button>
            </div>
          </header>

          {props.errorMessage ? <p className="banner">{props.errorMessage}</p> : null}
          {props.isPending ? <LoadingSkeleton /> : null}
          {props.children}
        </div>
      </div>
    </main>
  );
}

function LoadingSkeleton() {
  return (
    <section className="panel skeleton-panel" aria-label="読み込み中">
      <span />
      <span />
      <span />
    </section>
  );
}
