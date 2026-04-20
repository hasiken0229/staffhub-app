# 職員打刻・休暇申請・給与明細配信アプリ

## 現在の主構成

- `src/backend-laravel-app`
  - Laravel 11 の本番 API。勤怠、休暇、給与明細、通知、監査ログを提供します。
- `src/admin-web/next-app`
  - 管理画面と職員ポータル。Next.js の静的エクスポート前提です。
- `src/windows-punch/StaffHub.PunchApp`
  - RC-S380 を使った Windows 打刻アプリです。
- `src/mobile-app`
  - 将来の専用モバイルアプリ予定領域です。現状は未着手です。

## 補助資料

- `docs`
  - 基本設計、画面仕様、API I/O、DB 設計、Xserver 前提の再設計メモを格納しています。
- `docs/current-architecture.md`
  - 現行の採用構成、旧資産の扱い、セキュリティ運用メモを整理しています。
- `implementation_plan.md`
  - 現状確認と改善計画のたたき台です。

## フロントエンドの現在構成

- `src/admin-web/next-app/components/admin-dashboard.tsx`
  - 管理画面と職員ポータルの配線役です。初期の巨大コンポーネントから分割を進め、現在は約 844 行です。
  - 行数だけを減らすよりも、各セクションへ `data / filters / form / actions / formatters` の束を渡す形に寄せています。
- `src/admin-web/next-app/components/dashboard-sections`
  - 管理画面の各セクション単位コンポーネントです。
  - `dashboard`, `employees`, `cards`, `attendance`, `leave`, `notices`, `payroll`, `reports`, `system`, `audit`, `employee portal` を個別ファイルで管理します。
- `src/admin-web/next-app/components/admin-portal-shell.tsx`
  - 管理画面共通のサイドバー、ヘッダー、メトリクス表示です。
- `src/admin-web/next-app/components/login-section.tsx`
  - 共通ログイン画面です。
- `src/admin-web/next-app/components/daily-attendance-graph.tsx`
  - 日次勤怠の勤務グラフ表示です。親コンポーネントから描画 helper を外しています。
- `src/admin-web/next-app/components/payroll-statement-detail-card.tsx`
  - 管理者/職員の両方で使う給与明細詳細カードです。
- `src/admin-web/next-app/hooks`
  - 画面 state と action を機能単位でまとめています。
  - 例: `attendance`, `leave`, `payroll`, `auth`, `audit`, `report`, `notice`, `session`, `derived`, `effects`
- `src/admin-web/next-app/lib/dashboard-defaults.ts`
  - 管理画面初期値とセクション定義をまとめた定数ファイルです。

## 開発時の見方

1. 画面の見た目や配置を変えるときは `components/dashboard-sections` か共通 UI コンポーネントを見る。
2. フォーム state やフィルタ初期値を変えるときは `src/admin-web/next-app/hooks/*-state.ts` を見る。
3. API 呼び出しや再読込フローを変えるときは `src/admin-web/next-app/hooks/*-actions.ts`、画面初期化や導出値は `use-admin-dashboard-effects.ts` と `use-admin-dashboard-derived-data.ts` を見る。
4. セクション props を増やすときは、まず `data / filters / form / actions / formatters` のどこへ置くかを決めてから子コンポーネントへ渡す。
5. Laravel 側の API 変更時は `src/backend-laravel-app/tests/Feature` の既存テストを優先して更新する。

## 当面の運用方針

1. Laravel API と Next.js 管理画面の互換性を維持したまま、責務分割とテスト整備を進める。
2. 職員向けは Flutter 新規開発を急がず、既存ポータルをレスポンシブ/PWA として改善する。
3. 旧 `.NET / prototype / skeleton` は参照用に残し、本番移行後に段階的に整理する。
4. `.env` と鍵ファイルはワークスペース外で管理し、漏えいの可能性がある資格情報はローテーションする。

## 通知メール運用メモ

- Laravel 側のメール設定は `src/backend-laravel-app/.env` の `MAIL_*` を Xserver SMTP など実運用値へ設定する。
- 職員メールアドレス列は現行スキーマに未実装のため、`STAFFHUB_NOTIFICATION_FALLBACK_TO` に運用受信箱を設定すると、給与明細公開や承認結果メールを安全に受け取れる。
- 全体向けお知らせの送信先を固定したい場合は `STAFFHUB_ADMIN_NOTIFICATION_TO` に管理側の宛先をカンマ区切りで設定する。

## 現時点の確認状況

- フロントエンドは `src/admin-web/next-app` で `npm run build` が通る状態です。
- Laravel 側は `src/backend-laravel-app` で Feature テストを整備済みです。
  - `AuthApiTest.php`
  - `AuditLogAdminApiTest.php`
  - `AttendanceAlertsApiTest.php`
  - `NotificationMailFlowTest.php`
