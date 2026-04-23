import { useState } from "react";

type SectionNavProps = {
  activeSection: string;
  onChange: (section: string) => void;
  badges?: Record<string, number>;
};

const groups = [
  {
    key: "main",
    label: "メイン",
    items: [
      { key: "dashboard", label: "ダッシュボード", icon: "D" },
      { key: "notices", label: "お知らせ", icon: "N" },
    ],
  },
  {
    key: "operations",
    label: "業務管理",
    items: [
      { key: "employees", label: "職員管理", icon: "職" },
      { key: "cards", label: "カード管理", icon: "C" },
      { key: "attendance", label: "日次勤怠", icon: "勤" },
      { key: "leave", label: "届出・有給管理", icon: "休" },
    ],
  },
  {
    key: "data",
    label: "データ・集計",
    items: [
      { key: "payroll", label: "給与明細", icon: "給" },
      { key: "reports", label: "レポート", icon: "R" },
      { key: "audit", label: "監査ログ", icon: "A" },
      { key: "system", label: "システム管理", icon: "設" },
    ],
  },
];

export function SectionNav({ activeSection, onChange, badges = {} }: SectionNavProps) {
  const [openGroupKeys, setOpenGroupKeys] = useState(() => groups.map((group) => group.key));

  function toggleGroup(groupKey: string) {
    setOpenGroupKeys((current) =>
      current.includes(groupKey) ? current.filter((key) => key !== groupKey) : [...current, groupKey],
    );
  }

  return (
    <nav className="section-nav" aria-label="管理メニュー">
      {groups.map((group) => {
        const hasActiveItem = group.items.some((item) => item.key === activeSection);
        const isOpen = openGroupKeys.includes(group.key) || hasActiveItem;

        return (
          <div key={group.key} className="nav-group">
            <button
              type="button"
              className="nav-group-toggle"
              onClick={() => toggleGroup(group.key)}
              aria-expanded={isOpen}
            >
              <span>{group.label}</span>
              <span className={isOpen ? "nav-group-mark is-open" : "nav-group-mark"} aria-hidden="true">
                ⌄
              </span>
            </button>
            {isOpen ? (
              <div className="nav-group-items">
                {group.items.map((item) => (
                  <button
                    key={item.key}
                    className={activeSection === item.key ? "nav-item is-active" : "nav-item"}
                    onClick={() => onChange(item.key)}
                    type="button"
                    title={item.label}
                  >
                    <span className="nav-icon" aria-hidden="true">{item.icon}</span>
                    <span className="nav-label">{item.label}</span>
                    {badges[item.key] ? <span className="nav-badge">{badges[item.key]}</span> : null}
                  </button>
                ))}
              </div>
            ) : null}
          </div>
        );
      })}
    </nav>
  );
}
