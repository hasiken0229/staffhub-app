import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { ConfirmDialog } from "@/components/confirm-dialog";

describe("ConfirmDialog", () => {
  it("閉じた後は起点にフォーカスを戻し、処理中は二重確定できない", () => {
    const trigger = document.createElement("button");
    document.body.appendChild(trigger);
    trigger.focus();
    const props = { title: "確認", message: "確認内容", confirmLabel: "実行", onCancel: vi.fn(), onConfirm: vi.fn() };
    const view = render(<ConfirmDialog {...props} open />);
    view.rerender(<ConfirmDialog {...props} open busy />);
    expect(screen.getByRole("button", { name: "処理中..." })).toBeDisabled();
    fireEvent.keyDown(screen.getByRole("dialog"), { key: "Escape" });
    expect(props.onCancel).not.toHaveBeenCalled();
    view.rerender(<ConfirmDialog {...props} open={false} />);
    expect(trigger).toHaveFocus();
    trigger.remove();
  });
  it("開いた時は戻るボタンにフォーカスし、破棄操作を明示する", () => {
    render(
      <ConfirmDialog
        open
        title="保存していない変更があります"
        message="ログアウトすると変更は破棄されます。"
        confirmLabel="変更を破棄してログアウト"
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );

    expect(screen.getByRole("dialog")).toBeVisible();
    expect(screen.getByRole("button", { name: "戻る" })).toHaveFocus();
    expect(screen.getByRole("button", { name: "変更を破棄してログアウト" })).toBeVisible();
  });

  it("閉じている時はダイアログを表示しない", () => {
    render(
      <ConfirmDialog
        open={false}
        title="確認"
        message="確認内容"
        confirmLabel="実行"
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );

    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
  });

  it("確認中は背景スクロールを止め、Tabと逆向きTabで本文とボタンを循環する", () => {
    const initialOverflow = document.documentElement.style.overflow;
    const view = render(<ConfirmDialog open title="確認" message="長文" confirmLabel="申請する" onCancel={vi.fn()} onConfirm={vi.fn()} />);
    expect(document.documentElement.style.overflow).toBe("hidden");
    const last = screen.getByRole("button", { name: "申請する" });
    const body = screen.getByRole("region", { name: "確認" });
    last.focus();
    fireEvent.keyDown(last, { key: "Tab" });
    expect(body).toHaveFocus();
    fireEvent.keyDown(body, { key: "Tab", shiftKey: true });
    expect(last).toHaveFocus();
    view.unmount();
    expect(document.documentElement.style.overflow).toBe(initialOverflow);
  });

  it("Escキーは戻る操作として扱う", () => {
    const onCancel = vi.fn();
    render(
      <ConfirmDialog
        open
        title="確認"
        message="確認内容"
        confirmLabel="実行"
        onCancel={onCancel}
        onConfirm={vi.fn()}
      />,
    );

    fireEvent.keyDown(screen.getByRole("dialog"), { key: "Escape" });

    expect(onCancel).toHaveBeenCalledOnce();
  });
});
