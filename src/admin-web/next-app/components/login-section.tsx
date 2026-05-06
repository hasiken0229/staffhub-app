"use client";

import { useEffect, useState } from "react";
import type { FormEvent } from "react";
import { requestPasswordReset, resetPassword } from "@/lib/api";

type LoginSectionProps = {
  loginId: string;
  password: string;
  authMessage: string;
  onLoginIdChange: (value: string) => void;
  onPasswordChange: (value: string) => void;
  onLogin: () => Promise<void>;
};

export function LoginSection(props: LoginSectionProps) {
  const [mode, setMode] = useState<"login" | "forgot" | "reset">("login");
  const [resetEmail, setResetEmail] = useState("");
  const [resetToken, setResetToken] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [newPasswordConfirmation, setNewPasswordConfirmation] = useState("");
  const [resetMessage, setResetMessage] = useState("");
  const [isResetSubmitting, setIsResetSubmitting] = useState(false);

  useEffect(() => {
    if (typeof window === "undefined") {
      return;
    }

    const params = new URLSearchParams(window.location.search);
    const token = params.get("resetToken") ?? "";
    const email = params.get("email") ?? "";
    if (token) {
      setMode("reset");
      setResetToken(token);
      setResetEmail(email);
    }
  }, []);

  async function handleForgotSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setIsResetSubmitting(true);
    setResetMessage("");
    try {
      await requestPasswordReset(resetEmail);
      setResetMessage("再設定用メールを送信しました。メールをご確認ください。");
    } catch (error) {
      setResetMessage(error instanceof Error ? error.message : "再設定メールの送信に失敗しました。");
    } finally {
      setIsResetSubmitting(false);
    }
  }

  async function handleResetSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setIsResetSubmitting(true);
    setResetMessage("");
    try {
      await resetPassword(resetEmail, resetToken, newPassword, newPasswordConfirmation);
      setResetMessage("パスワードを再設定しました。新しいパスワードでログインしてください。");
      setMode("login");
      props.onLoginIdChange(resetEmail);
      setNewPassword("");
      setNewPasswordConfirmation("");
      if (typeof window !== "undefined") {
        window.history.replaceState(null, "", window.location.pathname);
      }
    } catch (error) {
      setResetMessage(error instanceof Error ? error.message : "パスワード再設定に失敗しました。");
    } finally {
      setIsResetSubmitting(false);
    }
  }

  return (
    <main className="login-shell is-simple">
      <section className="login-card login-card-simple">
        <div className="login-card-header">
          <h2>{mode === "login" ? "ログイン情報" : mode === "forgot" ? "パスワード再設定" : "新しいパスワード"}</h2>
          <p className="login-note">
            {mode === "login"
              ? "メールアドレスとパスワードを入力してください。"
              : mode === "forgot"
                ? "職員登録済みのメールアドレスへ再設定用URLを送信します。"
                : "メールに記載されたURLから新しいパスワードを設定します。"}
          </p>
        </div>

        {mode === "login" ? (
          <form
            className="login-form"
            onSubmit={(event) => {
              event.preventDefault();
              void props.onLogin();
            }}
          >
            <label>
              メールアドレス
              <input
                value={props.loginId}
                type="email"
                autoComplete="username"
                onChange={(event) => props.onLoginIdChange(event.target.value)}
              />
            </label>
            <label>
              パスワード
              <input
                type="password"
                value={props.password}
                autoComplete="current-password"
                onChange={(event) => props.onPasswordChange(event.target.value)}
              />
            </label>
            <button type="submit" className="login-submit">
              ログインする
            </button>
            <button type="button" className="text-link-button login-help-button" onClick={() => setMode("forgot")}>
              パスワードを忘れた方
            </button>
            {props.authMessage ? <p className="feedback">{props.authMessage}</p> : null}
            {resetMessage ? <p className="feedback">{resetMessage}</p> : null}
          </form>
        ) : null}

        {mode === "forgot" ? (
          <form className="login-form" onSubmit={(event) => void handleForgotSubmit(event)}>
            <label>
              メールアドレス
              <input
                value={resetEmail}
                type="email"
                autoComplete="email"
                onChange={(event) => setResetEmail(event.target.value)}
              />
            </label>
            <button type="submit" className="login-submit" disabled={isResetSubmitting}>
              {isResetSubmitting ? "送信中..." : "再設定メールを送信する"}
            </button>
            <button type="button" className="secondary" onClick={() => setMode("login")}>
              ログインに戻る
            </button>
            {resetMessage ? <p className="feedback">{resetMessage}</p> : null}
          </form>
        ) : null}

        {mode === "reset" ? (
          <form className="login-form" onSubmit={(event) => void handleResetSubmit(event)}>
            <label>
              メールアドレス
              <input
                value={resetEmail}
                type="email"
                autoComplete="email"
                onChange={(event) => setResetEmail(event.target.value)}
              />
            </label>
            <label>
              新しいパスワード
              <input
                type="password"
                value={newPassword}
                autoComplete="new-password"
                onChange={(event) => setNewPassword(event.target.value)}
              />
            </label>
            <label>
              新しいパスワード（確認）
              <input
                type="password"
                value={newPasswordConfirmation}
                autoComplete="new-password"
                onChange={(event) => setNewPasswordConfirmation(event.target.value)}
              />
            </label>
            <button type="submit" className="login-submit" disabled={isResetSubmitting}>
              {isResetSubmitting ? "設定中..." : "パスワードを再設定する"}
            </button>
            <button type="button" className="secondary" onClick={() => setMode("login")}>
              ログインに戻る
            </button>
            {resetMessage ? <p className="feedback">{resetMessage}</p> : null}
          </form>
        ) : null}
      </section>
    </main>
  );
}
