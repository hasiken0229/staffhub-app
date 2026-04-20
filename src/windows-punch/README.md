# Windows打刻アプリ試作

実装先: `src/windows-punch/StaffHub.PunchApp`

## 内容

- WPFベースの打刻待受画面プロトタイプ
- 模擬カードUID入力と打刻API送信
- 通信失敗時の未送信キュー
- RC-S380実装へ差し替えやすいカードリーダー抽象
- RC-S380向け PC/SC 読取サービスの実装追加

## 現状

- `RcS380CardReaderService` と `MockCardReaderService` の両対応です
- `AttendanceApiClient` はバックエンドの `/api/attendance/punch` に送信します
- Sony資料で RC-S380 が `PC/SC 2.0` と `FeliCa` に対応していることを前提に、PC/SC 経由でカード識別子取得を試みる実装にしています
- `FF CA 00 00 00` による識別子取得部分は、RC-S380 の PC/SC 利用を前提にした実装上の推定を含みます
- `punchsettings.json` で API URL と端末コードを切り替えられます

## 想定起動

`.NET SDK 8` 導入後:

```powershell
cd src/windows-punch/StaffHub.PunchApp
dotnet run
```

## 本番URLへ切り替える

`src/windows-punch/StaffHub.PunchApp/punchsettings.json` を編集します。

```json
{
  "apiBaseUrl": "https://ikegami-wakaba.jp/dakoku",
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
