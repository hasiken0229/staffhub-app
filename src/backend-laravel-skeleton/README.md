# Laravel Skeleton For Xserver Shared

このディレクトリは、`Xserver共有レンタルサーバー` 前提で実装するための Laravel 雛形です。

## 含めているもの

- `routes/api.php`
- `app/Http/Controllers/Api`
- `app/Services`
- `database/migrations`

## 現時点で中身が入っている箇所

- `AttendanceService`
  - 打刻受付
  - 端末ハートビート
  - 打刻履歴一覧
  - 日次勤怠一覧
- `CardAssignmentService`
  - カード一覧
  - カード登録
  - カード失効
- `AttendancePunchController`
  - 業務エラーをAPI形式で返却
- `CardAdminController`
  - 業務エラーをAPI形式で返却

## 目的

- 共有サーバー向けの `PHP + MySQL` 実装を始めやすくする
- 既存の設計書を、そのまま Laravel の形へ落とし込む

## 想定の進め方

1. Laravel プロジェクトを新規作成
2. この配下のファイルを対応位置へコピー
3. `.env` に MySQL 接続設定を入れる
4. `php artisan migrate`
5. Controller / Service の中身を実装

## 前提

- Laravel 11 系想定
- DB は MySQL 8 系想定
- 認証は `Sanctum` または Session ベースを想定
