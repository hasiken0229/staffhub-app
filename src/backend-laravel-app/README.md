# 勤怠管理 Backend

`Xserver共有レンタルサーバー` を想定した `Laravel 11 + MySQL` のバックエンド実装です。

## 現在入っているもの

- `routes/api.php`
  - 打刻
  - 管理者のカード登録
  - 勤怠一覧
  - 休暇、給与明細、通知の API 雛形
- `app/Services/AttendanceService.php`
  - 打刻受付
  - 端末ハートビート
  - 打刻履歴一覧
  - 日次勤怠一覧
- `app/Services/CardAssignmentService.php`
  - カード一覧
  - カード登録
  - カード失効
- `database/migrations`
  - 共有サーバー向け MySQL スキーマ

## ローカル確認

1. `php.ini` を指定して Artisan を実行します
2. `.env` の MySQL 接続先を環境に合わせて更新します
3. `php artisan migrate` を実行します

例:

```powershell
php -c "C:\Users\ikega\OneDrive\デスクトップ\職員打刻有給申請給与明細アプリ\php.ini" artisan route:list
php -c "C:\Users\ikega\OneDrive\デスクトップ\職員打刻有給申請給与明細アプリ\php.ini" artisan migrate
```

## 注意点

- モバイル用ログインはまだダミー応答です
- `leave`, `payroll`, `notifications` はまだ雛形段階です
- 管理 API はローカル環境だけ `AdminMiddleware` で簡易通過できます
- 本番では `Sanctum` などの認証実装を追加してください

## 打刻端末の初期登録

打刻APIは `attendance_devices` に登録された端末だけ受け付けます。
初回は Seeder を使うと準備しやすいです。

管理者アカウントや端末は、環境変数を準備した上で明示的に投入します。

通常:

```bash
STAFFHUB_ADMIN_EMAIL="admin@example.com" \
STAFFHUB_ADMIN_PASSWORD="change-me" \
STAFFHUB_DEVICE_CODE="PC-ENTRANCE-01" \
STAFFHUB_DEVICE_SECRET="change-me-device-secret" \
/usr/bin/php8.3 artisan db:seed --class=Database\\Seeders\\StaffHubBootstrapSeeder
```

サンプル職員とカードも同時投入する場合:

```bash
STAFFHUB_SEED_SAMPLE=true /usr/bin/php8.3 artisan db:seed --class=Database\\Seeders\\StaffHubBootstrapSeeder
```

サンプル投入を有効にした場合の既定値:

- サンプル職員コード: `E0001`
- サンプルカードUID: `012E4CE15C908F48`

## API認証

Bearer トークン認証を実装しています。

- 管理者ログイン: `users` テーブル
- 職員ログイン: `employees + employee_auth` テーブル

主な API:

- `POST /api/auth/login`
- `POST /api/auth/refresh`
- `GET /api/auth/me`
- `POST /api/auth/logout`

ログイン例:

```json
{
  "loginId": "staff001",
  "password": "Staff1234!",
  "audience": "EMPLOYEE"
}
```

管理者認証情報はリポジトリへ固定値を置かず、環境変数を指定した明示的な seed でのみ作成します。

既存の管理者ログインID/パスワードを切り替える場合は、CLI から次を実行します。

```bash
/usr/bin/php8.3 scripts/update_admin_credentials.php hasiken0229@gmai.com hasiken0229
```

必要に応じて第3引数に表示名も指定できます。

## 休暇申請API

以下を DB 実装済みです。

- `GET /api/leave/balance`
- `GET /api/leave/requests`
- `POST /api/leave/requests`
- `GET /api/leave/requests/{id}`
- `GET /api/admin/leave/requests`
- `POST /api/admin/leave/requests/{id}/approve`
- `POST /api/admin/leave/requests/{id}/reject`
- `POST /api/admin/leave/requests/{id}/return`

補足:

- 有給は残数不足チェックを行います
- 半日申請は `AM` / `PM` を必須にしています
- 管理者承認時の `approved_by` は、管理者が `users` テーブル利用のため `STAFFHUB_APPROVER_EMPLOYEE_ID` があればそれを優先し、未設定時は先頭の有効職員IDを利用します

## 給与明細API

以下を DB 実装済みです。

- `GET /api/payroll/statements`
- `GET /api/payroll/statements/{id}`
- `POST /api/payroll/statements/{id}/viewed`
- `GET /api/admin/payroll/statements`
- `POST /api/admin/payroll/statements`

保存先:

- `storage/app/private/payroll/...`

注意:

- アップロードは PDF のみ
- 上限は 10MB
- 給与明細詳細APIは今は `downloadUrl` を返す形で、将来のダウンロード専用エンドポイント追加を見越した実装です

## 次の実装候補

1. `Laravel Sanctum` を導入して職員/管理者認証を本実装する
2. `LeaveController` と `PayrollController` を DB 実装へ置き換える
3. Windows打刻アプリの送信先を Laravel API に切り替える
