# 職員打刻・休暇申請・給与明細配信アプリ DB設計書

## 1. 方針

本設計書は、以下の業務を支えるデータベース設計を定義する。

- カード打刻
- 休暇申請と承認
- 給与明細PDF配信
- 通知
- 監査ログ

初期MVPでは PostgreSQL を前提とする。

## 2. 設計方針

### 2.1 基本方針

- 業務上の主データは論理削除を基本とし、監査性を確保する
- 打刻のようなイベントデータは更新より追加を基本とする
- 日次勤怠は打刻イベントから集計して保持する
- 有給残数は履歴ベースで管理する
- 給与明細PDF本体はDBに保存せず、保存先パスのみ保持する

### 2.2 命名方針

- テーブル名は複数形の snake_case
- 主キーは `id`
- 外部キーは `{table単数形}_id` を基本とする
- 日時は `timestamp with time zone` を想定する

## 3. ERの考え方

### 3.1 主な関連

- `employees` 1 : N `employee_cards`
- `employees` 1 : N `attendance_events`
- `attendance_devices` 1 : N `attendance_events`
- `employees` 1 : N `attendance_daily`
- `employees` 1 : N `leave_requests`
- `leave_requests` 1 : N `leave_request_actions`
- `employees` 1 : N `paid_leave_grants`
- `employees` 1 : N `payroll_statements`
- `payroll_statements` 1 : N `payroll_statement_views`
- `employees` 1 : N `notifications`

## 4. テーブル一覧

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

## 5. テーブル詳細

## 5.1 employees

### 用途

職員の基本情報を保持する。

### カラム

| カラム名 | 型 | NULL | 制約 | 説明 |
| --- | --- | --- | --- | --- |
| id | bigint | NO | PK | 職員ID |
| employee_code | varchar(30) | NO | UNIQUE | 職員番号 |
| name | varchar(100) | NO |  | 氏名 |
| kana | varchar(100) | YES |  | カナ |
| department_name | varchar(100) | YES |  | 所属名 |
| employment_type | varchar(30) | NO |  | 正社員、パートなど |
| status | varchar(20) | NO |  | ACTIVE, SUSPENDED, RETIRED |
| joined_on | date | NO |  | 入社日 |
| retired_on | date | YES |  | 退職日 |
| created_at | timestamp | NO |  | 作成日時 |
| updated_at | timestamp | NO |  | 更新日時 |

### インデックス

- unique index on `employee_code`
- index on `department_name`
- index on `status`

## 5.2 employee_auth

### 用途

職員向けログイン認証情報を保持する。

### カラム

| カラム名 | 型 | NULL | 制約 | 説明 |
| --- | --- | --- | --- | --- |
| employee_id | bigint | NO | PK, FK -> employees.id | 職員ID |
| login_id | varchar(100) | NO | UNIQUE | ログインID |
| password_hash | varchar(255) | NO |  | パスワードハッシュ |
| password_updated_at | timestamp | YES |  | パスワード更新日時 |
| last_login_at | timestamp | YES |  | 最終ログイン日時 |
| mobile_push_token | varchar(255) | YES |  | Push通知トークン |
| created_at | timestamp | NO |  | 作成日時 |
| updated_at | timestamp | NO |  | 更新日時 |

### インデックス

- unique index on `login_id`

## 5.3 employee_cards

### 用途

カードUIDと職員の紐付けを管理する。

### カラム

| カラム名 | 型 | NULL | 制約 | 説明 |
| --- | --- | --- | --- | --- |
| id | bigint | NO | PK | カード紐付けID |
| employee_id | bigint | NO | FK -> employees.id | 職員ID |
| card_uid | varchar(64) | NO |  | カード識別子 |
| is_active | boolean | NO |  | 有効フラグ |
| assigned_at | timestamp | NO |  | 割当日時 |
| revoked_at | timestamp | YES |  | 失効日時 |
| created_at | timestamp | NO |  | 作成日時 |
| updated_at | timestamp | NO |  | 更新日時 |

### 制約・補足

- 同一時点で有効な `card_uid` は1人の職員にのみ紐付く
- 再発行や履歴管理のため物理削除はしない

### インデックス

- unique partial index on `card_uid` where `is_active = true`
- index on `employee_id`

## 5.4 attendance_devices

### 用途

Windows打刻端末を管理する。

### カラム

| カラム名 | 型 | NULL | 制約 | 説明 |
| --- | --- | --- | --- | --- |
| id | bigint | NO | PK | 端末ID |
| device_code | varchar(50) | NO | UNIQUE | 端末識別コード |
| name | varchar(100) | NO |  | 端末名 |
| location_name | varchar(100) | YES |  | 設置場所 |
| os_user | varchar(100) | YES |  | 実行ユーザー名 |
| app_version | varchar(30) | YES |  | 打刻アプリバージョン |
| last_seen_at | timestamp | YES |  | 最終通信日時 |
| is_active | boolean | NO |  | 利用状態 |
| created_at | timestamp | NO |  | 作成日時 |
| updated_at | timestamp | NO |  | 更新日時 |

### インデックス

- unique index on `device_code`
- index on `is_active`

## 5.5 attendance_events

### 用途

カード打刻の生イベントを保存する。

### カラム

| カラム名 | 型 | NULL | 制約 | 説明 |
| --- | --- | --- | --- | --- |
| id | bigint | NO | PK | 打刻イベントID |
| employee_id | bigint | YES | FK -> employees.id | 職員ID。未登録カード時はNULL可 |
| device_id | bigint | NO | FK -> attendance_devices.id | 端末ID |
| card_uid | varchar(64) | NO |  | 読取カード識別子 |
| occurred_at | timestamp | NO |  | 打刻時刻 |
| event_type | varchar(20) | YES |  | CLOCK_IN, CLOCK_OUT |
| source_type | varchar(20) | NO |  | CARD_READER |
| receive_status | varchar(20) | NO |  | ACCEPTED, REJECTED |
| rejection_reason | varchar(100) | YES |  | エラー理由 |
| offline_saved | boolean | NO |  | オフライン保存有無 |
| dedupe_key | varchar(100) | YES | UNIQUE | 冪等制御キー |
| created_at | timestamp | NO |  | 作成日時 |

### 制約・補足

- 未登録カード打刻も監査のため保存する
- `dedupe_key` は端末側で生成し、二重送信を防止する

### インデックス

- index on `employee_id, occurred_at`
- index on `device_id, occurred_at`
- index on `receive_status`
- index on `card_uid`

## 5.6 attendance_daily

### 用途

1日単位の勤怠集計結果を保持する。

### カラム

| カラム名 | 型 | NULL | 制約 | 説明 |
| --- | --- | --- | --- | --- |
| id | bigint | NO | PK | 日次勤怠ID |
| employee_id | bigint | NO | FK -> employees.id | 職員ID |
| target_date | date | NO |  | 対象日 |
| clock_in_at | timestamp | YES |  | 出勤時刻 |
| clock_out_at | timestamp | YES |  | 退勤時刻 |
| work_minutes | integer | YES |  | 勤務分数 |
| late_flag | boolean | NO |  | 遅刻フラグ |
| early_leave_flag | boolean | NO |  | 早退フラグ |
| absence_flag | boolean | NO |  | 欠勤フラグ |
| special_leave_flag | boolean | NO |  | 特休フラグ |
| paid_leave_unit | numeric(4,2) | YES |  | 有休反映値 0.5, 1.0 など |
| remark | varchar(255) | YES |  | 補足 |
| updated_at | timestamp | NO |  | 更新日時 |

### 制約・補足

- 同一職員、同一日付は1件
- 打刻再送や休暇承認に応じて再計算される

### インデックス

- unique index on `employee_id, target_date`
- index on `target_date`

## 5.7 leave_types

### 用途

休暇区分マスタを保持する。

### カラム

| カラム名 | 型 | NULL | 制約 | 説明 |
| --- | --- | --- | --- | --- |
| code | varchar(20) | NO | PK | PAID, ABSENCE, SPECIAL |
| name | varchar(50) | NO | UNIQUE | 表示名 |
| requires_balance | boolean | NO |  | 残数管理対象か |
| allows_half_day | boolean | NO |  | 半日利用可否 |
| sort_order | integer | NO |  | 表示順 |

## 5.8 leave_requests

### 用途

職員からの休暇申請本体を保持する。

### カラム

| カラム名 | 型 | NULL | 制約 | 説明 |
| --- | --- | --- | --- | --- |
| id | bigint | NO | PK | 申請ID |
| employee_id | bigint | NO | FK -> employees.id | 申請者職員ID |
| leave_type_code | varchar(20) | NO | FK -> leave_types.code | 休暇区分 |
| start_date | date | NO |  | 開始日 |
| end_date | date | NO |  | 終了日 |
| day_unit | varchar(10) | NO |  | FULL, HALF |
| half_day_type | varchar(10) | YES |  | AM, PM |
| quantity_days | numeric(4,2) | NO |  | 申請日数 |
| reason | text | YES |  | 理由 |
| status | varchar(20) | NO |  | PENDING, APPROVED, REJECTED, RETURNED |
| approved_by | bigint | YES | FK -> employees.id | 承認者ID |
| approved_at | timestamp | YES |  | 承認日時 |
| decision_comment | text | YES |  | 判定コメント |
| created_at | timestamp | NO |  | 作成日時 |
| updated_at | timestamp | NO |  | 更新日時 |

### 制約・補足

- `start_date <= end_date`
- `day_unit = HALF` の場合は `half_day_type` 必須
- 初期MVPでは 1申請 = 連続した同一区分の休暇とする

### インデックス

- index on `employee_id, created_at`
- index on `status, start_date`
- index on `leave_type_code`

## 5.9 leave_request_actions

### 用途

休暇申請に対する操作履歴を保持する。

### カラム

| カラム名 | 型 | NULL | 制約 | 説明 |
| --- | --- | --- | --- | --- |
| id | bigint | NO | PK | 履歴ID |
| leave_request_id | bigint | NO | FK -> leave_requests.id | 申請ID |
| action_type | varchar(20) | NO |  | APPLIED, APPROVED, REJECTED, RETURNED |
| action_by | bigint | NO | FK -> employees.id | 実行者ID |
| comment | text | YES |  | コメント |
| acted_at | timestamp | NO |  | 実行日時 |

### インデックス

- index on `leave_request_id, acted_at`

## 5.10 paid_leave_grants

### 用途

有給付与と消化の元になる履歴を保持する。

### カラム

| カラム名 | 型 | NULL | 制約 | 説明 |
| --- | --- | --- | --- | --- |
| id | bigint | NO | PK | 付与履歴ID |
| employee_id | bigint | NO | FK -> employees.id | 職員ID |
| granted_on | date | NO |  | 付与日 |
| granted_days | numeric(4,2) | NO |  | 付与日数 |
| used_days | numeric(4,2) | NO |  | 消化日数 |
| expires_on | date | YES |  | 失効日 |
| note | varchar(255) | YES |  | 備考 |
| created_at | timestamp | NO |  | 作成日時 |
| updated_at | timestamp | NO |  | 更新日時 |

### 制約・補足

- 残数は `granted_days - used_days` で算出する
- 将来は時間休や付与ルール自動化に拡張可能

### インデックス

- index on `employee_id, granted_on`
- index on `expires_on`

## 5.11 payroll_statements

### 用途

給与明細PDFのメタ情報を保持する。

### カラム

| カラム名 | 型 | NULL | 制約 | 説明 |
| --- | --- | --- | --- | --- |
| id | bigint | NO | PK | 明細ID |
| employee_id | bigint | NO | FK -> employees.id | 対象職員ID |
| target_year_month | char(7) | NO |  | YYYY-MM |
| file_path | varchar(255) | NO |  | ストレージ保存先 |
| original_file_name | varchar(255) | NO |  | 元ファイル名 |
| file_size_bytes | bigint | YES |  | ファイルサイズ |
| content_type | varchar(100) | YES |  | MIME種別 |
| published_at | timestamp | YES |  | 公開日時 |
| uploaded_by | bigint | NO | FK -> employees.id | 登録者ID |
| created_at | timestamp | NO |  | 作成日時 |
| updated_at | timestamp | NO |  | 更新日時 |

### 制約・補足

- 同一職員、同一対象年月は1件

### インデックス

- unique index on `employee_id, target_year_month`
- index on `published_at`

## 5.12 payroll_statement_views

### 用途

給与明細閲覧履歴を保持する。

### カラム

| カラム名 | 型 | NULL | 制約 | 説明 |
| --- | --- | --- | --- | --- |
| id | bigint | NO | PK | 閲覧履歴ID |
| payroll_statement_id | bigint | NO | FK -> payroll_statements.id | 明細ID |
| employee_id | bigint | NO | FK -> employees.id | 閲覧者職員ID |
| viewed_at | timestamp | NO |  | 閲覧日時 |
| ip_address | varchar(45) | YES |  | アクセス元IP |
| user_agent | varchar(255) | YES |  | 端末情報 |

### インデックス

- index on `payroll_statement_id, viewed_at`
- index on `employee_id, viewed_at`

## 5.13 notifications

### 用途

職員への通知を保持する。

### カラム

| カラム名 | 型 | NULL | 制約 | 説明 |
| --- | --- | --- | --- | --- |
| id | bigint | NO | PK | 通知ID |
| employee_id | bigint | NO | FK -> employees.id | 宛先職員ID |
| notification_type | varchar(30) | NO |  | LEAVE_RESULT, PAYROLL_PUBLISHED など |
| title | varchar(100) | NO |  | 件名 |
| body | text | NO |  | 本文 |
| related_type | varchar(30) | YES |  | 関連データ種別 |
| related_id | bigint | YES |  | 関連データID |
| is_read | boolean | NO |  | 既読フラグ |
| sent_at | timestamp | NO |  | 送信日時 |
| read_at | timestamp | YES |  | 既読日時 |

### インデックス

- index on `employee_id, is_read, sent_at`

## 5.14 audit_logs

### 用途

重要操作の監査証跡を保持する。

### カラム

| カラム名 | 型 | NULL | 制約 | 説明 |
| --- | --- | --- | --- | --- |
| id | bigint | NO | PK | 監査ログID |
| actor_type | varchar(20) | NO |  | EMPLOYEE, ADMIN, SYSTEM, DEVICE |
| actor_id | bigint | YES |  | 実行者ID |
| action | varchar(50) | NO |  | 操作種別 |
| target_type | varchar(50) | NO |  | 対象種別 |
| target_id | varchar(100) | YES |  | 対象ID |
| detail_json | jsonb | YES |  | 補足情報 |
| occurred_at | timestamp | NO |  | 実行日時 |
| ip_address | varchar(45) | YES |  | アクセス元IP |

### インデックス

- index on `occurred_at`
- index on `actor_type, actor_id`
- index on `target_type, target_id`
- gin index on `detail_json`

## 6. 初期マスタデータ

### leave_types

| code | name | requires_balance | allows_half_day | sort_order |
| --- | --- | --- | --- | --- |
| PAID | 有給休暇 | true | true | 1 |
| ABSENCE | 欠勤 | false | false | 2 |
| SPECIAL | 特別休暇 | false | true | 3 |

## 7. 業務ルールとDB反映方針

## 7.1 打刻

- 打刻受付時は必ず `attendance_events` に保存する
- 受理したイベントに基づき `attendance_daily` を再計算する
- 未登録カードでも `attendance_events.receive_status = REJECTED` として保存する

## 7.2 休暇

- 申請作成時は `leave_requests` と `leave_request_actions` を同時登録する
- 承認時は `leave_requests.status` を更新し、`leave_request_actions` に履歴追加する
- 有給承認時は `paid_leave_grants.used_days` を更新する
- 承認済み休暇は `attendance_daily` に反映する

## 7.3 給与明細

- PDFの実体はストレージ保存とし、DBにはメタ情報のみ保存する
- 閲覧時は `payroll_statement_views` に記録する

## 8. 今後の拡張ポイント

- 勤務体系マスタ
- 休憩打刻
- 夜勤、日跨ぎ勤務
- 部署マスタ
- 承認者マスタ
- 管理者アカウントを職員テーブルと分離する構成
- CSV一括取込テーブル

