# Xserver共有レンタルサーバー向け API設計書

## 1. 目的

本書は、`Xserver共有レンタルサーバー` 上で `PHP / Laravel` により実装することを前提に、主要APIのI/Oを定義する。

対象クライアントは以下。

- Windows打刻アプリ
- 職員向けWeb/PWA
- 管理者向けWeb

## 2. 実装方針

- Laravel の `routes/api.php` を中心に構成
- 認証付き画面は `Sanctum` または `Session + CSRF` を利用
- Windows打刻アプリは `deviceCode + deviceSecret` で認証
- レスポンス形式は既存設計と揃え、`data / meta / error` を基本とする

## 3. 共通仕様

## 3.1 ベースURL

- `/api`

## 3.2 正常レスポンス

```json
{
  "data": {},
  "meta": {}
}
```

## 3.3 エラーレスポンス

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "入力内容を確認してください。",
    "details": [
      {
        "field": "cardUid",
        "message": "カードUIDを入力してください。"
      }
    ]
  }
}
```

## 3.4 主なエラーコード

- `UNAUTHORIZED`
- `FORBIDDEN`
- `VALIDATION_ERROR`
- `NOT_FOUND`
- `CARD_NOT_REGISTERED`
- `DEVICE_DISABLED`
- `INSUFFICIENT_PAID_LEAVE`
- `FILE_UPLOAD_ERROR`
- `INTERNAL_ERROR`

## 4. Laravelでのルート整理案

## 4.1 認証不要

- `POST /api/attendance/punch`
- `POST /api/attendance/devices/heartbeat`
- `POST /api/auth/login`

## 4.2 職員認証必須

- `GET /api/mobile/home`
- `GET /api/leave/balance`
- `GET /api/leave/requests`
- `POST /api/leave/requests`
- `GET /api/payroll/statements`
- `GET /api/payroll/statements/{id}`
- `POST /api/payroll/statements/{id}/viewed`
- `GET /api/notifications`
- `POST /api/notifications/{id}/read`

## 4.3 管理者認証必須

- `GET /api/admin/employees`
- `POST /api/admin/employees`
- `PUT /api/admin/employees/{id}`
- `GET /api/admin/cards`
- `POST /api/admin/cards/assign`
- `POST /api/admin/cards/revoke`
- `GET /api/admin/attendance/events`
- `GET /api/admin/attendance/daily`
- `GET /api/admin/leave/requests`
- `POST /api/admin/leave/requests/{id}/approve`
- `POST /api/admin/leave/requests/{id}/reject`
- `POST /api/admin/leave/requests/{id}/return`
- `GET /api/admin/payroll/statements`
- `POST /api/admin/payroll/statements`
- `GET /api/admin/audit-logs`

## 5. Windows打刻アプリ API

## 5.1 打刻受付

- `POST /api/attendance/punch`

### リクエスト

```json
{
  "deviceCode": "PC-ENTRANCE-01",
  "deviceSecret": "secret-value",
  "cardUid": "012E4CE15C908F48",
  "occurredAt": "2026-03-21T08:31:45+09:00",
  "dedupeKey": "PC-ENTRANCE-01-20260321083145-0001",
  "appVersion": "1.0.0"
}
```

### 正常レスポンス

```json
{
  "data": {
    "attendanceEventId": 10001,
    "employee": {
      "id": 1,
      "employeeCode": "E0001",
      "name": "山田 太郎"
    },
    "eventType": "CLOCK_IN",
    "resultType": "SUCCESS",
    "resultMessage": "出勤を記録しました。",
    "occurredAt": "2026-03-21T08:31:45+09:00",
    "offlineAccepted": false
  }
}
```

### エラー例

```json
{
  "error": {
    "code": "CARD_NOT_REGISTERED",
    "message": "このカードは登録されていません。"
  }
}
```

### Laravel実装メモ

- `AttendancePunchController@store`
- FormRequest で入力検証
- Service層で `カード検索 -> 出退勤判定 -> event保存 -> daily更新`

## 5.2 端末ハートビート

- `POST /api/attendance/devices/heartbeat`

### リクエスト

```json
{
  "deviceCode": "PC-ENTRANCE-01",
  "deviceSecret": "secret-value",
  "appVersion": "1.0.0",
  "lastSeenAt": "2026-03-21T08:31:45+09:00",
  "pendingOfflineCount": 0
}
```

### レスポンス

```json
{
  "data": {
    "success": true,
    "serverTime": "2026-03-21T08:31:45+09:00",
    "deviceActive": true
  }
}
```

## 6. 管理者向け API

## 6.1 カード一覧

- `GET /api/admin/cards`

### クエリ

- `cardUid`
- `employeeCode`
- `isActive`
- `page`
- `perPage`

### レスポンス

```json
{
  "data": [
    {
      "id": 11,
      "employeeId": 1,
      "employeeCode": "E0001",
      "employeeName": "山田 太郎",
      "cardUid": "012E4CE15C908F48",
      "isActive": true,
      "assignedAt": "2026-03-21T15:52:11+09:00",
      "revokedAt": null
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 1
  }
}
```

## 6.2 カード登録

- `POST /api/admin/cards/assign`

### リクエスト

```json
{
  "employeeId": 1,
  "cardUid": "012E4CE15C908F48"
}
```

### レスポンス

```json
{
  "data": {
    "id": 11,
    "employeeId": 1,
    "employeeCode": "E0001",
    "employeeName": "山田 太郎",
    "cardUid": "012E4CE15C908F48",
    "isActive": true,
    "assignedAt": "2026-03-21T15:52:11+09:00",
    "revokedAt": null
  }
}
```

### 処理ルール

- `cardUid` は大文字化して保存
- 同一UIDの有効カードがあれば先に無効化
- 同一職員の有効カードがあれば先に無効化
- 監査ログを記録

## 6.3 カード失効

- `POST /api/admin/cards/revoke`

### リクエスト

```json
{
  "cardId": 11
}
```

### レスポンス

```json
{
  "data": {
    "success": true
  }
}
```

## 6.4 打刻履歴一覧

- `GET /api/admin/attendance/events`

### クエリ

- `from`
- `to`
- `employeeCode`
- `receiveStatus`
- `deviceCode`
- `page`
- `perPage`

### レスポンス例

```json
{
  "data": [
    {
      "id": 10002,
      "employeeId": 1,
      "employeeCode": "E0001",
      "employeeName": "山田 太郎",
      "deviceId": 1,
      "deviceCode": "PC-ENTRANCE-01",
      "deviceName": "玄関端末",
      "cardUid": "012E4CE15C908F48",
      "occurredAt": "2026-03-21T16:02:33+09:00",
      "eventType": "CLOCK_OUT",
      "receiveStatus": "ACCEPTED",
      "rejectionReason": null,
      "offlineSaved": false
    }
  ]
}
```

## 6.5 日次勤怠一覧

- `GET /api/admin/attendance/daily`

### クエリ

- `targetMonth`
- `employeeCode`
- `departmentName`
- `page`
- `perPage`

### レスポンス例

```json
{
  "data": [
    {
      "employeeId": 1,
      "employeeCode": "E0001",
      "employeeName": "山田 太郎",
      "targetDate": "2026-03-21",
      "clockInAt": "2026-03-21T15:58:23+09:00",
      "clockOutAt": "2026-03-21T16:02:33+09:00",
      "workMinutes": 4,
      "absenceFlag": false,
      "specialLeaveFlag": false,
      "paidLeaveUnit": null
    }
  ]
}
```

## 7. 職員向け API

## 7.1 ホーム

- `GET /api/mobile/home`

### レスポンス

```json
{
  "data": {
    "employee": {
      "id": 1,
      "employeeCode": "E0001",
      "name": "山田 太郎"
    },
    "pendingLeaveCount": 1,
    "paidLeaveBalance": 8.5,
    "unreadNotificationCount": 2,
    "latestPayroll": {
      "id": 1,
      "targetYearMonth": "2026-02",
      "originalFileName": "salary_2026_02.pdf",
      "publishedAt": "2026-03-25T09:00:00+09:00"
    }
  }
}
```

## 7.2 休暇申請登録

- `POST /api/leave/requests`

### リクエスト

```json
{
  "leaveTypeCode": "PAID",
  "startDate": "2026-04-03",
  "endDate": "2026-04-03",
  "dayUnit": "HALF",
  "halfDayType": "AM",
  "reason": "私用のため"
}
```

### 処理ルール

- `startDate <= endDate`
- 半日は同日だけ
- 欠勤はMVPでは半日不可
- 有給のみ残数不足チェック

## 7.3 給与明細一覧

- `GET /api/payroll/statements`

### クエリ

- `yearMonth`
- `page`
- `perPage`

## 7.4 給与明細閲覧登録

- `POST /api/payroll/statements/{id}/viewed`

### リクエスト

```json
{
  "viewedAt": "2026-03-25T09:05:10+09:00"
}
```

## 8. ファイルアップロードAPI

## 8.1 給与明細アップロード

- `POST /api/admin/payroll/statements`

### 送信形式

- `multipart/form-data`

### フィールド

- `employeeId`
- `targetYearMonth`
- `publishedAt`
- `file`

### 処理ルール

- PDFのみ許可
- サイズ上限を設定
- 保存先は非公開領域
- DBには保存パスと元ファイル名のみ保持

## 9. Laravel実装単位のおすすめ

## 9.1 Controller

- `AuthController`
- `AttendancePunchController`
- `AttendanceAdminController`
- `CardAdminController`
- `EmployeeAdminController`
- `LeaveController`
- `LeaveAdminController`
- `PayrollController`
- `PayrollAdminController`
- `NotificationController`

## 9.2 Service

- `AttendanceService`
- `CardAssignmentService`
- `LeaveRequestService`
- `PayrollStatementService`
- `NotificationService`
- `AuditLogService`

## 9.3 Middleware

- `EnsureAdminRole`
- `EnsureAttendanceDevice`

## 10. 実装優先順

1. `POST /api/attendance/punch`
2. `GET /api/admin/cards`
3. `POST /api/admin/cards/assign`
4. `GET /api/admin/attendance/events`
5. `GET /api/admin/attendance/daily`
6. `POST /api/leave/requests`
7. `POST /api/admin/leave/requests/{id}/approve`
8. `POST /api/admin/payroll/statements`

## 11. 次に作るもの

この次は以下が自然。

1. `MySQL schema.sql`
2. `Laravel migration雛形`
3. `Windows打刻アプリのPHP API向け切替仕様`
