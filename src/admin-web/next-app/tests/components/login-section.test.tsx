import { useState } from "react";
import { act, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { LoginSection } from "@/components/login-section";
import loginMessages from "@/lib/login-messages.json";
import { ApiRequestError, type InitialPasswordChallenge } from "@/lib/api";

describe("LoginSection", () => {
  it("管理者初回変更で確認入力を照合し、再入力可能なエラーと失効を区別する", async () => {
    const user = userEvent.setup();
    const token = crypto.randomUUID();
    const password = crypto.randomUUID();
    const login = vi.fn().mockResolvedValue({ requiresPasswordChange: true, passwordChangeToken: token, expiresInSeconds: 600 });
    const change = vi.fn().mockRejectedValueOnce(new ApiRequestError("仮パスワードと異なるものを入力してください。", 422))
      .mockRejectedValueOnce(new ApiRequestError("期限切れ", 401, "PASSWORD_CHANGE_EXPIRED"));
    render(<LoginHarness onLogin={login} onInitialPasswordChange={change} />);
    await user.click(screen.getByRole("button", { name: "ログインする" }));
    expect(screen.getByRole("heading", { name: loginMessages.initialTitle })).toBeVisible();
    const first = screen.getByLabelText("新しいパスワード", { exact: true });
    const confirm = screen.getByLabelText("新しいパスワード（確認）");
    await user.type(first, password);
    await user.type(confirm, password + "x");
    const submit = screen.getByRole("button", { name: "変更して管理画面を開く" });
    await user.click(submit);
    expect(change).not.toHaveBeenCalled();
    expect(screen.getByRole("alert")).toHaveTextContent(loginMessages.initialMismatch);
    await user.clear(confirm); await user.type(confirm, password);
    await user.click(submit);
    expect(change).toHaveBeenCalledWith(token, password, password);
    expect(screen.getByRole("alert")).toHaveTextContent("仮パスワード");
    await user.click(submit);
    expect(screen.getByRole("heading", { name: "ログイン情報" })).toBeVisible();
    expect(screen.getByRole("alert")).toHaveTextContent(loginMessages.initialExpired);
    expect(screen.getByLabelText("パスワード", { exact: true })).toHaveValue("");
  });

  it("前画面から引き継いだセッション切れを一度だけ読み上げ対象にする", () => {
    window.sessionStorage.setItem("staffhub-login-notice", "session-expired");
    const first = render(<LoginHarness onLogin={vi.fn()} />);
    expect(screen.getByRole("status")).toHaveTextContent(loginMessages.expired);
    expect(screen.getByRole("status")).toHaveAttribute("aria-live", "polite");
    expect(window.sessionStorage.getItem("staffhub-login-notice")).toBeNull();
    first.unmount();
    render(<LoginHarness onLogin={vi.fn()} />);
    expect(screen.getByRole("status")).toBeEmptyDOMElement();
  });

  it("再設定後のログイン画面に完了通知とメールアドレスを引き継ぐ", async () => {
    const user = userEvent.setup();
    window.history.replaceState(null, "", "/?resetToken=fixture&email=staff%40example.test");
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { success: true } }), {
      headers: { "Content-Type": "application/json" },
    })));
    try {
      render(<LoginHarness onLogin={vi.fn()} />);
      await user.type(screen.getByLabelText("新しいパスワード", { exact: true }), "NewPassword123!");
      await user.type(screen.getByLabelText("新しいパスワード（確認）"), "NewPassword123!");
      await user.click(screen.getByRole("button", { name: "パスワードを再設定する" }));
      expect(await screen.findByRole("button", { name: "ログインする" })).toBeEnabled();
      expect(screen.getByRole("status")).toHaveTextContent(loginMessages.resetComplete);
      expect(screen.getByLabelText("メールアドレス")).toHaveValue("staff@example.test");
      expect(screen.getByLabelText("パスワード")).toHaveValue("");
      expect(window.location.search).toBe("");
    } finally {
      vi.unstubAllGlobals();
      window.history.replaceState(null, "", "/");
    }
  });

  it("入力した認証情報でログイン処理を実行する", async () => {
    const user = userEvent.setup();
    const onLogin = vi.fn().mockResolvedValue(undefined);

    render(<LoginHarness onLogin={onLogin} />);

    await user.type(screen.getByLabelText("メールアドレス"), "staff@example.test");
    await user.type(screen.getByLabelText("パスワード"), "Password123!");
    await user.click(screen.getByRole("button", { name: "ログインする" }));

    expect(screen.getByLabelText("メールアドレス")).toHaveValue("staff@example.test");
    expect(screen.getByLabelText("パスワード")).toHaveValue("Password123!");
    expect(onLogin).toHaveBeenCalledTimes(1);
    expect(onLogin).toHaveBeenCalledWith("staff@example.test", "Password123!");
  });

  it("ブラウザー補完で変更イベントがなくても画面の認証情報を送信する", async () => {
    const user = userEvent.setup();
    const onLogin = vi.fn().mockResolvedValue(undefined);

    render(<LoginHarness onLogin={onLogin} />);

    const loginId = screen.getByLabelText("メールアドレス") as HTMLInputElement;
    const password = screen.getByLabelText("パスワード") as HTMLInputElement;
    await user.click(loginId);
    loginId.value = "saved@example.test";
    password.value = " SavedPassword123! ";
    await user.click(screen.getByRole("button", { name: "ログインする" }));

    expect(onLogin).toHaveBeenCalledWith("saved@example.test", " SavedPassword123! ");
    expect(loginId).toHaveValue("saved@example.test");
    expect(password).toHaveValue(" SavedPassword123! ");
  });

  it("ログイン中は入力と送信を無効化して二重送信を防ぐ", async () => {
    const user = userEvent.setup();
    let resolveLogin: (() => void) | undefined;
    const onLogin = vi.fn(() => new Promise<void>((resolve) => {
      resolveLogin = resolve;
    }));

    render(<LoginHarness onLogin={onLogin} />);

    await user.click(screen.getByRole("button", { name: "ログインする" }));

    expect(screen.getByRole("button", { name: "ログイン中..." })).toBeDisabled();
    expect(screen.getByLabelText("メールアドレス")).toBeDisabled();
    expect(screen.getByLabelText("パスワード")).toBeDisabled();
    expect(onLogin).toHaveBeenCalledTimes(1);

    await act(async () => resolveLogin?.());
    expect(screen.getByRole("button", { name: "ログインする" })).toBeEnabled();
  });
});

function LoginHarness({ onLogin, onInitialPasswordChange = vi.fn() }: {
  onLogin: (loginId: string, password: string) => Promise<void | InitialPasswordChallenge>;
  onInitialPasswordChange?: (token: string, password: string, confirmation: string) => Promise<void>;
}) {
  const [loginId, setLoginId] = useState("");
  const [password, setPassword] = useState("");

  return (
    <LoginSection
      loginId={loginId}
      password={password}
      authMessage=""
      onLogin={onLogin}
      onInitialPasswordChange={onInitialPasswordChange}
      onLoginIdChange={setLoginId}
      onPasswordChange={setPassword}
    />
  );
}
