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
    <main className="login-shell">
      <section className="login-rail" aria-label="勤怠管理システム">
        <div className="login-brand">
          <span className="sidebar-logo">勤</span>
          <div>
            <strong>勤怠管理システム</strong>
            <span>職員打刻・休暇申請・給与明細</span>
          </div>
        </div>
        <div className="login-rail-body">
          <p className="panel-kicker">STAFF PORTAL</p>
          <h1>共通ログイン</h1>
          <p>管理者画面と職員ポータルを同じログインIDで利用できます。</p>
        </div>
        <dl className="login-status-list">
          <div>
            <dt>管理</dt>
            <dd>勤怠・休暇・給与</dd>
          </div>
          <div>
            <dt>職員</dt>
            <dd>申請・明細確認</dd>
          </div>
        </dl>
      </section>

      <section className="login-card login-card-simple">
        <div className="login-card-header">
          <p className="panel-kicker">SIGN IN</p>
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
