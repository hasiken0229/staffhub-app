# バックエンド雛形

`src/backend/StaffHub.Api` に ASP.NET Core を想定した API 雛形を作成しています。

## できること

- 設計書に対応する主要 API パスの骨組み
- インメモリのサンプルデータ応答
- 打刻、有給申請、給与明細、管理画面系の基本モック
- `App_Data/staffhub-state.json` へのJSON永続化

## 現時点の注意

- JWT、本物のファイル保存、PostgreSQL 接続は未実装です
- まずは画面側の接続確認や API 契約の合意、打刻実機確認に使う前提です

## 永続化

- 再起動してもカード割当、打刻、有給申請、通知などが残るようにしています
- 保存先: `src/backend/StaffHub.Api/App_Data/staffhub-state.json`
- `health` API でも現在の保存モードと保存先を確認できます

## PostgreSQL 接続

- `appsettings.json` の `Persistence:UsePostgreSqlCore` を `true` にし、`ConnectionStrings:StaffHubPostgres` を設定すると、カード管理と勤怠系APIを PostgreSQL に切り替えられます
- 初回起動時は `sql/schema.sql` を使ってスキーマを自動作成します
- JSON永続化は残るため、未対応APIは引き続き `staffhub-state.json` を利用します

例:

```json
{
  "Persistence": {
    "UsePostgreSqlCore": true
  },
  "ConnectionStrings": {
    "StaffHubPostgres": "Host=localhost;Port=5432;Database=staffhub;Username=postgres;Password=postgres"
  }
}
```

## 想定コマンド

SDK 導入後は以下で起動想定です。

```powershell
cd src/backend/StaffHub.Api
dotnet run
```

## デモ用ログイン

- 職員: `staff001 / password`
- 管理者: `admin001 / password`
