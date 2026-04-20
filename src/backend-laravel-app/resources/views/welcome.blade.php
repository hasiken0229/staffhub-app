<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>勤怠管理</title>
        <style>
            :root {
                --ink: #152033;
                --muted: #5d6a7a;
                --line: #d9e1ea;
                --paper: #f6f8fb;
                --accent: #d94f38;
                --accent-deep: #ab341f;
                --panel: #ffffff;
                --blue: #2e5b7f;
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                font-family: "Yu Gothic UI", "Hiragino Sans", "Meiryo", sans-serif;
                color: var(--ink);
                background:
                    radial-gradient(circle at top left, rgba(217, 79, 56, 0.18), transparent 28%),
                    radial-gradient(circle at top right, rgba(46, 91, 127, 0.16), transparent 26%),
                    linear-gradient(180deg, #fffdfc 0%, var(--paper) 100%);
            }

            a {
                color: inherit;
                text-decoration: none;
            }

            .shell {
                width: min(1120px, calc(100% - 32px));
                margin: 0 auto;
                padding: 28px 0 56px;
            }

            .hero {
                display: grid;
                grid-template-columns: 1.3fr 0.9fr;
                gap: 24px;
                align-items: stretch;
            }

            .panel {
                background: rgba(255, 255, 255, 0.92);
                border: 1px solid rgba(217, 225, 234, 0.9);
                border-radius: 28px;
                box-shadow: 0 22px 50px rgba(21, 32, 51, 0.08);
                backdrop-filter: blur(10px);
            }

            .hero-copy {
                padding: 34px;
            }

            .eyebrow {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                padding: 8px 14px;
                border-radius: 999px;
                background: rgba(217, 79, 56, 0.1);
                color: var(--accent-deep);
                font-size: 13px;
                font-weight: 700;
                letter-spacing: 0.04em;
            }

            h1 {
                margin: 18px 0 14px;
                font-size: clamp(34px, 5vw, 54px);
                line-height: 1.05;
                letter-spacing: -0.03em;
            }

            .lead {
                margin: 0;
                max-width: 42rem;
                color: var(--muted);
                font-size: 17px;
                line-height: 1.85;
            }

            .actions {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                margin-top: 26px;
            }

            .button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                min-height: 48px;
                padding: 0 18px;
                border-radius: 14px;
                border: 1px solid transparent;
                font-weight: 700;
            }

            .button-primary {
                background: var(--ink);
                color: #fff;
            }

            .button-secondary {
                background: #fff;
                border-color: var(--line);
                color: var(--ink);
            }

            .hero-side {
                padding: 28px;
                display: grid;
                gap: 14px;
                align-content: start;
            }

            .status-card {
                padding: 18px 18px 16px;
                border-radius: 18px;
                background: linear-gradient(135deg, rgba(46, 91, 127, 0.92), rgba(21, 32, 51, 0.96));
                color: #fff;
            }

            .status-card strong,
            .status-card span {
                display: block;
            }

            .status-card strong {
                font-size: 14px;
                letter-spacing: 0.04em;
                opacity: 0.78;
            }

            .status-card span {
                margin-top: 8px;
                font-size: 24px;
                font-weight: 800;
            }

            .status-card small {
                display: block;
                margin-top: 8px;
                opacity: 0.8;
                line-height: 1.6;
            }

            .checklist {
                padding: 0;
                margin: 0;
                list-style: none;
                display: grid;
                gap: 10px;
            }

            .checklist li {
                padding: 14px 15px;
                border-radius: 16px;
                background: #fff;
                border: 1px solid var(--line);
            }

            .checklist b {
                display: block;
                font-size: 14px;
                margin-bottom: 4px;
            }

            .checklist span {
                color: var(--muted);
                font-size: 14px;
                line-height: 1.7;
            }

            .grid {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 20px;
                margin-top: 22px;
            }

            .feature {
                padding: 24px;
            }

            .feature h2 {
                margin: 0 0 10px;
                font-size: 20px;
            }

            .feature p {
                margin: 0;
                color: var(--muted);
                line-height: 1.8;
                font-size: 14px;
            }

            .meta {
                margin-top: 22px;
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
            }

            .tag {
                display: inline-flex;
                align-items: center;
                min-height: 38px;
                padding: 0 14px;
                border-radius: 999px;
                background: rgba(255, 255, 255, 0.8);
                border: 1px solid var(--line);
                font-size: 13px;
                color: var(--muted);
            }

            .footnote {
                margin-top: 22px;
                padding: 18px 20px;
                border-radius: 18px;
                background: rgba(255, 255, 255, 0.76);
                border: 1px dashed rgba(46, 91, 127, 0.28);
                color: var(--muted);
                line-height: 1.8;
                font-size: 14px;
            }

            code {
                font-family: Consolas, "Courier New", monospace;
                font-size: 0.95em;
                color: var(--blue);
            }

            @media (max-width: 900px) {
                .hero,
                .grid {
                    grid-template-columns: 1fr;
                }

                .shell {
                    width: min(100% - 20px, 1120px);
                    padding-top: 16px;
                    padding-bottom: 28px;
                }

                .hero-copy,
                .hero-side,
                .feature {
                    padding: 22px;
                }
            }
        </style>
    </head>
    <body>
        <div class="shell">
            <section class="hero">
                <div class="panel hero-copy">
                    <span class="eyebrow">勤怠管理システム</span>
                    <h1>職員打刻・休暇申請・給与明細の<br>運用基盤を準備できました。</h1>
                    <p class="lead">
                        このページは <code>/dakoku</code> 配下の仮トップです。
                        Windows打刻アプリ、管理Web、モバイル申請APIを
                        Xserver共有レンタルサーバー向け Laravel 構成で
                        置ける状態まで進んでいます。
                    </p>

                    <div class="actions">
                        <a class="button button-primary" href="{{ url('/api/admin/cards') }}">管理API確認</a>
                        <a class="button button-secondary" href="{{ url('/up') }}">Laravelヘルス確認</a>
                    </div>

                    <div class="meta">
                        <span class="tag">URL: {{ url('/') }}</span>
                        <span class="tag">Laravel v{{ Illuminate\Foundation\Application::VERSION }}</span>
                        <span class="tag">PHP v{{ PHP_VERSION }}</span>
                    </div>
                </div>

                <aside class="panel hero-side">
                    <div class="status-card">
                        <strong>Current Status</strong>
                        <span>初期配置完了</span>
                        <small>
                            ルーティング、MySQL migration、PHP 8.3 対応 vendor の
                            調整まで反映済みです。
                        </small>
                    </div>

                    <ul class="checklist">
                        <li>
                            <b>打刻PC</b>
                            <span>RC-S380 を使う Windows クライアントから API 送信する想定です。</span>
                        </li>
                        <li>
                            <b>職員向け機能</b>
                            <span>有給、欠勤、特休の申請と給与明細PDF配信をこのAPIで扱います。</span>
                        </li>
                        <li>
                            <b>次の作業</b>
                            <span>認証本実装、打刻アプリの送信先切替、管理画面の配備が次の工程です。</span>
                        </li>
                    </ul>
                </aside>
            </section>

            <section class="grid">
                <article class="panel feature">
                    <h2>1. カード打刻</h2>
                    <p>
                        PC常駐アプリから <code>/api/attendance/punch</code> へ送信し、
                        出勤・退勤・端末ハートビートを一元管理します。
                    </p>
                </article>

                <article class="panel feature">
                    <h2>2. 休暇申請</h2>
                    <p>
                        有給、欠勤、特休をモバイル側から申請し、
                        管理者が1段階承認で処理する前提です。
                    </p>
                </article>

                <article class="panel feature">
                    <h2>3. 給与明細</h2>
                    <p>
                        月次の給与明細PDFを安全に配信し、
                        閲覧履歴まで残せる構成を想定しています。
                    </p>
                </article>
            </section>

            <div class="footnote">
                仮トップを確認できたら、次は Windows 打刻アプリの送信先を
                <code>https://ikegami-wakaba.jp/dakoku/api/attendance/punch</code>
                に切り替えて、Xserver 上の API まで通るかを確認するのが自然です。
            </div>
        </div>
    </body>
</html>
