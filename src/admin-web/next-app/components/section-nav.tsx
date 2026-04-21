import { useState } from "react";

type SectionNavProps = {
  activeSection: string;
  onChange: (section: string) => void;
};

const groups = [
  {
    key: "daily",
    label: "日次運用",
    items: [
      { key: "dashboard", label: "ダッシュボード" },
      { key: "attendance", label: "日次勤怠" },
      { key: "leave", label: "届出・有給管理" },
    ],
  },
  {
    key: "master",
    label: "職員・マスタ",
    items: [
      { key: "employees", label: "職員管理" },
      { key: "cards", label: "カード管理" },
      { key: "system", label: "システム管理" },
    ],
  },
  {
    key: "backoffice",
    label: "通知・給与・監査",
    items: [
      { key: "notices", label: "お知らせ" },
      { key: "payroll", label: "給与明細" },
      { key: "reports", label: "レポート" },
      { key: "audit", label: "監査ログ" },
    ],
  },
];

export function SectionNav({ activeSection, onChange }: SectionNavProps) {
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
                  >
                    <span className="nav-label">{item.label}</span>
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
