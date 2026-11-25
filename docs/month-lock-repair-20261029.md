# 月ロック競合の修正（FINAL-01、2026-10-29）

ローカル修正。本番・既存配布物には未反映。既存DBの更新・移行・実未送信キューの読取や再送は行っていない。

## 変更内容

`MonthMutationLock::acquire()` の `insertOrIgnore` をQuery Builderのupsertに変更した。重複時の更新対象は、同じ値の `target_year_month` だけとする。新月は従来どおりOPENで作成し、既存行のstatus、note、締め・再開者、各日時は更新しない。テーブル・索引・既存データの移行は不要。

重複INSERTの共有ロックから後続FOR UPDATEの排他ロックへ昇格する競合を避け、重複時も初めから排他取得する。月の重複除去・昇順、transaction必須、FOR UPDATE、CLOSED拒否、既存のtransaction再試行回数は維持する。

## 検証範囲

追加の `MonthMutationLockSafetyTest` は、OPEN/CLOSEDの全保存列不変、締め済み拒否と新月作成のrollback、初月一意、月の取得順、親transaction失敗・入力不正のrollbackを確認する。

競合は専用MySQL 5.7.44の新規data・DB・保存先・合成職員と端末で再試験する。前回の12業務競合、8並列10群80応答、新月/既存月・同一キー/別職員/別キー、再送時の原本と日次の一意性に加え、保存列不変と逆順の複数月取得を確認する。結果・コマンド・実行ソースSHAは本修正の検証報告書に記録する。SQLite成功をMySQL成功へ転用しない。

## 本番適用前の確認

今回の専用DBには対象表のtriggerを作成していない。重複時upsertはUPDATE triggerも発火し得るため、実際の本番 `attendance_monthly_closes` のtriggerと列定義を読取で確認する。既存列が変わるtriggerがあれば、今回の保存値不変の検証だけで適用可能と判断しない。

現在の配置・依存版・DB版との照合、バックアップ/復元、旧打刻アプリ停止と未送信原本保全、端末設定の引継ぎ、実機受入は将来の本番作業として別に扱う。成功した合成試験の回数を、本番の発生確率や全deadlockの不存在の保証にしない。

公式根拠：

- [MySQL5.7: SQL文が取得するロック](https://docs.oracle.com/cd/E17952_01/mysql-5.7-en/innodb-locks-set.html)
- [MySQL5.7: triggerの発火](https://docs.oracle.com/cd/E17952_01/mysql-5.7-en/create-trigger.html)
- [MySQL5.7: deadlockへの対処](https://docs.oracle.com/cd/E17952_01/mysql-5.7-en/innodb-deadlocks-handling.html)
