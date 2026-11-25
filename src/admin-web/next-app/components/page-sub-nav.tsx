import type { ReactNode } from "react";

export type PageSubNavItem = {
  label: string;
  targetId: string;
};

type PageSubNavProps = {
  items: PageSubNavItem[];
  activeTargetId: string;
  onChange: (targetId: string) => void;
  children?: ReactNode;
};

export function PageSubNav({ items, activeTargetId, onChange, children }: PageSubNavProps) {
  if (items.length === 0 && !children) {
    return null;
  }

  return (
    <div className={children ? "page-subnav-shell with-shortcut" : "page-subnav-shell"}>
      <nav className="page-subnav" aria-label="ページ内メニュー">
        {items.map((item) => (
          <button
            key={item.targetId}
            type="button"
            className={item.targetId === activeTargetId ? "page-subnav-item is-active" : "page-subnav-item"}
            aria-current={item.targetId === activeTargetId ? "page" : undefined}
            onClick={() => onChange(item.targetId)}
          >
            {item.label}
          </button>
        ))}
        {children}
      </nav>
    </div>
  );
}
