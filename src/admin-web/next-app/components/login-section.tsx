"use client";

import { useEffect, useState } from "react";
import type { FormEvent } from "react";
import { ApiRequestError, requestPasswordReset, resetPassword, type InitialPasswordChallenge } from "@/lib/api";
import loginMessages from "@/lib/login-messages.json";

type LoginSectionProps = {
  loginId: string;
  password: string;
  authMessage: string;
  onLoginIdChange: (value: string) => void;
  onPasswordChange: (value: string) => void;
  onLogin: (loginId: string, password: string) => Promise<InitialPasswordChallenge | void>;
  onInitialPasswordChange: (token: string, password: string, confirmation: string) => Promise<void>;
};

export function LoginSection(props: LoginSectionProps) {
  const [mode, setMode] = useState<"login" | "forgot" | "reset" | "initial">("login");
  const [challenge, setChallenge] = useState<InitialPasswordChallenge | null>(null);
  const [resetEmail, setResetEmail] = useState("");
  const [resetToken, setResetToken] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [newPasswordConfirmation, setNewPasswordConfirmation] = useState("");
  const [resetMessage, setResetMessage] = useState("");
  const [notice, setNotice] = useState("");
  const [isLoginSubmitting, setIsLoginSubmitting] = useState(false);
  // Enable saved credential suggestions only after the user enters either field.
  const [isLoginAutofillEnabled, setIsLoginAutofillEnabled] = useState(false);
  const [isResetSubmitting, setIsResetSubmitting] = useState(false);

  function enableLoginAutofill() {
    setIsLoginAutofillEnabled(true);
  }

  useEffect(() => {
    if (typeof window === "undefined") {
      return;
    }

    const params = new URLSearchParams(window.location.search);
    const token = params.get("resetToken") ?? "";
    if (window.sessionStorage.getItem("staffhub-login-notice") === "session-expired") {
      window.sessionStorage.removeItem("staffhub-login-notice");
      if (!token) setNotice(loginMessages.expired);
    }
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
    setNotice("");
    try {
      await requestPasswordReset(resetEmail);
      setNotice("再設定用メールを送信しました。メールをご確認ください。");
    } catch (error) {
      setResetMessage(error instanceof ApiRequestError && error.status === 0 ? loginMessages.offlineForgot
        : error instanceof Error ? error.message : "再設定メールの送信に失敗しました。");
    } finally {
      setIsResetSubmitting(false);
    }
  }

  async function handleLoginSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isLoginSubmitting) {
      return;
    }

    // Password managers may update the inputs without firing React's onChange.
    const formData = new FormData(event.currentTarget);
    const loginId = String(formData.get("username") ?? "");
    const password = String(formData.get("password") ?? "");
    props.onLoginIdChange(loginId);
    props.onPasswordChange(password);
    setIsLoginSubmitting(true);
    setNotice("");
    setResetMessage("");
    try {
      const required = await props.onLogin(loginId, password);
      if (required) {
        setChallenge(required);
        props.onPasswordChange("");
        setNewPassword("");
        setNewPasswordConfirmation("");
        setMode("initial");
      }
    } finally {
      setIsLoginSubmitting(false);
    }
  }

  async function handleResetSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setIsResetSubmitting(true);
    setResetMessage("");
    setNotice("");
    try {
      await resetPassword(resetEmail, resetToken, newPassword, newPasswordConfirmation);
      setNotice(loginMessages.resetComplete);
      setMode("login");
      props.onLoginIdChange(resetEmail);
      props.onPasswordChange("");
      setResetToken("");
      setNewPassword("");
      setNewPasswordConfirmation("");
      if (typeof window !== "undefined") {
        window.history.replaceState(null, "", window.location.pathname);
      }
    } catch (error) {
      setResetMessage(error instanceof ApiRequestError && error.status === 0 ? loginMessages.offlineReset
        : error instanceof Error ? error.message : "パスワード再設定に失敗しました。");
    } finally {
      setIsResetSubmitting(false);
    }
  }

  async function handleInitialSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!challenge || isResetSubmitting) return;
    if (Array.from(newPassword).length < 8 || newPassword !== newPasswordConfirmation) {
      setResetMessage(Array.from(newPassword).length < 8 ? loginMessages.initialShort : loginMessages.initialMismatch);
      return;
    }
    setResetMessage("");
    setIsResetSubmitting(true);
    try {
      await props.onInitialPasswordChange(challenge.passwordChangeToken, newPassword, newPasswordConfirmation);
      changeMode("login");
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 422) {
        setResetMessage(Object.values(error.fieldErrors).flat()[0] || error.message);
      } else {
        changeMode("login");
        setResetMessage(error instanceof ApiRequestError && error.status === 401 ? loginMessages.initialExpired : loginMessages.initialUnknown);
      }
    } finally {
      setIsResetSubmitting(false);
    }
  }

  function changeMode(nextMode: "login" | "forgot") {
    setChallenge(null);
    setNewPassword("");
    setNewPasswordConfirmation("");
    setMode(nextMode);
    setNotice("");
    setResetMessage("");
  }

  return (
    <main className="login-shell is-simple">
      <section className="login-card login-card-simple">
        <div className="login-card-header">
          <h2>{mode === "login" ? "ログイン情報" : mode === "forgot" ? "パスワード再設定" : mode === "initial" ? loginMessages.initialTitle : "新しいパスワード"}</h2>
          <p className="login-note">
            {mode === "login"
              ? "メールアドレスとパスワードを入力してください。"
              : mode === "forgot"
                ? "職員登録済みのメールアドレスへ再設定用URLを送信します。"
                : mode === "initial" ? loginMessages.initialNote : "メールに記載されたURLから新しいパスワードを設定します。"}
          </p>
          <p className="login-feedback" role="status" aria-live="polite" aria-atomic="true">{notice}</p>
          <p className="login-feedback" role="alert" aria-atomic="true">{resetMessage || (mode === "login" && !notice ? props.authMessage : "")}</p>
        </div>

        {mode === "login" ? (
          <form
            className="login-form"
            method="post"
            autoComplete={isLoginAutofillEnabled ? "on" : "off"}
            aria-busy={isLoginSubmitting}
            onSubmit={(event) => void handleLoginSubmit(event)}
          >
            <label>
              メールアドレス
              <input
                value={props.loginId}
                id="username"
                name="username"
                type="email"
                autoComplete={isLoginAutofillEnabled ? "username" : "off"}
                readOnly={!isLoginAutofillEnabled}
                onPointerDown={enableLoginAutofill}
                onFocus={enableLoginAutofill}
                disabled={isLoginSubmitting}
                onChange={(event) => props.onLoginIdChange(event.target.value)}
              />
            </label>
            <label>
              パスワード
              <input
                type="password"
                value={props.password}
                id="current-password"
                name="password"
                autoComplete={isLoginAutofillEnabled ? "current-password" : "new-password"}
                readOnly={!isLoginAutofillEnabled}
                onPointerDown={enableLoginAutofill}
                onFocus={enableLoginAutofill}
                disabled={isLoginSubmitting}
                onChange={(event) => props.onPasswordChange(event.target.value)}
              />
            </label>
            <button type="submit" className="login-submit" disabled={isLoginSubmitting}>
              {isLoginSubmitting ? "ログイン中..." : "ログインする"}
            </button>
            <button type="button" className="text-link-button login-help-button" disabled={isLoginSubmitting} onClick={() => {
              setIsLoginAutofillEnabled(false);
              changeMode("forgot");
            }}>
              パスワードを忘れた方
            </button>
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
            <button type="button" className="secondary" onClick={() => changeMode("login")}>
              ログインに戻る
            </button>
          </form>
        ) : null}

        {mode === "reset" || mode === "initial" ? (
          <form className="login-form" onSubmit={mode === "initial" ? handleInitialSubmit : handleResetSubmit} aria-busy={isResetSubmitting}>
            {mode === "reset" ? <label>
              メールアドレス
              <input
                value={resetEmail}
                type="email"
                autoComplete="email"
                onChange={(event) => setResetEmail(event.target.value)}
              />
            </label> : null}
            <label>
              新しいパスワード
              <input
                type="password"
                value={newPassword}
                required={mode === "initial"}
                minLength={mode === "initial" ? 8 : undefined}
                autoFocus={mode === "initial"}
                disabled={isResetSubmitting}
                autoComplete="new-password"
                onChange={(event) => setNewPassword(event.target.value)}
              />
            </label>
            <label>
              新しいパスワード（確認）
              <input
                type="password"
                value={newPasswordConfirmation}
                required={mode === "initial"}
                minLength={mode === "initial" ? 8 : undefined}
                disabled={isResetSubmitting}
                autoComplete="new-password"
                onChange={(event) => setNewPasswordConfirmation(event.target.value)}
              />
            </label>
            <button type="submit" className="login-submit" disabled={isResetSubmitting}>
              {isResetSubmitting ? "設定中..." : mode === "initial" ? "変更して管理画面を開く" : "パスワードを再設定する"}
            </button>
            <button type="button" className="secondary" disabled={isResetSubmitting} onClick={() => changeMode("login")}>
              ログインに戻る
            </button>
          </form>
        ) : null}
      </section>
    </main>
  );
}
