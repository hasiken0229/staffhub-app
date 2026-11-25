import { useState, type ReactNode } from "react";
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
  onActiveSectionChange: (section: string, targetId?: string) => void;
  onSubNavChange: (targetId: string) => void;
  onRefresh: () => void;
  onLogout: () => void;
  children: ReactNode;
};

export function AdminPortalShell(props: AdminPortalShellProps) {
  const [isMobileNavOpen, setIsMobileNavOpen] = useState(false);

  function handleSectionChange(section: string, targetId?: string) {
    props.onActiveSectionChange(section, targetId);
    setIsMobileNavOpen(false);
  }

  return (
    <main className="admin-shell admin-shell-with-sidebar">
      <aside className="admin-sidebar">
        <div className="sidebar-account-panel">
          <div className="sidebar-account-heading">
            <h1>勤怠管理システム</h1>
            <p>利用中のアカウント: <strong>{props.currentUserName ?? "管理者"}</strong></p>
          </div>
          <PortalModeTabs
            currentMode={props.currentMode}
            canUseEmployeePortal={props.canUseEmployeePortal}
            onModeChange={props.onModeChange}
          />
          <button type="button" className="secondary sidebar-logout-button" onClick={props.onLogout}>
            ログアウト
          </button>
        </div>
        <button
          type="button"
          className="secondary mobile-admin-nav-toggle"
          aria-expanded={isMobileNavOpen}
          aria-controls="admin-primary-navigation"
          onClick={() => setIsMobileNavOpen((current) => !current)}
        >
          {isMobileNavOpen ? "管理メニューを閉じる" : "管理メニューを開く"}
        </button>
        <div id="admin-primary-navigation" className={isMobileNavOpen ? "admin-primary-navigation is-open" : "admin-primary-navigation"}>
          <SectionNav activeSection={props.activeSection} onChange={handleSectionChange} badges={props.navBadges} />
        </div>
      </aside>

      <div className="admin-content-column">
        <div className="main-column">
          <header className="workspace-hero section-enter">
            <div>
              <h2>{props.currentSectionTitle}</h2>
            </div>
            <PageSubNav items={props.subNavItems} activeTargetId={props.activeSubNavId} onChange={props.onSubNavChange}>
              {props.activeSection === "dashboard" && (
                <button type="button" className="page-subnav-item" onClick={() => handleSectionChange("reports", "reports-export")}>
                  レポート
                </button>
              )}
            </PageSubNav>
            <div className="workspace-actions">
              <div className="workspace-account-actions">
                <span className="workspace-user-label">{props.currentUserName ?? "管理者"}</span>
                <PortalModeTabs
                  currentMode={props.currentMode}
                  canUseEmployeePortal={props.canUseEmployeePortal}
                  onModeChange={props.onModeChange}
                />
                <button type="button" className="secondary workspace-logout-button" onClick={props.onLogout}>
                  ログアウト
                </button>
              </div>
              <button onClick={props.onRefresh} type="button">
                {props.isPending ? "読み込み中..." : "最新情報を再読込"}
              </button>
            </div>
          </header>

          {props.errorMessage ? <p className="banner" role="alert">{props.errorMessage}</p> : null}
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
