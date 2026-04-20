import { DataTable } from "@/components/data-table";
import type { CardAssignment, Employee } from "@/types";

type CardsSectionProps = {
  data: {
    cards: CardAssignment[];
    employees: Employee[];
    activePanel: string;
  };
  form: {
    assignEmployeeId: string;
    assignCardUid: string;
    assignResult: string;
  };
  actions: {
    onAssignEmployeeIdChange: (value: string) => void;
    onAssignCardUidChange: (value: string) => void;
    onAssignCard: () => Promise<void>;
  };
  formatters: {
    formatDateTime: (value?: string | null) => string;
  };
};

export function CardsSection(props: CardsSectionProps) {
  const activePanel = props.data.activePanel || "cards-list";

  return (
    <section className="split section-enter delay-3">
      {activePanel === "cards-list" ? (
        <DataTable
          id="cards-list"
          title="カード管理"
          rows={props.data.cards}
          columns={[
            { key: "cardUid", header: "カードUID", render: (row) => row.cardUid },
            { key: "employeeCode", header: "職員番号", render: (row) => row.employeeCode },
            { key: "employeeName", header: "氏名", render: (row) => row.employeeName },
            { key: "isActive", header: "状態", render: (row) => (row.isActive ? "有効" : "無効") },
            { key: "assignedAt", header: "割当日時", render: (row) => props.formatters.formatDateTime(row.assignedAt) },
          ]}
        />
      ) : null}
      {activePanel === "cards-register" ? (
        <section id="cards-register" className="panel action-panel anchor-panel">
        <div className="panel-header">
          <div>
            <h3>カード登録</h3>
          </div>
        </div>
        <label>
          対象職員
          <select value={props.form.assignEmployeeId} onChange={(event) => props.actions.onAssignEmployeeIdChange(event.target.value)}>
            {props.data.employees.map((employee) => (
              <option key={employee.id} value={employee.id}>
                {employee.employeeCode} / {employee.name}
              </option>
            ))}
          </select>
        </label>
        <label>
          カードUID
          <input
            value={props.form.assignCardUid}
            onChange={(event) => props.actions.onAssignCardUidChange(event.target.value.toUpperCase())}
            placeholder="例: 012E4CE15C908F48"
          />
        </label>
        <button type="button" onClick={() => void props.actions.onAssignCard()}>
          登録する
        </button>
        {props.form.assignResult ? <p className="feedback">{props.form.assignResult}</p> : null}
        </section>
      ) : null}
    </section>
  );
}
