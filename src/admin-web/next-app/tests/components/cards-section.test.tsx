import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { CardsSection } from "@/components/dashboard-sections/cards-section";

function createProps() {
  const onRevokeCard = vi.fn().mockResolvedValue(undefined);
  const onDeleteCard = vi.fn().mockResolvedValue(undefined);
  return {
    data: {
      activePanel: "cards-list",
      cards: [{ id: 30, employeeId: 1, cardUid: "D0D0D0D0D0D0D0D0", employeeCode: "E0001", employeeName: "確認職員", isActive: true, assignedAt: "2026-07-17T09:00:00" }],
      employees: [],
    },
    form: { assignEmployeeId: "", assignCardUid: "", assignResult: "" },
    actions: {
      onAssignEmployeeIdChange: vi.fn(),
      onAssignCardUidChange: vi.fn(),
      onAssignCard: vi.fn(),
      onRevokeCard,
      onDeleteCard,
    },
    formatters: { formatDateTime: (value?: string | null) => value ?? "-" },
  };
}

describe("CardsSection", () => {
  it("カード解除は確認後にだけ実行される", async () => {
    const user = userEvent.setup();
    const props = createProps();
    render(<CardsSection {...props} />);

    await user.click(screen.getByRole("button", { name: "解除" }));

    expect(screen.getByRole("dialog", { name: "カード割当を解除しますか？" })).toHaveTextContent("このカードでは打刻できません");
    expect(props.actions.onRevokeCard).not.toHaveBeenCalled();

    await user.click(screen.getByRole("button", { name: "割当を解除" }));

    expect(props.actions.onRevokeCard).toHaveBeenCalledWith(30);
  });

  it("カード削除は確認後にだけ実行される", async () => {
    const user = userEvent.setup();
    const props = createProps();
    render(<CardsSection {...props} />);

    await user.click(screen.getByRole("button", { name: "削除" }));

    expect(screen.getByRole("dialog", { name: "カードを削除しますか？" })).toHaveTextContent("削除前に内容を確認");
    expect(props.actions.onDeleteCard).not.toHaveBeenCalled();

    await user.click(screen.getByRole("button", { name: "カードを削除" }));

    expect(props.actions.onDeleteCard).toHaveBeenCalledWith(30);
  });
});
