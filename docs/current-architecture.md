# 現行アーキテクチャ整理

## 本番で使う構成

- `src/backend-laravel-app`
  - 本番API本体。Laravel 11 + MySQL を前提に運用する。
- `src/admin-web/next-app`
  - 管理画面と職員ポータル。静的エクスポートして `/dakoku/admin/` へ配置する。
- `src/windows-punch/StaffHub.PunchApp`
  - RC-S380 打刻端末用の Windows アプリ。

## 管理画面の現在構成

- `src/admin-web/next-app/components/admin-dashboard.tsx`
  - 画面全体の container。認証状態、現在セクション、データ読込の配線を担う。
  - 初期の約 2953 行から、現在は約 844 行まで分割済み。
  - 行数最小化より、各 section に `data / filters / form / actions / formatters` を束で渡す配線を優先している。
- `src/admin-web/next-app/components/admin-portal-shell.tsx`
  - 管理画面の共通 shell。
- `src/admin-web/next-app/components/login-section.tsx`
  - 共通ログイン画面。
- `src/admin-web/next-app/components/daily-attendance-graph.tsx`
  - 日次勤怠一覧の勤務グラフ表示。
- `src/admin-web/next-app/components/payroll-statement-detail-card.tsx`
  - 管理者/職員共通の給与明細詳細。
- `src/admin-web/next-app/components/dashboard-sections`
  - 画面単位の表示コンポーネント群。
  - `attendance-section.tsx`
  - `audit-section.tsx`
  - `cards-section.tsx`
  - `dashboard-overview-section.tsx`
  - `employee-portal-section.tsx`
  - `employees-section.tsx`
  - `leave-section.tsx`
  - `notices-section.tsx`
  - `payroll-section.tsx`
  - `reports-section.tsx`
  - `system-section.tsx`

## フロント状態管理の現在構成

- `src/admin-web/next-app/hooks`
  - 画面 state を `*-state.ts`、API 操作を `*-actions.ts` に分割している。
- 現在の主な state hook
  - `use-attendance-admin-state.ts`
  - `use-auth-state.ts`
  - `use-card-assignment-state.ts`
  - `use-leave-admin-state.ts`
  - `use-notice-form-state.ts`
  - `use-payroll-admin-state.ts`
  - `use-report-state.ts`
  - `use-audit-filter-state.ts`
- 現在の主な action hook
  - `use-attendance-actions.ts`
  - `use-audit-actions.ts`
  - `use-dashboard-session-actions.ts`
  - `use-leave-actions.ts`
  - `use-payroll-actions.ts`
- 画面初期化と導出値
  - `use-admin-dashboard-effects.ts`
  - `use-admin-dashboard-derived-data.ts`
- 画面初期値・セクション定義
  - `src/admin-web/next-app/lib/dashboard-defaults.ts`

## バックエンドの現在構成

- 監査ログ
  - `app/Services/AuditLogService.php`
  - `app/Http/Controllers/Api/Admin/AuditLogAdminController.php`
- 通知メール
  - `app/Services/NotificationMailService.php`
  - `app/Mail/SystemNotificationMail.php`
  - `resources/views/emails/system-notification-text.blade.php`
- 打刻異常検知
  - `app/Services/AttendanceService.php`
  - `未退勤`, `打刻漏れ`, `短時間の連続打刻` を付与

## テストと確認

- フロントエンド
  - `src/admin-web/next-app` で `npm run build` を継続確認している。
- バックエンド Feature テスト
  - `tests/Feature/AuthApiTest.php`
  - `tests/Feature/AuditLogAdminApiTest.php`
  - `tests/Feature/AttendanceAlertsApiTest.php`
  - `tests/Feature/NotificationMailFlowTest.php`
- ローカル PHP 実行環境
  - `C:/php/php.ini` で `mbstring`, `pdo_sqlite`, `sqlite3`, `openssl` を有効化済み
  - `phpunit.xml` は SQLite テストDB前提に調整済み

## 参考・保留中の構成

- `src/mobile-app`
  - Flutter 想定の予定領域。現時点では専用アプリ未実装。
- `src/backend`
  - 初期 .NET バックエンド試作。参照用の旧資産。
- `src/admin-web/prototype`
  - 管理画面の静的試作。参照用の旧資産。
- `src/backend-laravel-skeleton`
  - Laravel 初期骨組み。参照用の旧資産。

## 運用方針

- 旧資産は即削除せず、移行完了後 1 給与サイクルの確認を終えてから整理する。
- 職員向けは Flutter を急がず、既存ポータルのレスポンシブ/PWA 運用を先行する。
- API 互換を崩す変更は避け、管理画面の責務分割と監査・テストの整備を優先する。
- 管理画面の追加改修では `admin-dashboard.tsx` に直接 JSX や state を積み増さず、まず `dashboard-sections` と `hooks` への配置を検討する。
- 表示 helper や初期定数も container に残さず、`components` / `hooks` / `lib` へ逃がしてから配線する。
- section props はフラットに増やさず、`data / filters / form / actions / formatters` の束で整理する。

## セキュリティ運用メモ

- `.env`、SSH 秘密鍵、PPK はワークスペースに置かず、安全な保管先へ移す。
- すでに配置済みの本番資格情報は漏えい前提でローテーションする。
- メール送信は `MAIL_MAILER=log` のまま本番化せず、SMTP 接続情報を設定して検証後に切り替える。
