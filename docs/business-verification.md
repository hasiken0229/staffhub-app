# 業務処理の結合テストと本番検証

更新日: 2026-09-25

申請・承認・月締め・給与保存・通知・PDF保存を、それぞれ別の結果として記録する。画面の再取得失敗や通知失敗を理由に、保存済みの給与や申請を再登録しない。

## 専用環境の起動

フロントエンドは `src/admin-web/next-app`、バックエンドは `src/backend-laravel-app`。Node.js、PHP、Composer依存関係、Playwright Chromiumが必要。Windowsの既定PHPは `C:/php/php.exe`。別の実行ファイルは `STAFFHUB_PHP` で指定する。

```powershell
# src/admin-web/next-app で実行。SQLiteによる業務フロー確認
npm run test:business

# 本番と同じDBエンジンで確認。資格情報はリポジトリ・OneDriveの外へ置く
npm run test:business -- --mysql-credentials C:/private/staffhub-test-mysql.json
```

MySQL資格情報ファイルの形式:

```json
{"host":"127.0.0.1","port":13317,"user":"staffhub_verify","password":"専用環境のパスワード"}
```

このアカウントの権限は `staffhub_verification_` で始まる検証用DBに限定する。既存DBを流用せず、毎回空のDBを作成する。2026-09-25の検証は本番MySQL 5.7.16に対して専用MySQL 5.7.44を使用した。SQLiteでの成功だけでは月締めの競合試験を合格にしない。

ランナーは次を実施する。

- `%LOCALAPPDATA%/StaffHub/Verification/e2e-*` にDB・保存ファイル・ログ・画面・ダウンロードを保存する。
- 管理者・職員のテスト用ログイン、給与・賞与CSV、5日の有給残数を作成する。
- APIを `127.0.0.1:8130`、画面を `127.0.0.1:3130` で起動する。使用中のサーバーを再利用しない。
- `testing`、localhost、専用DB名、専用保存領域のマーカー、通知無効を起動前に検査する。本番URL、外部DB、OneDrive保存、実通知設定を拒否する。
- 実APIで申請・承認・台帳・取消、給与・賞与のプレビューと確定、職員/HARMOS取込と再実行、本人表示、PDF保存、5画面幅、通信切断を確認する。
- MySQL指定時は別プロセス同士で「締め先行」「更新先行」を競合させる。

HTTPの外部送信は結合環境で拒否する。Google Chatの成功・失敗・再試行はLaravelテストの模擬応答で確認する。本番への実通知テストとは別扱いにする。`test-environment.json` は専用テスト接続だけを含むが、パスワードがあるため共有しない。

## 通常の検証コマンド

```powershell
# src/backend-laravel-app
C:/php/php.exe -d extension=fileinfo -d extension=pdo_mysql -d extension=curl -d extension=gd vendor/phpunit/phpunit/phpunit

# src/admin-web/next-app
npm test
npx tsc --noEmit
npm run test:e2e
npm run build

# リポジトリのルート
dotnet test src/windows-punch/StaffHub.PunchApp.Tests
```

PHP拡張を `-d` で渡す場合はPHPUnitを直接起動する。`artisan test` の子プロセスへこの設定が引き継がれない環境がある。フロントエンドの通常E2Eは通信を模擬する回帰試験であり、実APIを使う `test:business` と区別する。

`BusinessWorkflowSafetyTest` では、PDF差し替えのロールバック、保存失敗、CSV部分成功と再実行、プレビュー照合、一括処理の個別結果、締め後の更新拒否、将来公開、通知の失敗と再試行を確認する。通知失敗後も給与を1件に保ち、再試行のrequestIdが同じで、成功後の再実行が送信を増やさないことを確認する。

## 実装上の整合性

月単位ロックは既存の `attendance_monthly_closes` 行を使用する。複数月は昇順で取得し、申請・承認・打刻・修正・HARMOS勤怠取込と月締めで共用する。月締めの最終チェックと更新は同じトランザクション内で行う。

給与CSVは従来の部分成功を維持し、1人分の明細・内訳・バッチ項目を一緒に確定する。新しいPDFが確定するまで古いPDFを残す。2026-10-28のローカル修正版では保存内容のバイト数・SHA-256を確認し、DBロールバック時は今回作成した未参照ファイルだけを回収する。接続喪失や削除失敗時は保全して確認を要するため、DB参照を調べずに保存ファイルを一括削除しない。本番・配布物への反映は未実施。

HARMOSの取込本体と成功履歴も同じtransactionで確定し、履歴保存失敗なら本体も戻す。通常の行単位SKIP・部分成功の仕様は維持する。非公開ファイル・設定を含めた運用準備と合成復元訓練は [P2運用手順](p2-operations-20261028.md) を参照する。

プレビューの照合値はファイル内容と設定から生成する。新画面は確定時にも送信し、変更があれば再プレビューを要求する。既存クライアントとの互換性のため、照合値の追加は任意のAPIパラメーターとした。応答を失った確定操作は結果不明として履歴へ案内する。

給与通知は既存の `jobs` テーブルの専用キュー `staffhub-notifications` を使う。公開予定日時以降に、明細が削除・差し替え・再予約されていないか再確認する。明細ID・保存版・公開日時から通知イベントを固定し、Google Chatには同じUUID requestIdを渡す。[Google ChatのrequestId仕様](https://developers.google.com/workspace/chat/api/reference/rest/v1/spaces.messages/create)を参照。

## 本番反映と復元

1. ローカルの既存差分を記録し、公開ファイルとの差をハッシュで照合する。関係しない既存差分を混ぜない。
2. Web公開領域の外へコード、静的ファイル、DB、給与PDFなどの保存ファイル、環境設定を保存する。秘密情報と給与資料はリポジトリ・OneDriveの外へ保存する。
3. `scripts/verification/backup.php --app=<Laravelルート> --output=<非公開の新規保存先>` はCLI専用のDBバックアップ。既存バックアップは上書きしない。SQLには復元先テーブルを置換する命令が含まれるため、復元試験は必ず新しい専用DBに行う。
4. 復元後にテーブル件数、対象職員・対象月、保存ファイルのハッシュ、修正コードでの読み取りを照合する。
5. 変更ファイルだけを段階的に反映する。反映直前のハッシュが確認時と異なれば停止する。バックエンド差し替え時は短時間メンテナンスにし、失敗時は今回のファイルだけを戻す。今回はDB形式変更・マイグレーションなし。
6. 静的ファイルの配信ハッシュ、`admin/release.json`、HTML、JS/CSSを確認し、ログイン済み画面を明示的に再読込する。

2026-09-25のバックアップ・復元結果・変更一覧・検証記録は、実行したPCの `%LOCALAPPDATA%/StaffHub/Verification/20260925-business` に保存した。Xserver側は `/home/deploy/staffhub-verification/20260925-business`。`rollback/backend` と `rollback/static` は初回反映直前、`pdf-layout-release/rollback/backend` はPDF表示の追加修正直前のコピー。完全に元へ戻す場合は初回のハッシュを基準にする。業務を進めた後のDBを、表示修正の巻き戻しだけのために古いバックアップへ戻さない。

## 通知の定期実行

Xserverでは `/usr/bin/php8.3` を明示する。2026-09-25は既存cronを維持し、次の専用ワーカーのみを1分間隔で追加した。

```text
/usr/bin/flock -n /home/deploy/staffhub-verification/notifications.lock /usr/bin/php8.3 /home/deploy/attendance.example.org/staffhub-app/artisan queue:work database --queue=staffhub-notifications --stop-when-empty --max-time=50 --sleep=1 --tries=5 --timeout=40
```

標準出力・エラーは非公開の `notifications-worker.log` へ追記する。公開日時ちょうどの秒単位送信は保証せず、公開後の次の定期実行以降に送信する。既存のお知らせから過去ジョブを作り直さない。給与保存後の表示は「予定」「通知待ち」「送信済み」「未完了」を分ける。

未完了時は監査ログの明細ID・イベントID・理由を確認し、宛先または接続を直す。再試行は該当する失敗ジョブだけを指定する。`queue:retry all` や過去通知の一括再送は行わない。Google Chat APIの送信成功ログと、宛先側の受信画面の確認は別項目にする。

## 本番検証票

実行前に次を非公開の検証記録へ記入する。指定されていない業務は「対象待ち」にする。

|項目|記録内容|
|---|---|
|対象|職員番号、申請ID、対象年月、処理する日付|
|入力|CSVの種類・保存先・SHA-256、対象行・職員、給与の公開日時|
|通知|宛先職員/スペース、予定する通知、送信結果と受信確認|
|変更前|状態、残数、台帳、勤怠、給与ID、ファイルハッシュ|
|期待値|作成/承認/取消/締め/公開/取込後の値|
|実行結果|個別の成功・失敗・結果不明、処理ID、監査履歴|
|再取得|管理者と本人の画面、保存データ、残数と台帳の一致|
|判定|合格／不具合／対象待ち／実機未実施、未確認の理由|

月締めは1人だけの処理ではなく対象月全体の処理。指定職員の照合が済んでも、対象月全体の未処理項目があれば締めない。取消・差戻し・残数不足・通信断・保存失敗・再実行は専用環境で試す。

## PDF保存と内容照合

通常画面は「ダウンロードを開始」と表示する。非空、PDF/ZIPの形式、HTML/JSON混入を確認してからブラウザへ渡す。WebアプリからOS上の保存完了を確認できない場合は、完了と表示しない。

結合テストはPlaywrightのdownloadイベントから `saveAs` し、保存結果・ファイルサイズ・SHA-256を記録する。PDFを厳密に読み取り、全ページを画像化し、職員・月・金額・勤怠を確認する。

```powershell
python src/backend-laravel-app/scripts/verification/verify-pdfs.py C:/private/pdf-manifest.json --output C:/private/pdf-check
```

manifestは配列で、各要素に `path`、`expectedText`、任意の `expectedSha256` を持つ。期待文字が指定されていないファイルは合格にしない。`pypdf` と `pdftoppm` を用意し、`results.json` とページ画像を確認する。本番サーバーからコピーしたPDFの内容確認は、利用者のブラウザによる保存完了の確認とは別に記録する。

Windows端末は [診断と実機検証手順](windows-business-verification.md) を使用する。診断の正常終了だけでカード読取・打刻・復旧再送を実機合格にしない。
