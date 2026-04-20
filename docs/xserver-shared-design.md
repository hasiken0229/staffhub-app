# Xserver共有レンタルサーバー前提 再設計書

## 1. 目的

本書は、SONY `RC-S380` を利用する職員打刻、有給等の休暇申請、給与明細PDF配信を、`Xserver共有レンタルサーバー` 上で運用する前提に合わせて再設計したものである。

今回の前提は以下とする。

- カードリーダー: `SONY RC-S380`
- 接続方式: `USB`
- 打刻端末: `Windows固定`
- 給与明細: `PDF配信のみ`
- 休暇区分: `有給 / 欠勤 / 特休`
- 承認フロー: `1段階`
- サーバー基盤: `Xserver共有レンタルサーバー`

## 2. 共有レンタルサーバー前提の制約

Xserver共有レンタルサーバーでは、一般に以下の前提で構成を組む必要がある。

- `PHP` を中心としたWebアプリ構成に寄せる
- DBは `MySQL / MariaDB` を利用する
- `常駐プロセス` 前提の設計は避ける
- バッチ処理は `Cron` を利用する
- サーバー側でUSBカードリーダー制御は行わない

したがって、元の `ASP.NET Core + PostgreSQL + Next.js常駐` 前提は採用せず、共有サーバー向けに以下へ置き換える。

- バックエンド: `PHP (Laravel推奨)`
- DB: `MySQL`
- 管理Web: `Laravel Blade` または `PHP描画`
- 職員向け画面: `PWA` もしくは `レスポンシブWeb`
- 打刻端末: `Windowsデスクトップアプリ` から `HTTPS API` 呼び出し

## 3. 推奨構成

## 3.1 全体構成

1. `Windows打刻アプリ`
2. `職員向けWeb/PWA`
3. `管理者向けWeb`
4. `PHP API`
5. `MySQL`
6. `給与明細PDF保存領域`

## 3.2 役割

### Windows打刻アプリ

- `RC-S380` からカードIDを読む
- サーバーの打刻APIへ `HTTPS POST`
- 通信断時はローカル保存
- 復旧後に未送信打刻を再送

### 職員向けWeb/PWA

- 休暇申請
- 申請履歴確認
- 給与明細PDF閲覧
- お知らせ確認

### 管理者向けWeb

- 職員管理
- カード紐付け管理
- 勤怠履歴確認
- 休暇承認
- 給与明細PDFアップロード

### PHP API

- 認証
- 打刻受付
- 勤怠集計
- 休暇申請管理
- 給与明細管理
- 監査ログ

## 4. 技術構成案

## 4.1 サーバー側

- 言語: `PHP 8.x`
- フレームワーク: `Laravel`
- DB: `MySQL`
- 画面: `Blade + Alpine.js` または軽量JS
- ファイル保存: `Xserver上の非公開ディレクトリ`
- バッチ: `Cron`

## 4.2 クライアント側

- 打刻端末: `C# / WPF`
- モバイル向け: `PWA`

## 4.3 認証

- 職員: メールまたは職員番号 + パスワード
- 管理者: ID + パスワード
- 管理者は `2段階認証` を推奨

## 5. なぜこの構成にするか

- 共有レンタルサーバー上では `常駐Node.js` や `ASP.NET Core常駐` 前提が重い
- `Laravel + MySQL` は共有サーバーとの相性が良い
- 管理画面とAPIを同一PHPアプリにまとめやすい
- PDF配信や申請機能は常駐プロセス不要で構築できる

## 6. 機能再設計

## 6.1 打刻

### 処理概要

1. Windows打刻アプリがカードIDを取得
2. `POST /api/attendance/punch` を呼ぶ
3. サーバーでカードIDから職員を特定
4. 当日最終打刻から `出勤/退勤` を判定
5. `attendance_events` と `attendance_daily` を更新
6. 結果を端末へ返却

### 共有サーバー前提の注意

- 打刻判定はすべてサーバー側で完結させる
- 連続打刻の抑止はクライアントとサーバー両方で持つ
- オフライン時はWindowsアプリ内のローカルキューで保持

## 6.2 休暇申請

- 職員は `有給 / 欠勤 / 特休` を申請
- 単位は `1日 / 半日`
- ステータスは `申請中 / 承認 / 却下 / 差戻し`
- 有給のみ残数チェック

## 6.3 給与明細

- 管理者がPDFをアップロード
- 職員は対象年月ごとの一覧から閲覧
- 閲覧時刻を記録
- ファイルURLを直公開せず、認可を通して配信

## 7. 業務フロー

## 7.1 打刻

1. 管理者が職員を登録
2. 管理者がカードUIDを職員へ紐付け
3. 職員がWindows端末でカード打刻
4. PHP API が打刻記録
5. 日次勤怠へ集計

## 7.2 休暇

1. 職員がWeb/PWAから申請
2. 管理者が承認
3. 承認結果を通知

## 7.3 給与明細

1. 給与担当がPDFをアップロード
2. 公開日以降に職員が閲覧
3. 閲覧履歴を保存

## 8. データベース方針

既存のPostgreSQL前提設計をベースに、`MySQL` に読み替えて実装する。

主要テーブルは以下。

- `employees`
- `employee_auth`
- `employee_cards`
- `attendance_devices`
- `attendance_events`
- `attendance_daily`
- `leave_types`
- `leave_requests`
- `leave_request_actions`
- `paid_leave_grants`
- `payroll_statements`
- `payroll_statement_views`
- `notifications`
- `audit_logs`

## 9. 画面構成

## 9.1 Windows打刻アプリ

- 待受画面
- 打刻結果画面
- 未登録カード表示
- 未送信キュー表示
- 再送ボタン

## 9.2 職員向けWeb/PWA

- ログイン
- ホーム
- 休暇申請
- 申請履歴
- 給与明細一覧
- 給与明細PDF閲覧

## 9.3 管理者向けWeb

- ダッシュボード
- 職員管理
- カード管理
- 勤怠履歴
- 当日勤怠一覧
- 休暇申請一覧
- 給与明細管理
- 監査ログ

## 10. API設計方針

共有サーバー向けには、以下のような `REST風API` をLaravel内に持つ。

- `POST /api/auth/login`
- `POST /api/attendance/punch`
- `GET /api/admin/cards`
- `POST /api/admin/cards/assign`
- `POST /api/admin/cards/revoke`
- `GET /api/admin/attendance/events`
- `GET /api/admin/attendance/daily`
- `GET /api/leave/requests`
- `POST /api/leave/requests`
- `POST /api/admin/leave/requests/{id}/approve`
- `POST /api/admin/payroll/statements`

## 11. ファイル保存方針

給与明細PDFは以下の方針とする。

- `public_html` 直下へは置かない
- アプリ管理下の非公開領域へ保存
- ダウンロード時に認証・認可を通す
- ファイル名はランダム化または内部ID化する

## 12. バッチ方針

常駐ジョブは使わず、`Cron` で以下を実行する。

- 明細公開時の通知生成
- 未処理通知の配信
- ログ整理
- バックアップ補助

## 13. セキュリティ方針

- 全通信 `HTTPS`
- API認証必須
- PDF直リンク禁止
- 監査ログ保存
- 管理画面はIP制限も検討
- パスワードはハッシュ化

## 14. メリットとデメリット

## 14.1 メリット

- Xserver共有レンタルサーバーで運用しやすい
- 月額を抑えやすい
- PHP/MySQL構成は保守要員を確保しやすい

## 14.2 デメリット

- `.NET + PostgreSQL + Next.js` より表現力は落ちる
- 常駐ジョブやリアルタイム通信は不得意
- 将来拡張時にVPS移行を検討しやすい構成になる

## 15. 推奨MVP

まずは以下に絞る。

- Windows打刻アプリ
- 打刻API
- 職員管理
- カード紐付け
- 当日勤怠一覧
- 休暇申請
- 1段階承認
- 給与明細PDF配信

## 16. 今後の進め方

実装は次の順がよい。

1. `MySQL向けDDL`
2. `Laravel API設計`
3. `管理画面モック`
4. `Windows打刻アプリの接続先をPHP APIへ変更`
5. `PWA画面実装`

## 17. 結論

Xserver共有レンタルサーバーで運用する場合、サーバー側は `PHP + MySQL` 前提に寄せる必要がある。  
一方で、`RC-S380 + Windows打刻アプリ` という現場構成はそのまま活かせるため、打刻端末をクライアント、共有サーバーを業務API基盤として分離する設計が最も現実的である。
