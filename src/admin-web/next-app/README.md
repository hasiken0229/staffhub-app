# Next.js 管理Web本実装骨組み

場所:

- `src/admin-web/next-app`

## 構成

- `app`
  - App Router
- `components`
  - ダッシュボードUI部品
- `lib/api.ts`
  - バックエンドAPIクライアント
- `types`
  - 型定義

## 前提

- `NEXT_PUBLIC_API_BASE_URL` でバックエンドURLを指定
- `NEXT_PUBLIC_BASE_PATH` で配置先サブパスを指定

例:

- `NEXT_PUBLIC_API_BASE_URL=https://ikegami-wakaba.jp/dakoku`
- `NEXT_PUBLIC_BASE_PATH=/dakoku/admin`

## ローカル起動

```powershell
cd src/admin-web/next-app
npm install
$env:NEXT_PUBLIC_API_BASE_URL="https://ikegami-wakaba.jp/dakoku"
$env:NEXT_PUBLIC_BASE_PATH=""
npm run dev
```

## Xserver共有サーバー向けビルド

静的エクスポート前提です。ビルド後に `out` フォルダが生成されます。

```powershell
cd src/admin-web/next-app
npm install
$env:NEXT_PUBLIC_API_BASE_URL="https://ikegami-wakaba.jp/dakoku"
$env:NEXT_PUBLIC_BASE_PATH="/dakoku/admin"
npm run build
```

出力先:

- `src/admin-web/next-app/out`

## Xserver反映先の例

管理画面を `https://ikegami-wakaba.jp/dakoku/admin/` で公開する場合:

- `out` の中身を `public_html/dakoku/admin/` にアップロード
- `out-login` の中身を `public_html/dakoku/login/` にアップロード

片方だけ古い状態を避けるため、ビルド後はまとめてアップロード用の構成を作れます。

```powershell
cd src/admin-web/next-app
powershell -ExecutionPolicy Bypass -File .\scripts\prepare-xserver-upload.ps1
```

生成先:

- `src/admin-web/next-app/tmp/xserver-upload/dakoku/admin/`
- `src/admin-web/next-app/tmp/xserver-upload/dakoku/login/`

WinSCP の保存済みサイト名またはセッション URL があれば、そのままアップロードまで実行できます。

```powershell
cd src/admin-web/next-app
powershell -ExecutionPolicy Bypass -File .\scripts\upload-xserver-via-winscp.ps1 -Session "保存済みサイト名"
```

プレビューだけ確認したい場合:

```powershell
cd src/admin-web/next-app
powershell -ExecutionPolicy Bypass -File .\scripts\upload-xserver-via-winscp.ps1 -Session "保存済みサイト名" -Preview
```

補足:

- 既定の WinSCP 実行ファイルは `C:\Program Files (x86)\WinSCP\WinSCP.com`
- 既定の反映先は `/home/iwakaba/ikegami-wakaba.jp/public_html/dakoku`
- 保存済みサイトをフォルダ配下に置いている場合は `フォルダ名/サイト名` 形式で指定可能
- 別の反映先にしたい場合は `-RemoteRoot` で変更可能

## できること

- ダッシュボード表示
- 職員一覧
- 職員CSV取込
- カード一覧
- 打刻履歴
- 休暇承認
- 給与明細PDFアップロード
- 給与CSV/賞与CSV取込
- 監査ログ一覧
