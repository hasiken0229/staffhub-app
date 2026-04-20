type SectionNavProps = {
  activeSection: string;
  onChange: (section: string) => void;
};

const items = [
  { key: "dashboard", label: "ダッシュボード" },
  { key: "employees", label: "職員管理" },
  { key: "cards", label: "カード管理" },
  { key: "attendance", label: "日次勤怠" },
  { key: "leave", label: "届出・有給管理" },
  { key: "notices", label: "お知らせ" },
  { key: "payroll", label: "給与明細" },
  { key: "reports", label: "レポート" },
  { key: "system", label: "システム管理" },
  { key: "audit", label: "監査ログ" },
];

export function SectionNav({ activeSection, onChange }: SectionNavProps) {
  return (
    <nav className="section-nav">
      {items.map((item) => (
        <button
          key={item.key}
          className={activeSection === item.key ? "nav-item is-active" : "nav-item"}
          onClick={() => onChange(item.key)}
          type="button"
        >
          <span className="nav-label">{item.label}</span>
        </button>
      ))}
    </nav>
  );
}
