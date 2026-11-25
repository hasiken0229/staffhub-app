# Windows打刻アプリ試作

実装先: `src/windows-punch/StaffHub.PunchApp`

## 内容

- WPFベースの打刻待受画面プロトタイプ
- 模擬カードUID入力と打刻API送信
- 通信失敗時の未送信キューと自動再送
- RC-S380実装へ差し替えやすいカードリーダー抽象
- RC-S380向け PC/SC 読取サービスの実装追加

## 現状

- `RcS380CardReaderService` と `MockCardReaderService` の両対応です
- `AttendanceApiClient` はバックエンドの `/api/attendance/punch` に送信します
- Sony資料で RC-S380 が `PC/SC 2.0` と `FeliCa` に対応していることを前提に、PC/SC 経由でカード識別子取得を試みる実装にしています
- `FF CA 00 00 00` による識別子取得部分は、RC-S380 の PC/SC 利用を前提にした実装上の推定を含みます
- `punchsettings.json` で API URL と端末コードを切り替えられます

## 想定起動

開発・動作確認では `.NET SDK 10 LTS` 導入後:

```powershell
cd src/windows-punch/StaffHub.PunchApp
dotnet run
```

オフライン打刻は `%LOCALAPPDATA%\StaffHub\PunchApp\pending-punches.json` に保存されます。
アプリやPCを再起動しても、元の打刻時刻と重複防止キーを維持したまま再送されます。

アプリ起動の約3秒後と以降30秒ごとに、未送信打刻を古い順に最大20件まで自動再送します。
HTTP 408・429・5xx、通信断、端末認証エラー、月締め等では未送信データを削除しません。
サーバーに拒否記録が確実に残る `CARD_NOT_REGISTERED`、`TOO_MANY_PUNCHES`、`SHORT_INTERVAL_PUNCH` のみ、再送対象から削除します。
保守パネルの「未送信を再送」は、障害確認時の手動操作用として残しています。

### 受付拒否・確認対象の結果

自動再送で受付拒否された打刻は、時刻・理由を主画面の直近履歴へ表示します。
タイトルを3回押して開く保守パネルでは、保存された確認結果を一覧で参照できます。
`%LOCALAPPDATA%\StaffHub\PunchApp\punch-review-receipts.json` は再起動後も読まれます。
保存項目は元の送信キー、打刻時刻、受信時刻、固定の理由コード、返されたイベントIDだけです。
氏名・カードUID・サーバーの自由文は保存しません。新しい記録の追加時に受信から30日超を整理し、直近200件まで保持します。
記録が追加されない間は既存ファイルを保持します。主画面には直近10件、保守パネルには保管中の全件を表示します。
閲覧できるのは打刻画面の利用者と、そのWindowsユーザーの保存領域を読める管理者です。

確認結果を安全に保存できるまで、その打刻は未送信キューから削除しません。
台帳破損・読込不能・保存失敗では原本を保持して後続送信を止め、管理者確認を表示します。
台帳を空ファイルに置き換えたり、未送信ファイルを削除したりしないでください。
サーバーが原本を保存して返した `WARNING` も「原本保存済・要確認」として保持し、通常の打刻成立と区別します。
再送しても元の時刻・送信キーを使い、過去の打刻を新しいキーで作り直しません。

### カードリーダーの選択と復旧

`readerMode` は大文字小文字を区別せず、次の値を受け付けます。

| 値 | 選択 |
| --- | --- |
| `MOCK` | 模擬リーダーだけを生成。PC/SC・FeliCaの実読取を開始しない |
| `PCSC` | PC/SCを明示選択。FeliCa DLLが存在しても使用しない |
| `FELICA` | FeliCaライブラリを明示選択 |
| `AUTO` | 従来のSony共有フォルダにfelica.dllがあればFeliCa、それ以外はPC/SC |
| `RC_S380` | 既存設定との互換用。従来と同じ`AUTO`選択 |

不明な値は設定エラーとして表示し、実リーダーを生成しません。
FeliCaのDLL不足・bitness不一致・必要関数欠如、読取workerの終了は、初期化待ちのままにせずエラー・停止状態を表示します。
復旧操作では旧workerの停止を確認してから新しいリーダーを作成します。停止待ちのworkerを残して二重に開始しません。
`AUTO`は障害時に別方式へ勝手に切り替えません。DLL/ドライバーの整合を管理者が確認するか、確認済みの`PCSC`等へ設定変更して再起動してください。

10月10日配布済みの`StartPunch.ps1`は`RC_S380`以外を起動前に拒否します。
今回の変更はローカル製品ソースだけで、既存配布ZIP・起動スクリプトは更新していません。
新モードを別PCで使う際には、配布作業で起動スクリプトの受入値も合わせる必要があります。
DLLの版・依存関係・32/64bit、実機の抜差し・継続提示・Windows再起動は、実端末で別途確認してください。

起動時と1分ごとにheartbeatを送信し、サーバー受信時刻・未送信件数・カードリーダー状態・端末時計との差を記録します。
端末時計がサーバーより30秒超ずれると注意、120秒超ずれると要確認として打刻画面に表示します。時刻異常でも打刻は停止しないため、Windowsの時刻同期設定を直したあとに通常どおり運用を継続できます。

自動テスト:

```powershell
dotnet test src/windows-punch/StaffHub.PunchApp.Tests/StaffHub.PunchApp.Tests.csproj
```

## 本番URLへ切り替える

`src/windows-punch/StaffHub.PunchApp/punchsettings.json` を編集します。

```json
{
  "apiBaseUrl": "https://attendance.example.org/dakoku",
  "deviceCode": "PC-ENTRANCE-01",
  "deviceSecret": "secret-value",
  "deviceName": "玄関端末",
  "autoStartEnabled": true,
  "readerMode": "RC_S380",
  "preferredReaderName": "RC-S380",
  "pollIntervalMilliseconds": 700
}
```

ビルド後は出力先にも `punchsettings.json` がコピーされるので、実行ファイル横の同名ファイルを編集しても切り替えできます。

## 玄関PCでの自動起動

打刻アプリの自動起動は、専用Windowsユーザーのタスクスケジューラで「ログオン時」に実行する運用を推奨します。
`autoStartEnabled` は設定互換用の項目であり、アプリ自身がタスクスケジューラへ登録するものではありません。
