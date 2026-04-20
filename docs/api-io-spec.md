# 職員打刻・休暇申請・給与明細配信アプリ API I/O定義書

## 1. 目的

本書は、MVPで実装する主要APIの入出力を定義する。

対象は以下のクライアントである。

- Windows打刻アプリ
- 職員向けモバイルアプリ
- 管理者向けWebアプリ

## 2. 共通仕様

### 2.1 ベースURL

- `/api`

### 2.2 形式

- リクエスト、レスポンスは JSON を基本とする
- 給与明細PDF取得のみバイナリまたは署名付きURL返却を許容する

### 2.3 認証

- モバイル、管理Webは Bearer Token 認証
- Windows打刻アプリは端末コードと端末シークレット、または専用APIキーを利用

### 2.4 共通レスポンス形式

#### 正常

```json
{
  "data": {},
  "meta": {}
}
```

#### エラー

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "入力内容を確認してください。",
    "details": [
      {
        "field": "startDate",
        "message": "開始日は終了日以前である必要があります。"
      }
    ]
  }
}
```

### 2.5 主なエラーコード

- `UNAUTHORIZED`
- `FORBIDDEN`
- `VALIDATION_ERROR`
- `NOT_FOUND`
- `DUPLICATE_REQUEST`
- `INSUFFICIENT_PAID_LEAVE`
- `CARD_NOT_REGISTERED`
- `DEVICE_DISABLED`
- `FILE_UPLOAD_ERROR`
- `INTERNAL_ERROR`

## 3. 認証API

## 3.1 ログイン

- `POST /api/auth/login`

### リクエスト

```json
{
  "loginId": "staff001",
  "password": "password"
}
```

### レスポンス

```json
{
  "data": {
    "accessToken": "jwt-token",
    "refreshToken": "refresh-token",
    "user": {
      "id": 1,
      "role": "EMPLOYEE",
      "employeeCode": "E0001",
      "name": "山田 太郎"
    }
  }
}
```

## 3.2 トークン更新

- `POST /api/auth/refresh`

### リクエスト

```json
{
  "refreshToken": "refresh-token"
}
```

### レスポンス

```json
{
  "data": {
    "accessToken": "new-jwt-token",
    "refreshToken": "new-refresh-token"
  }
}
```

## 3.3 ログアウト

- `POST /api/auth/logout`

### リクエスト

```json
{
  "refreshToken": "refresh-token"
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

## 4. Windows打刻アプリAPI

## 4.1 打刻受付

- `POST /api/attendance/punch`

### 用途

カード読取結果を送信し、打刻判定結果を返す。

### リクエスト

```json
{
  "deviceCode": "PC-ENTRANCE-01",
  "deviceSecret": "secret-value",
  "cardUid": "0123456789ABCDEF",
  "occurredAt": "2026-03-21T08:31:45+09:00",
  "dedupeKey": "PC-ENTRANCE-01-20260321083145-0001",
  "appVersion": "1.0.0"
}
```

### 成功レスポンス

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

### 警告レスポンス例

```json
{
  "data": {
    "attendanceEventId": 10002,
    "employee": {
      "id": 1,
      "employeeCode": "E0001",
      "name": "山田 太郎"
    },
    "eventType": "CLOCK_OUT",
    "resultType": "WARNING",
    "resultMessage": "短時間の連続打刻です。内容を確認してください。",
    "occurredAt": "2026-03-21T08:33:10+09:00",
    "offlineAccepted": false
  }
}
```

### エラーレスポンス例

```json
{
  "error": {
    "code": "CARD_NOT_REGISTERED",
    "message": "このカードは登録されていません。"
  }
}
```

## 4.2 端末接続確認

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
    "serverTime": "2026-03-21T08:31:46+09:00",
    "deviceActive": true
  }
}
```

## 5. 職員向けモバイルAPI

## 5.1 ホーム情報取得

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
      "id": 21,
      "targetYearMonth": "2026-02",
      "publishedAt": "2026-03-20T09:00:00+09:00",
      "viewed": false
    }
  }
}
```

## 5.2 有給残数取得

- `GET /api/leave/balance`

### レスポンス

```json
{
  "data": {
    "employeeId": 1,
    "currentBalance": 8.5,
    "grants": [
      {
        "id": 1,
        "grantedOn": "2025-04-01",
        "grantedDays": 10.0,
        "usedDays": 1.5,
        "expiresOn": "2027-03-31"
      }
    ]
  }
}
```

## 5.3 休暇申請一覧取得

- `GET /api/leave/requests`

### クエリ

- `status`
- `from`
- `to`
- `page`
- `perPage`

### レスポンス

```json
{
  "data": [
    {
      "id": 51,
      "leaveTypeCode": "PAID",
      "leaveTypeName": "有給休暇",
      "startDate": "2026-04-03",
      "endDate": "2026-04-03",
      "dayUnit": "HALF",
      "halfDayType": "AM",
      "quantityDays": 0.5,
      "status": "PENDING",
      "createdAt": "2026-03-21T10:00:00+09:00"
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 1
  }
}
```

## 5.4 休暇申請登録

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

### 成功レスポンス

```json
{
  "data": {
    "id": 51,
    "status": "PENDING",
    "quantityDays": 0.5,
    "createdAt": "2026-03-21T10:00:00+09:00"
  }
}
```

## 5.5 休暇申請詳細取得

- `GET /api/leave/requests/{id}`

### レスポンス

```json
{
  "data": {
    "id": 51,
    "employee": {
      "id": 1,
      "name": "山田 太郎"
    },
    "leaveTypeCode": "PAID",
    "leaveTypeName": "有給休暇",
    "startDate": "2026-04-03",
    "endDate": "2026-04-03",
    "dayUnit": "HALF",
    "halfDayType": "AM",
    "quantityDays": 0.5,
    "reason": "私用のため",
    "status": "PENDING",
    "decisionComment": null,
    "actions": [
      {
        "actionType": "APPLIED",
        "actionByName": "山田 太郎",
        "actedAt": "2026-03-21T10:00:00+09:00",
        "comment": null
      }
    ]
  }
}
```

## 5.6 給与明細一覧取得

- `GET /api/payroll/statements`

### クエリ

- `yearMonth`
- `page`
- `perPage`

### レスポンス

```json
{
  "data": [
    {
      "id": 21,
      "targetYearMonth": "2026-02",
      "originalFileName": "salary_2026_02.pdf",
      "publishedAt": "2026-03-20T09:00:00+09:00",
      "viewed": false
    }
  ]
}
```

## 5.7 給与明細取得

- `GET /api/payroll/statements/{id}`

### レスポンス例 1: 署名付きURL返却

```json
{
  "data": {
    "id": 21,
    "targetYearMonth": "2026-02",
    "downloadUrl": "https://storage.example.com/signed-url",
    "expiresAt": "2026-03-21T12:10:00+09:00"
  }
}
```

### レスポンス例 2: バイナリ返却

- `Content-Type: application/pdf`

## 5.8 給与明細閲覧記録

- `POST /api/payroll/statements/{id}/viewed`

### リクエスト

```json
{
  "viewedAt": "2026-03-21T12:00:00+09:00"
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

## 5.9 通知一覧取得

- `GET /api/notifications`

### クエリ

- `isRead`
- `page`
- `perPage`

### レスポンス

```json
{
  "data": [
    {
      "id": 3001,
      "notificationType": "LEAVE_RESULT",
      "title": "休暇申請が承認されました",
      "body": "2026-04-03 の有給休暇申請が承認されました。",
      "isRead": false,
      "sentAt": "2026-03-21T11:00:00+09:00"
    }
  ]
}
```

## 5.10 通知既読化

- `POST /api/notifications/{id}/read`

### レスポンス

```json
{
  "data": {
    "success": true,
    "readAt": "2026-03-21T11:05:00+09:00"
  }
}
```

## 6. 管理者向けWeb API

## 6.1 職員一覧取得

- `GET /api/admin/employees`

### クエリ

- `employeeCode`
- `name`
- `departmentName`
- `status`
- `page`
- `perPage`

### レスポンス

```json
{
  "data": [
    {
      "id": 1,
      "employeeCode": "E0001",
      "name": "山田 太郎",
      "departmentName": "総務部",
      "employmentType": "FULL_TIME",
      "status": "ACTIVE"
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 1
  }
}
```

## 6.2 職員登録

- `POST /api/admin/employees`

### リクエスト

```json
{
  "employeeCode": "E0001",
  "name": "山田 太郎",
  "kana": "ヤマダ タロウ",
  "departmentName": "総務部",
  "employmentType": "FULL_TIME",
  "status": "ACTIVE",
  "joinedOn": "2024-04-01",
  "loginId": "staff001",
  "initialPassword": "TempPass1234"
}
```

### レスポンス

```json
{
  "data": {
    "id": 1
  }
}
```

## 6.3 職員更新

- `PUT /api/admin/employees/{id}`

### リクエスト

```json
{
  "name": "山田 太郎",
  "departmentName": "総務部",
  "employmentType": "FULL_TIME",
  "status": "ACTIVE",
  "retiredOn": null
}
```

## 6.4 カード一覧取得

- `GET /api/admin/cards`

### クエリ

- `cardUid`
- `employeeCode`
- `isActive`

### レスポンス

```json
{
  "data": [
    {
      "id": 10,
      "cardUid": "0123456789ABCDEF",
      "employeeId": 1,
      "employeeCode": "E0001",
      "employeeName": "山田 太郎",
      "isActive": true,
      "assignedAt": "2026-03-01T09:00:00+09:00"
    }
  ]
}
```

## 6.5 カード紐付け

- `POST /api/admin/cards/assign`

### リクエスト

```json
{
  "employeeId": 1,
  "cardUid": "0123456789ABCDEF"
}
```

### レスポンス

```json
{
  "data": {
    "id": 10,
    "isActive": true
  }
}
```

## 6.6 カード無効化

- `POST /api/admin/cards/revoke`

### リクエスト

```json
{
  "cardId": 10
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

## 6.7 打刻履歴一覧取得

- `GET /api/admin/attendance/events`

### クエリ

- `from`
- `to`
- `employeeCode`
- `receiveStatus`
- `deviceCode`
- `page`
- `perPage`

### レスポンス

```json
{
  "data": [
    {
      "id": 10001,
      "occurredAt": "2026-03-21T08:31:45+09:00",
      "employeeCode": "E0001",
      "employeeName": "山田 太郎",
      "cardUid": "0123456789ABCDEF",
      "eventType": "CLOCK_IN",
      "receiveStatus": "ACCEPTED",
      "rejectionReason": null,
      "deviceCode": "PC-ENTRANCE-01",
      "deviceName": "玄関端末"
    }
  ]
}
```

## 6.8 日次勤怠一覧取得

- `GET /api/admin/attendance/daily`

### クエリ

- `targetMonth`
- `employeeCode`
- `departmentName`
- `page`
- `perPage`

### レスポンス

```json
{
  "data": [
    {
      "employeeId": 1,
      "employeeCode": "E0001",
      "employeeName": "山田 太郎",
      "targetDate": "2026-03-21",
      "clockInAt": "2026-03-21T08:31:45+09:00",
      "clockOutAt": "2026-03-21T17:30:00+09:00",
      "workMinutes": 538,
      "absenceFlag": false,
      "specialLeaveFlag": false,
      "paidLeaveUnit": null
    }
  ]
}
```

## 6.9 休暇申請一覧取得

- `GET /api/admin/leave/requests`

### クエリ

- `status`
- `leaveTypeCode`
- `from`
- `to`
- `employeeName`
- `page`
- `perPage`

## 6.10 休暇申請承認

- `POST /api/admin/leave/requests/{id}/approve`

### リクエスト

```json
{
  "comment": "承認します"
}
```

### レスポンス

```json
{
  "data": {
    "id": 51,
    "status": "APPROVED",
    "approvedAt": "2026-03-21T10:30:00+09:00"
  }
}
```

## 6.11 休暇申請却下

- `POST /api/admin/leave/requests/{id}/reject`

### リクエスト

```json
{
  "comment": "対象日の人員配置の都合により却下します"
}
```

## 6.12 休暇申請差戻し

- `POST /api/admin/leave/requests/{id}/return`

### リクエスト

```json
{
  "comment": "理由をもう少し詳しく入力してください"
}
```

## 6.13 給与明細一覧取得

- `GET /api/admin/payroll/statements`

### クエリ

- `targetYearMonth`
- `employeeCode`
- `publishedStatus`
- `page`
- `perPage`

## 6.14 給与明細アップロード

- `POST /api/admin/payroll/statements`

### リクエスト

- `multipart/form-data`

### フィールド

| フィールド名 | 必須 | 説明 |
| --- | --- | --- |
| employeeId | YES | 対象職員ID |
| targetYearMonth | YES | YYYY-MM |
| publishedAt | NO | 即時公開しない場合は未来日時 |
| file | YES | PDFファイル |

### レスポンス

```json
{
  "data": {
    "id": 21,
    "employeeId": 1,
    "targetYearMonth": "2026-02",
    "publishedAt": "2026-03-20T09:00:00+09:00"
  }
}
```

## 6.15 監査ログ一覧取得

- `GET /api/admin/audit-logs`

### クエリ

- `from`
- `to`
- `action`
- `actorKeyword`
- `targetType`
- `page`
- `perPage`

### レスポンス

```json
{
  "data": [
    {
      "id": 90001,
      "occurredAt": "2026-03-21T10:30:00+09:00",
      "actorType": "ADMIN",
      "actorId": 100,
      "action": "LEAVE_APPROVED",
      "targetType": "LEAVE_REQUEST",
      "targetId": "51",
      "detail": {
        "comment": "承認します"
      }
    }
  ]
}
```

## 7. バリデーション方針

## 7.1 打刻

- `cardUid` 必須
- `deviceCode` 必須
- `occurredAt` 必須
- `dedupeKey` 必須

## 7.2 休暇申請

- `leaveTypeCode` は `PAID`, `ABSENCE`, `SPECIAL` のみ
- `startDate <= endDate`
- `dayUnit = HALF` のとき `halfDayType` 必須
- `ABSENCE` のとき `HALF` はMVPでは不可
- `PAID` のとき残数不足ならエラー

## 7.3 給与明細アップロード

- PDFのみ許可
- 同一職員、同一対象年月の重複登録は不可

## 8. 備考

- 管理者APIの認可はロール制御前提とする
- 日次勤怠再計算は同期またはジョブ実行のどちらでもよいが、初期MVPでは同期実行でよい
- 給与明細取得は、アプリ内ビューア利用を考慮して署名付きURL方式が実装しやすい

