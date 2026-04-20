<?php

namespace App\Services;

final class GoogleChatMessageBuilder
{
    /**
     * @param array{title?: string, lines?: array<int, string>, context?: array<string, string>} $template
     * @param array<string, scalar|null> $variables
     * @return array{title: string, lines: array<int, string>, context: array<string, string>}
     */
    public function buildMessage(array $template, array $variables = []): array
    {
        $title = $this->renderTemplateString((string) ($template['title'] ?? ''), $variables);

        $lines = [];
        foreach ((array) ($template['lines'] ?? []) as $line) {
            if (!is_string($line)) {
                continue;
            }

            $rendered = trim($this->renderTemplateString($line, $variables));
            if ($rendered !== '') {
                $lines[] = $rendered;
            }
        }

        $context = [];
        foreach ((array) ($template['context'] ?? []) as $label => $value) {
            if (!is_string($label) || !is_string($value)) {
                continue;
            }

            $rendered = trim($this->renderTemplateString($value, $variables));
            if ($rendered !== '') {
                $context[$label] = $rendered;
            }
        }

        return [
            'title' => trim($title),
            'lines' => $lines,
            'context' => $context,
        ];
    }

    public function buildText(string $title, array $lines = [], array $context = []): string
    {
        $segments = [];
        $title = trim($title);

        if ($title !== '') {
            $segments[] = '*' . $title . '*';
        }

        $bodyLines = array_values(array_filter(array_map(
            static fn (mixed $value): string => is_string($value) ? trim($value) : '',
            $lines
        )));

        if ($bodyLines !== []) {
            $segments[] = implode("\n", $bodyLines);
        }

        $contextLines = [];
        foreach ($context as $label => $value) {
            if (!is_scalar($value) && $value !== null) {
                continue;
            }

            $normalizedLabel = trim((string) $label);
            $normalizedValue = trim((string) ($value ?? ''));
            if ($normalizedLabel === '' || $normalizedValue === '') {
                continue;
            }

            $contextLines[] = sprintf('%s: %s', $normalizedLabel, $normalizedValue);
        }

        if ($contextLines !== []) {
            $segments[] = implode("\n", $contextLines);
        }

        return trim(implode("\n\n", $segments));
    }

    /**
     * @param array<string, scalar|null> $variables
     */
    private function renderTemplateString(string $value, array $variables): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function (array $matches) use ($variables): string {
            $key = $matches[1] ?? '';
            $replacement = $variables[$key] ?? '';

            if (!is_scalar($replacement) && $replacement !== null) {
                return '';
            }

            return trim((string) ($replacement ?? ''));
        }, $value);
    }
}
