# 職員打刻・休暇申請・給与明細配信アプリ 基本設計書

## 1. 目的

本システムは、以下の3業務を一体で管理できるアプリケーションを提供することを目的とする。

- Windows PC常駐のカード打刻
- モバイルアプリからの休暇申請
- モバイルアプリからの給与明細PDF閲覧

対象業務は以下の通り。

- 出退勤打刻
- 有給休暇申請
- 欠勤申請
- 特別休暇申請
- 給与明細PDF配信

今回の前提条件は以下とする。

- カードリーダー: SONY RC-S380
- 接続方式: USB
- 打刻端末: Windows固定
- 給与明細: PDF配信のみ
- 休暇区分: 有給、欠勤、特休
- 承認フロー: 1段階承認

## 2. システム全体構成

### 2.1 構成要素

1. Windows打刻アプリ
2. 職員向けモバイルアプリ
3. 管理者向けWebアプリ
4. 共通バックエンドAPI
5. データベース
6. 給与明細PDF保管ストレージ

### 2.2 役割

#### Windows打刻アプリ

- RC-S380からカード情報を読み取る
- カード情報を職員に紐付けて打刻を記録する
- 通信断時はローカルに一時保存する
- 復旧後に未送信打刻を再送する

#### 職員向けモバイルアプリ

- 休暇申請を行う
- 申請履歴を確認する
- 給与明細PDFを閲覧する
- 通知を受け取る

#### 管理者向けWebアプリ

- 職員情報を管理する
- カード紐付けを管理する
- 勤怠履歴を確認する
- 休暇申請を承認する
- 給与明細PDFをアップロードする

#### 共通バックエンドAPI

- 認証認可
- 打刻データ受付
- 勤怠集計
- 休暇申請管理
- 明細配信管理
- 監査ログ記録

## 3. 想定利用者

### 3.1 職員

- カード打刻を行う
- モバイルアプリから休暇申請を行う
- 給与明細を確認する

### 3.2 承認者

- 休暇申請を承認、却下、差戻しする

### 3.3 労務担当者

- 職員情報を管理する
- 勤怠実績を確認する

### 3.4 給与担当者

- 給与明細PDFをアップロードする

### 3.5 システム管理者

- 端末、権限、設定を管理する

## 4. 業務フロー

### 4.1 打刻フロー

1. 職員がWindows打刻アプリでカードをかざす
2. RC-S380がカード情報を読み取る
3. 打刻アプリがカードIDを取得する
4. バックエンドAPIへ打刻要求を送信する
5. APIがカードIDと職員を照合する
6. APIが出勤または退勤を判定する
7. 打刻結果を保存する
8. 打刻アプリに結果を返却する
9. 打刻アプリが氏名、時刻、結果を表示する

### 4.2 休暇申請フロー

1. 職員がモバイルアプリから申請する
2. APIが入力内容を保存する
3. 承認者へ通知する
4. 承認者が管理画面で承認、却下、差戻しする
5. APIが状態を更新する
6. 職員へ通知する

### 4.3 給与明細配信フロー

1. 給与担当者が管理画面からPDFをアップロードする
2. APIがストレージへ保存する
3. 対象職員と対象年月を登録する
4. 公開日に職員へ通知する
5. 職員がモバイルアプリでPDFを閲覧する
6. APIが閲覧履歴を記録する

## 5. 画面一覧

## 5.1 Windows打刻アプリ

### A-01 待受画面

- 用途: カード読取待ち受け
- 表示項目:
  - 現在時刻
  - 読取待機メッセージ
  - ネットワーク状態
  - 未送信件数

### A-02 打刻結果画面

- 用途: 打刻結果表示
- 表示項目:
  - 職員名
  - 打刻種別
  - 打刻時刻
  - 成功またはエラーメッセージ

### A-03 エラー画面

- 用途: 未登録カード、通信断、二重打刻警告

### A-04 端末設定画面

- 用途: API接続先、端末ID、管理者保守設定

## 5.2 職員向けモバイルアプリ

### M-01 ログイン

- メールアドレスまたは職員ID
- パスワード

### M-02 ホーム

- 本日の状態
- 申請状況サマリ
- 新着明細通知

### M-03 休暇申請入力

- 休暇区分
- 対象日
- 半日区分
- 理由
- 有給残数表示

### M-04 申請履歴一覧

- 申請日
- 区分
- 状態

### M-05 申請詳細

- 申請内容
- 承認結果
- 差戻し理由または却下理由

### M-06 給与明細一覧

- 対象年月
- 公開状態
- 閲覧済み状態

### M-07 給与明細PDF表示

- PDFビューア
- ダウンロード

### M-08 通知一覧

- 申請結果通知
- 明細公開通知

## 5.3 管理者向けWebアプリ

### W-01 ログイン

### W-02 ダッシュボード

- 未承認申請件数
- 本日打刻数
- 未送信端末有無

### W-03 職員一覧

- 職員検索
- 新規登録
- 編集

### W-04 カード管理

- カード登録
- 職員紐付け
- 無効化

### W-05 打刻履歴一覧

- 日付
- 職員
- 打刻種別
- 端末
- 異常フラグ

### W-06 日次勤怠一覧

- 出勤時刻
- 退勤時刻
- 勤務時間
- 欠勤、休暇反映状況

### W-07 休暇申請一覧

- 申請者
- 区分
- 対象日
- 状態

### W-08 休暇申請詳細

- 申請内容表示
- 承認
- 却下
- 差戻し

### W-09 給与明細一覧

- 対象年月
- 対象職員
- 公開状態

### W-10 給与明細アップロード

- 職員選択
- 対象年月
- PDFアップロード
- 公開日設定

### W-11 監査ログ一覧

- 操作日時
- 操作者
- 対象
- 操作内容

## 6. 画面遷移

### 6.1 Windows打刻アプリ

```text
待受画面
  -> カード読取成功 -> 打刻結果画面 -> 自動で待受画面へ戻る
  -> カード読取失敗 -> エラー画面 -> 自動で待受画面へ戻る
  -> 管理操作 -> 端末設定画面 -> 待受画面
```

### 6.2 職員向けモバイルアプリ

```text
ログイン
  -> ホーム
ホーム
  -> 休暇申請入力
  -> 申請履歴一覧 -> 申請詳細
  -> 給与明細一覧 -> 給与明細PDF表示
  -> 通知一覧
```

### 6.3 管理者向けWebアプリ

```text
ログイン
  -> ダッシュボード
ダッシュボード
  -> 職員一覧
  -> カード管理
  -> 打刻履歴一覧
  -> 日次勤怠一覧
  -> 休暇申請一覧 -> 休暇申請詳細
  -> 給与明細一覧 -> 給与明細アップロード
  -> 監査ログ一覧
```

## 7. 機能要件

### 7.1 打刻機能

- RC-S380を利用してカード読取を行うこと
- カードIDから職員を特定できること
- 打刻時に出勤、退勤を自動判定できること
- 未登録カードをエラー表示できること
- 通信断時は端末ローカルに保持できること
- 復旧時に再送できること
- 二重打刻を検出できること

### 7.2 休暇申請機能

- 有給、欠勤、特休を申請できること
- 有給のみ残数チェックすること
- 半日申請できること
- 1段階承認できること
- 承認、却下、差戻しの状態管理ができること

### 7.3 給与明細機能

- PDFを職員単位、年月単位で登録できること
- 公開日を設定できること
- モバイルアプリで閲覧できること
- 閲覧日時を記録できること

### 7.4 管理機能

- 職員情報を登録、編集できること
- カードを登録、紐付け、無効化できること
- 打刻履歴と日次勤怠を確認できること
- 操作ログを確認できること

## 8. 非機能要件

### 8.1 可用性

- 打刻端末はアプリ常時起動を前提とする
- API障害時もローカル保存で打刻継続可能とする

### 8.2 性能

- 打刻結果はカード読取から数秒以内に画面表示する
- 通常利用で職員一覧、申請一覧は数秒以内に表示する

### 8.3 セキュリティ

- モバイル、Webは認証必須とする
- 管理者は強固なパスワードと多要素認証を推奨する
- 給与明細PDFは認可チェック付きで配信する
- 監査ログを保存する

### 8.4 監査性

- 打刻受付
- 承認操作
- 明細アップロード
- 明細閲覧
- カード紐付け変更

上記を監査ログに記録する。

## 9. 論理データ設計

## 9.1 テーブル一覧

1. employees
2. employee_auth
3. employee_cards
4. attendance_devices
5. attendance_events
6. attendance_daily
7. leave_types
8. leave_requests
9. leave_request_actions
10. paid_leave_grants
11. payroll_statements
12. payroll_statement_views
13. notifications
14. audit_logs

## 9.2 主要テーブル定義案

### employees

| カラム名 | 型 | 説明 |
| --- | --- | --- |
| id | bigint PK | 職員ID |
| employee_code | varchar | 職員番号 |
| name | varchar | 氏名 |
| kana | varchar | カナ |
| department_name | varchar | 所属 |
| employment_type | varchar | 雇用区分 |
| status | varchar | 在籍状態 |
| joined_on | date | 入社日 |
| retired_on | date nullable | 退職日 |
| created_at | timestamp | 作成日時 |
| updated_at | timestamp | 更新日時 |

### employee_auth

| カラム名 | 型 | 説明 |
| --- | --- | --- |
| employee_id | bigint PK/FK | 職員ID |
| login_id | varchar | ログインID |
| password_hash | varchar | パスワードハッシュ |
| last_login_at | timestamp nullable | 最終ログイン日時 |
| mobile_push_token | varchar nullable | Push通知トークン |

### employee_cards

| カラム名 | 型 | 説明 |
| --- | --- | --- |
| id | bigint PK | カード紐付けID |
| employee_id | bigint FK | 職員ID |
| card_uid | varchar | カード識別子 |
| is_active | boolean | 有効フラグ |
| assigned_at | timestamp | 割当日時 |
| revoked_at | timestamp nullable | 失効日時 |

### attendance_devices

| カラム名 | 型 | 説明 |
| --- | --- | --- |
| id | bigint PK | 端末ID |
| device_code | varchar | 端末識別コード |
| name | varchar | 端末名 |
| location_name | varchar | 設置場所 |
| os_user | varchar nullable | 実行ユーザー |
| last_seen_at | timestamp nullable | 最終通信日時 |
| is_active | boolean | 利用状態 |

### attendance_events

| カラム名 | 型 | 説明 |
| --- | --- | --- |
| id | bigint PK | 打刻イベントID |
| employee_id | bigint FK | 職員ID |
| device_id | bigint FK | 端末ID |
| card_uid | varchar | 読取カードID |
| occurred_at | timestamp | 打刻時刻 |
| event_type | varchar | CLOCK_IN または CLOCK_OUT |
| source_type | varchar | CARD_READER |
| receive_status | varchar | ACCEPTED, REJECTED |
| rejection_reason | varchar nullable | エラー理由 |
| offline_saved | boolean | 端末オフライン保存有無 |
| created_at | timestamp | 作成日時 |

### attendance_daily

| カラム名 | 型 | 説明 |
| --- | --- | --- |
| id | bigint PK | 日次勤怠ID |
| employee_id | bigint FK | 職員ID |
| target_date | date | 対象日 |
| clock_in_at | timestamp nullable | 出勤時刻 |
| clock_out_at | timestamp nullable | 退勤時刻 |
| work_minutes | integer nullable | 勤務分数 |
| late_flag | boolean | 遅刻フラグ |
| early_leave_flag | boolean | 早退フラグ |
| absence_flag | boolean | 欠勤フラグ |
| special_leave_flag | boolean | 特休フラグ |
| paid_leave_unit | decimal nullable | 有休日数反映値 |
| updated_at | timestamp | 更新日時 |

### leave_types

| カラム名 | 型 | 説明 |
| --- | --- | --- |
| code | varchar PK | PAID, ABSENCE, SPECIAL |
| name | varchar | 表示名 |
| requires_balance | boolean | 残数管理対象 |

### leave_requests

| カラム名 | 型 | 説明 |
| --- | --- | --- |
| id | bigint PK | 申請ID |
| employee_id | bigint FK | 申請者職員ID |
| leave_type_code | varchar FK | 休暇区分 |
| start_date | date | 開始日 |
| end_date | date | 終了日 |
| day_unit | varchar | FULL または HALF |
| half_day_type | varchar nullable | AM または PM |
| reason | text nullable | 理由 |
| status | varchar | PENDING, APPROVED, REJECTED, RETURNED |
| approved_by | bigint nullable | 承認者ID |
| approved_at | timestamp nullable | 承認日時 |
| decision_comment | text nullable | 承認コメント |
| created_at | timestamp | 作成日時 |
| updated_at | timestamp | 更新日時 |

### leave_request_actions

| カラム名 | 型 | 説明 |
| --- | --- | --- |
| id | bigint PK | 履歴ID |
| leave_request_id | bigint FK | 申請ID |
| action_type | varchar | APPLIED, APPROVED, REJECTED, RETURNED |
| action_by | bigint FK | 実行者ID |
| comment | text nullable | コメント |
| acted_at | timestamp | 実行日時 |

### paid_leave_grants

| カラム名 | 型 | 説明 |
| --- | --- | --- |
| id | bigint PK | 付与履歴ID |
| employee_id | bigint FK | 職員ID |
| granted_on | date | 付与日 |
| granted_days | decimal | 付与日数 |
| used_days | decimal | 消化日数 |
| expires_on | date nullable | 失効日 |
| created_at | timestamp | 作成日時 |

### payroll_statements

| カラム名 | 型 | 説明 |
| --- | --- | --- |
| id | bigint PK | 明細ID |
| employee_id | bigint FK | 職員ID |
| target_year_month | char(7) | 対象年月 YYYY-MM |
| file_path | varchar | 保存先 |
| original_file_name | varchar | 元ファイル名 |
| published_at | timestamp nullable | 公開日時 |
| uploaded_by | bigint FK | 登録者ID |
| created_at | timestamp | 作成日時 |

### payroll_statement_views

| カラム名 | 型 | 説明 |
| --- | --- | --- |
| id | bigint PK | 閲覧履歴ID |
| payroll_statement_id | bigint FK | 明細ID |
| employee_id | bigint FK | 閲覧者職員ID |
| viewed_at | timestamp | 閲覧日時 |

### notifications

| カラム名 | 型 | 説明 |
| --- | --- | --- |
| id | bigint PK | 通知ID |
| employee_id | bigint FK | 宛先職員ID |
| notification_type | varchar | 申請結果、明細公開など |
| title | varchar | 件名 |
| body | text | 本文 |
| is_read | boolean | 既読フラグ |
| sent_at | timestamp | 送信日時 |

### audit_logs

| カラム名 | 型 | 説明 |
| --- | --- | --- |
| id | bigint PK | 監査ログID |
| actor_type | varchar | EMPLOYEE, ADMIN, SYSTEM |
| actor_id | bigint nullable | 実行者ID |
| action | varchar | 操作種別 |
| target_type | varchar | 対象種別 |
| target_id | varchar | 対象ID |
| detail_json | json | 差分、補足情報 |
| occurred_at | timestamp | 実行日時 |

## 10. API設計方針

## 10.1 認証系

- `POST /api/auth/login`
- `POST /api/auth/logout`
- `POST /api/auth/refresh`

## 10.2 打刻系

- `POST /api/attendance/punch`
  - 入力: deviceCode, cardUid, occurredAt
  - 出力: employeeName, eventType, resultMessage

- `GET /api/attendance/daily`
  - 管理者用の日次勤怠一覧取得

- `GET /api/attendance/events`
  - 管理者用の打刻履歴取得

## 10.3 休暇申請系

- `GET /api/leave/balance`
- `GET /api/leave/requests`
- `POST /api/leave/requests`
- `GET /api/leave/requests/{id}`
- `POST /api/leave/requests/{id}/approve`
- `POST /api/leave/requests/{id}/reject`
- `POST /api/leave/requests/{id}/return`

## 10.4 給与明細系

- `GET /api/payroll/statements`
- `GET /api/payroll/statements/{id}`
- `POST /api/admin/payroll/statements`
- `POST /api/payroll/statements/{id}/viewed`

## 10.5 マスタ管理系

- `GET /api/admin/employees`
- `POST /api/admin/employees`
- `PUT /api/admin/employees/{id}`
- `GET /api/admin/cards`
- `POST /api/admin/cards/assign`
- `POST /api/admin/cards/revoke`
- `GET /api/admin/devices`

## 10.6 監査系

- `GET /api/admin/audit-logs`

## 11. 打刻判定ルール案

- 当日初回打刻は出勤とする
- 同日内で出勤済かつ退勤未登録の場合は退勤とする
- 同一職員が短時間に連続打刻した場合は二重打刻警告とする
- 日跨ぎ勤務や休憩打刻は初期MVPでは対象外とする

補足:
夜勤や複雑シフトがある場合は、将来的に勤務体系マスタと打刻補正機能を追加する。

## 12. 休暇管理ルール案

- 有給のみ残数を管理する
- 欠勤と特休は残数を持たない
- 半日申請は有給、特休で利用可能とする
- 欠勤は原則1日単位を基本とし、必要に応じて半日対応を追加検討する
- 承認後は日次勤怠へ反映する

## 13. 技術構成案

### 13.1 推奨構成

- Windows打刻アプリ: C# / .NET
- モバイルアプリ: Flutter
- 管理者Web: Next.js
- バックエンドAPI: ASP.NET Core または NestJS
- DB: PostgreSQL
- ストレージ: S3互換ストレージ

### 13.2 選定理由

- RC-S380とWindows連携はC# / .NETが安定しやすい
- モバイルは1コードベースでiOS/Android対応しやすい
- Web管理画面は一般的なSPA構成で十分
- PDF配信はDBではなくストレージ分離が適切

## 14. MVP開発範囲

### 14.1 MVPに含めるもの

- Windows打刻アプリ
- 職員マスタ管理
- カード紐付け管理
- 打刻履歴保存
- 日次勤怠表示
- 有給、欠勤、特休の申請
- 1段階承認
- 給与明細PDFアップロード、閲覧
- 監査ログ

### 14.2 MVPでは後回しにするもの

- 複数承認
- シフト管理
- 休憩打刻
- 代休管理
- 自動給与計算
- PDF以外の明細表示画面

## 15. 実装の推奨順

1. 職員、カード、認証の基盤実装
2. Windows打刻アプリと打刻API
3. 管理者向け勤怠確認画面
4. 休暇申請、承認機能
5. 給与明細PDF配信機能
6. 通知と監査ログ強化

## 16. 次に詳細化すべき項目

- RC-S380連携方式の技術検証
- 打刻時のカードUID取得仕様確認
- ログイン方式の最終決定
- 有給付与ルールの詳細
- 給与明細PDFの登録運用方法
- インフラ配置方針

