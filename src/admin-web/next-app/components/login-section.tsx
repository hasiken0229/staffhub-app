type LoginSectionProps = {
  loginId: string;
  password: string;
  authMessage: string;
  onLoginIdChange: (value: string) => void;
  onPasswordChange: (value: string) => void;
  onLogin: () => Promise<void>;
};

export function LoginSection(props: LoginSectionProps) {
  return (
    <main className="login-shell is-simple">
      <section className="login-card login-card-simple">
        <div className="login-card-header">
          <h2>ログイン情報</h2>
          <p className="login-note">ログインIDとパスワードを入力してください。</p>
        </div>

        <form
          className="login-form"
          onSubmit={(event) => {
            event.preventDefault();
            void props.onLogin();
          }}
        >
          <label>
            ログインID
            <input
              value={props.loginId}
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
          {props.authMessage ? <p className="feedback">{props.authMessage}</p> : null}
        </form>
      </section>
    </main>
  );
}
