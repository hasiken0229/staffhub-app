<?php

namespace Tests\Feature;

use App\Services\GoogleChatMessageBuilder;
use Tests\TestCase;

final class GoogleChatMessageBuilderTest extends TestCase
{
    public function test_it_renders_message_from_template_variables(): void
    {
        $builder = app(GoogleChatMessageBuilder::class);

        $message = $builder->buildMessage([
            'title' => '{{statementLabel}}を公開しました',
            'lines' => [
                '{{targetYearMonth}} の{{statementLabel}}を確認できます。',
            ],
            'context' => [
                '対象年月' => '{{targetYearMonth}}',
            ],
        ], [
            'statementLabel' => '給与明細',
            'targetYearMonth' => '2026-04',
        ]);

        $this->assertSame('給与明細を公開しました', $message['title']);
        $this->assertSame(['2026-04 の給与明細を確認できます。'], $message['lines']);
        $this->assertSame(['対象年月' => '2026-04'], $message['context']);
    }
}
