import { useState } from "react";
import { ConfirmDialog } from "@/components/confirm-dialog";
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
    onRevokeCard: (cardId: number) => Promise<void>;
    onDeleteCard: (cardId: number) => Promise<void>;
  };
  formatters: {
    formatDateTime: (value?: string | null) => string;
  };
};

export function CardsSection(props: CardsSectionProps) {
  const activePanel = props.data.activePanel || "cards-list";
  const [pendingCardAction, setPendingCardAction] = useState<{ type: "revoke" | "delete"; card: CardAssignment } | null>(null);

  async function confirmCardAction() {
    if (!pendingCardAction) {
      return;
    }

    const action = pendingCardAction;
    setPendingCardAction(null);

    if (action.type === "revoke") {
      await props.actions.onRevokeCard(action.card.id);
    } else {
      await props.actions.onDeleteCard(action.card.id);
    }
  }

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
            {
              key: "actions",
              header: "操作",
              render: (row) => (
                <div className="table-action-row">
                  {row.isActive ? (
                    <button type="button" className="secondary table-action warning-action" onClick={() => setPendingCardAction({ type: "revoke", card: row })}>
                      解除
                    </button>
                  ) : null}
                  <button type="button" className="secondary table-action danger-action" onClick={() => setPendingCardAction({ type: "delete", card: row })}>
                    削除
                  </button>
                </div>
              ),
            },
          ]}
        />
      ) : null}
      {activePanel === "cards-list" && props.form.assignResult ? <p className="feedback">{props.form.assignResult}</p> : null}
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
            placeholder="例: D0D0D0D0D0D0D0D0"
          />
        </label>
        <button type="button" onClick={() => void props.actions.onAssignCard()}>
          登録する
        </button>
        {props.form.assignResult ? <p className="feedback">{props.form.assignResult}</p> : null}
        </section>
      ) : null}
      <ConfirmDialog
        open={pendingCardAction !== null}
        title={pendingCardAction?.type === "revoke" ? "カード割当を解除しますか？" : "カードを削除しますか？"}
        message={pendingCardAction
          ? pendingCardAction.type === "revoke"
            ? `${pendingCardAction.card.employeeCode} / ${pendingCardAction.card.employeeName}／カードUID: ${pendingCardAction.card.cardUid}。解除後、このカードでは打刻できません。`
            : `${pendingCardAction.card.employeeCode} / ${pendingCardAction.card.employeeName}／カードUID: ${pendingCardAction.card.cardUid}。履歴確認が必要な場合は、削除前に内容を確認してください。`
          : ""}
        confirmLabel={pendingCardAction?.type === "revoke" ? "割当を解除" : "カードを削除"}
        onCancel={() => setPendingCardAction(null)}
        onConfirm={() => void confirmCardAction()}
      />
    </section>
  );
}
