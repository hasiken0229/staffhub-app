import { useState } from "react";

type SectionNavProps = {
  activeSection: string;
  onChange: (section: string, targetId?: string) => void;
  badges?: Record<string, number>;
};

const groups = [
  {
    key: "main",
    label: "メイン",
    items: [
      { key: "dashboard", label: "ダッシュボード" },
      { key: "notices", label: "お知らせ" },
    ],
  },
  {
    key: "operations",
    label: "業務管理",
    items: [
      { key: "employees", label: "職員管理" },
      { key: "cards", label: "カード管理" },
      { key: "attendance", label: "日次勤怠" },
      { key: "leave", label: "届出・有給管理" },
    ],
  },
  {
    key: "data",
    label: "データ・集計",
    items: [
      { key: "payroll", label: "給与明細" },
      { key: "harmosMigration", label: "HARMOS移行" },
      { key: "reports", label: "レポート" },
      { key: "audit", label: "監査ログ" },
      { key: "system", label: "システム管理" },
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
                {group.items.map((item) => {
                  const badgeCount = badges[item.key] ?? 0;
                  const attendanceAlert = item.key === "attendance" && badgeCount > 0;
                  const badgeLabel = attendanceAlert ? "打刻漏れなど要確認の日次があります" : `未処理 ${badgeCount}件`;

                  return (
                    <button
                      key={item.key}
                      className={activeSection === item.key ? "nav-item is-active" : "nav-item"}
                      onClick={() => attendanceAlert ? onChange(item.key, "attendance-attention") : onChange(item.key)}
                      type="button"
                      aria-current={activeSection === item.key ? "page" : undefined}
                      title={badgeCount ? `${item.label} - ${badgeLabel}` : item.label}
                    >
                      <span className="nav-label">{item.label}</span>
                      {badgeCount ? (
                        <span className="nav-badge" aria-label={badgeLabel}>
                          {attendanceAlert ? "！" : `要${badgeCount}`}
                        </span>
                      ) : null}
                    </button>
                  );
                })}
              </div>
            ) : null}
          </div>
        );
      })}
    </nav>
  );
}
