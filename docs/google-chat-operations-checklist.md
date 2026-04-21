# Google Chat 通知 運用前チェックリスト

更新日: 2026-04-21

## 位置づけ

Google Chat 通知のコード実装とイベント接続は完了済みです。この文書は、本番運用前に設定・宛先・ログを確認するための手順です。

対象イベント:

- 休暇申請作成: 管理者スペースへ通知
- 休暇申請の承認/却下/差戻し: 職員DMへ通知
- お知らせ作成: 全体スペースまたは職員DMへ通知
- 給与/賞与明細公開: 職員DMへ通知
- 短時間連続打刻アラート: 管理者スペースへ通知

## 1. 本番 `.env` の確認

`src/backend-laravel-app/.env.example` と同じキーが本番 `.env` に設定されていることを確認します。

```dotenv
STAFFHUB_GOOGLE_CHAT_ENABLED=true
STAFFHUB_GOOGLE_CHAT_CREDENTIALS_PATH=/absolute/path/to/google-chat-service-account.json
STAFFHUB_GOOGLE_CHAT_BOT_SCOPE="https://www.googleapis.com/auth/chat.bot"
STAFFHUB_GOOGLE_CHAT_ADMIN_SPACE_ID=spaces/XXXXXX
STAFFHUB_GOOGLE_CHAT_ALL_STAFF_SPACE_ID=spaces/YYYYYY
STAFFHUB_GOOGLE_CHAT_MESSAGE_TIMEOUT_SECONDS=10
```

確認ポイント:

- `STAFFHUB_GOOGLE_CHAT_ENABLED` が `true` であること
- credentials path は本番サーバー上の絶対パスにすること
- 管理者スペースIDと全体スペースIDは `spaces/` 付き、または数値/IDのみでも可
- timeout は通常 `10` 秒でよい

## 2. サービスアカウント JSON の確認

credentials JSON は、Web公開ディレクトリ外に置きます。Laravel実行ユーザーが読み取りでき、ブラウザから直接アクセスできない場所にします。

確認コマンド例:

```bash
php -r "var_dump(is_readable('/absolute/path/to/google-chat-service-account.json'));"
```

期待値:

```text
bool(true)
```

NGの場合:

- pathの誤り
- ファイル権限不足
- JSONを配置したサーバーとLaravel実行サーバーが違う
- Web公開領域に置いてしまっている

## 3. Laravel 設定キャッシュ反映

本番 `.env` を変更した後は、Laravelの設定キャッシュを更新します。

```bash
php artisan config:clear
php artisan config:cache
```

確認コマンド例:

```bash
php artisan tinker
```

```php
config('staffhub.google_chat.enabled');
config('staffhub.google_chat.credentials_path');
config('staffhub.google_chat.admin_space_id');
config('staffhub.google_chat.all_staff_space_id');
```

期待値:

- `enabled` が `true`
- credentials path が本番JSONの絶対パス
- 管理者スペースIDと全体スペースIDが設定済み

## 4. 職員 Google Chat ID の確認

職員DMを送るには、`employees.google_chat_user_id` が必要です。値は `users/1234567890` 形式が正規形ですが、数値のみで登録してもアプリ側で `users/` を補完します。

確認SQL例:

```sql
select
  employee_code,
  name,
  google_chat_user_id
from employees
where status = 'ACTIVE'
order by employee_code;
```

運用判断:

- 給与明細公開や休暇判定の対象職員は `google_chat_user_id` が必須
- 未設定の職員は送信スキップされ、ログに `missing_google_chat_user_id` が出る
- 一斉導入前は、テスト対象職員を1名選んで先に登録する

## 5. 送信ログの見方

Laravelログで以下の文言を確認します。

成功:

```text
notification chat delivery completed
```

送信できなかったが理由が明確:

```text
notification chat delivery skipped
notification chat delivery incomplete
```

Google Chat API 側の例外:

```text
google chat notification failed
```

主な `reason`:

| reason | 意味 | 対応 |
|---|---|---|
| `google_chat_disabled` | Chat通知が無効 | `.env` と config cache を確認 |
| `missing_google_chat_user_id` | 職員のChat ID未登録 | 職員マスタに `google_chat_user_id` を登録 |
| `admin_space_not_configured` | 管理者スペースID未設定 | `STAFFHUB_GOOGLE_CHAT_ADMIN_SPACE_ID` を設定 |
| `all_staff_space_not_configured` | 全体スペースID未設定 | `STAFFHUB_GOOGLE_CHAT_ALL_STAFF_SPACE_ID` を設定 |
| `direct_message_space_not_found` | DMスペースを取得できない | Chatアプリ権限、ユーザーID、DM可能状態を確認 |

## 6. 4系統の疎通確認

本番運用前に、以下を1件ずつ実施します。テスト実施後は、画面表示とLaravelログの両方を確認します。

| 系統 | 操作 | 期待される通知先 | 成功ログ |
|---|---|---|---|
| 休暇申請作成 | 職員ポータルから休暇申請 | 管理者スペース | `LEAVE_REQUEST_CREATED` |
| 休暇判定 | 管理画面で承認/却下/差戻し | 対象職員DM | `LEAVE_DECISION` |
| お知らせ | 全体お知らせを作成 | 全体スペース | `NOTICE_ALL_STAFF` |
| 給与明細公開 | テスト職員へ給与/賞与明細を公開 | 対象職員DM | `PAYROLL_PUBLISHED` |

補足:

- 個別お知らせも確認する場合は、対象職員を指定して作成し、`NOTICE_TARGETED` を確認します。
- 短時間連続打刻アラートは自然発生に依存するため、運用前必須チェックからは外してよいです。

## 7. NG時の切り分け順

1. `.env` の `STAFFHUB_GOOGLE_CHAT_ENABLED` が `true` か確認
2. `php artisan config:cache` 後の `config('staffhub.google_chat.*')` が正しいか確認
3. credentials JSON が読み取り可能か確認
4. Chat API と Chat アプリのサービスアカウント設定を確認
5. スペースIDが正しいか確認
6. 職員DMの場合は `google_chat_user_id` を確認
7. Laravelログの `reason` と `google chat notification failed` の response status/body を確認

## 8. 運用上の注意

- credentials JSON はGit管理しない
- credentials JSON はWeb公開ディレクトリに置かない
- `NotificationMailService` は現状、Chat通知 façade として使われている。名称変更は影響範囲が広いため、今は行わない
- 現在の送信は同期HTTP送信。小規模運用ではこのままでよいが、通知量が増える場合はキュー化を検討する
- 本番でテスト通知を行う場合は、対象職員・対象スペース・実施時刻を事前にメモして、通常通知と混同しない
