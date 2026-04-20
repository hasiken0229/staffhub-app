<?php

return [
    'notifications' => [
        'fallback_to' => array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) env('STAFFHUB_NOTIFICATION_FALLBACK_TO', ''))
        ))),
        'admin_to' => array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) env('STAFFHUB_ADMIN_NOTIFICATION_TO', ''))
        ))),
        'subject_prefix' => trim((string) env('STAFFHUB_MAIL_SUBJECT_PREFIX', '[勤怠管理]')),
    ],
    'google_chat' => [
        'enabled' => filter_var(env('STAFFHUB_GOOGLE_CHAT_ENABLED', false), FILTER_VALIDATE_BOOL),
        'credentials_path' => ($value = trim((string) env('STAFFHUB_GOOGLE_CHAT_CREDENTIALS_PATH', ''))) !== '' ? $value : null,
        'bot_scope' => trim((string) env('STAFFHUB_GOOGLE_CHAT_BOT_SCOPE', 'https://www.googleapis.com/auth/chat.bot')),
        'admin_space_id' => ($value = trim((string) env('STAFFHUB_GOOGLE_CHAT_ADMIN_SPACE_ID', ''))) !== '' ? $value : null,
        'all_staff_space_id' => ($value = trim((string) env('STAFFHUB_GOOGLE_CHAT_ALL_STAFF_SPACE_ID', ''))) !== '' ? $value : null,
        'message_timeout_seconds' => max(1, (int) env('STAFFHUB_GOOGLE_CHAT_MESSAGE_TIMEOUT_SECONDS', 10)),
        'templates' => [
            'notice_targeted' => [
                'title' => '{{title}}',
                'lines' => [
                    '{{body}}',
                ],
            ],
            'notice_all_staff' => [
                'title' => '{{title}}',
                'lines' => [
                    '{{body}}',
                ],
            ],
            'payroll_published' => [
                'title' => '{{statementLabel}}を公開しました',
                'lines' => [
                    '{{targetYearMonth}} の{{statementLabel}}を確認できます。',
                ],
            ],
            'leave_decision_approved' => [
                'title' => '休暇申請が承認されました',
                'lines' => [
                    '{{commentOrDefault}}',
                ],
            ],
            'leave_decision_rejected' => [
                'title' => '休暇申請が却下されました',
                'lines' => [
                    '{{commentOrDefault}}',
                ],
            ],
            'leave_decision_returned' => [
                'title' => '休暇申請が差し戻されました',
                'lines' => [
                    '{{commentOrDefault}}',
                ],
            ],
            'leave_decision_default' => [
                'title' => '休暇申請が更新されました',
                'lines' => [
                    '{{commentOrDefault}}',
                ],
            ],
            'leave_request_created' => [
                'title' => '休暇申請が届きました',
                'lines' => [
                    '{{employeeName}}さんから休暇申請がありました。',
                ],
            ],
            'attendance_alert_short_interval' => [
                'title' => '短時間の連続打刻を検出しました',
                'lines' => [
                    '{{employeeName}}さんの連続打刻を確認してください。',
                ],
            ],
        ],
    ],
];
