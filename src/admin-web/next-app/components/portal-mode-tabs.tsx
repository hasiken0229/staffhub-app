import type { AuthAudience } from "@/types";

type PortalModeTabsProps = {
  currentMode: AuthAudience;
  canUseEmployeePortal: boolean;
  onModeChange: (mode: AuthAudience) => void;
};

export function PortalModeTabs(props: PortalModeTabsProps) {
  if (!props.canUseEmployeePortal) {
    return null;
  }

  const items: Array<{ key: AuthAudience; label: string }> = [
    { key: "ADMIN", label: "管理者画面" },
    { key: "EMPLOYEE", label: "利用者画面" },
  ];

  return (
    <div className="portal-mode-tabs" role="tablist" aria-label="画面切替">
      {items.map((item) => (
        <button
          key={item.key}
          type="button"
          role="tab"
          aria-selected={props.currentMode === item.key}
          className={props.currentMode === item.key ? "portal-mode-tab is-active" : "portal-mode-tab"}
          onClick={() => props.onModeChange(item.key)}
        >
          {item.label}
        </button>
      ))}
    </div>
  );
}
