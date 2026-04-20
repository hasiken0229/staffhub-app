export type PageSubNavItem = {
  label: string;
  targetId: string;
};

type PageSubNavProps = {
  items: PageSubNavItem[];
  activeTargetId: string;
  onChange: (targetId: string) => void;
};

export function PageSubNav({ items, activeTargetId, onChange }: PageSubNavProps) {
  if (items.length === 0) {
    return null;
  }

  return (
    <nav className="page-subnav" aria-label="ページ内メニュー">
      {items.map((item) => (
        <button
          key={item.targetId}
          type="button"
          className={item.targetId === activeTargetId ? "page-subnav-item is-active" : "page-subnav-item"}
          onClick={() => onChange(item.targetId)}
        >
          {item.label}
        </button>
      ))}
    </nav>
  );
}
