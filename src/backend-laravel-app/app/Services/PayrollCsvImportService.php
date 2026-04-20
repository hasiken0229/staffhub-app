<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use TCPDF;

final class PayrollCsvImportService
{
    public function __construct(private readonly PayrollTemplateCatalog $catalog)
    {
    }

    public function prepareRows(UploadedFile $file, string $statementType, array $expectedHeaders = []): array
    {
        $normalizedType = $this->catalog->normalizeStatementType($statementType);
        $rows = $this->readCsvRows($file);
        if (count($rows) < 2) {
            throw new ApiException('CSV_FORMAT_ERROR', 'CSVに明細データがありません。', 422);
        }

        $header = array_map([$this, 'normalizeString'], $rows[0]);
        $normalizedExpectedHeaders = array_map([$this, 'normalizeString'], $expectedHeaders);

        if ($normalizedExpectedHeaders !== [] && $header !== $normalizedExpectedHeaders) {
            throw new ApiException('CSV_FORMAT_ERROR', '選択したCSV定義と一致しないため登録できません。', 422, [
                ['field' => 'file', 'message' => '定義の列名・列順と一致するCSVを選択してください。'],
            ]);
        }

        $headerIndexMap = $this->buildHeaderIndexMap($header);

        $requiredHeaders = ['社員番号', '姓', '名'];
        foreach ([
            $this->catalog->payItems($normalizedType),
            $this->catalog->deductionItems($normalizedType),
            $this->catalog->summaryItems($normalizedType),
        ] as $section) {
            foreach ($section as $item) {
                $requiredHeaders[] = $item['source'];
            }
        }

        $missingHeaders = [];
        foreach (array_unique($requiredHeaders) as $requiredHeader) {
            if (!isset($headerIndexMap[$requiredHeader])) {
                $missingHeaders[] = $requiredHeader;
            }
        }

        if ($missingHeaders !== []) {
            throw new ApiException('CSV_FORMAT_ERROR', 'CSVヘッダーが不足しています。', 422, [
                ['field' => 'file', 'message' => '不足ヘッダー: ' . implode(', ', $missingHeaders)],
            ]);
        }

        if ($normalizedExpectedHeaders !== []) {
            $expectedFieldCount = count($normalizedExpectedHeaders);
            foreach (array_slice($rows, 1) as $index => $row) {
                if ($this->rowIsEmpty($row)) {
                    continue;
                }

                if (count($row) !== $expectedFieldCount) {
                    throw new ApiException('CSV_FORMAT_ERROR', 'CSVの列数が定義と一致しない行があります。', 422, [
                        ['field' => 'file', 'message' => ($index + 2) . '行目の列数が定義と一致しません。'],
                    ]);
                }
            }
        }

        return [
            'rows' => $rows,
            'header' => $header,
            'headerIndexMap' => $headerIndexMap,
        ];
    }

    public function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($this->normalizeString($value) !== '') {
                return false;
            }
        }

        return true;
    }

    public function employeeCode(array $row, array $headerIndexMap): string
    {
        return $this->cell($row, $headerIndexMap, '社員番号');
    }

    public function buildStatementPayload(
        string $statementType,
        string $targetYearMonth,
        array $row,
        array $headerIndexMap,
        object $employee,
        ?string $remarks = null,
    ): array {
        $normalizedType = $this->catalog->normalizeStatementType($statementType);

        $payItems = $this->extractItems($row, $headerIndexMap, $this->catalog->payItems($normalizedType));
        $deductionItems = $this->extractItems($row, $headerIndexMap, $this->catalog->deductionItems($normalizedType));
        $summaryItems = $this->extractItems($row, $headerIndexMap, $this->catalog->summaryItems($normalizedType), false);
        $employeeName = trim($this->cell($row, $headerIndexMap, '姓') . ' ' . $this->cell($row, $headerIndexMap, '名'));

        $summaryBySource = [];
        foreach ($summaryItems as $item) {
            $summaryBySource[(string) $item['sourceKey']] = (float) $item['amount'];
        }

        return [
            'statementType' => $normalizedType,
            'statementTypeLabel' => $this->catalog->title($normalizedType),
            'targetYearMonth' => $targetYearMonth,
            'employeeCode' => (string) $employee->employee_code,
            'employeeName' => $employeeName !== '' ? $employeeName : (string) $employee->name,
            'payItems' => $payItems,
            'deductionItems' => $deductionItems,
            'summaryItems' => $summaryItems,
            'remarks' => $remarks,
            'grossAmount' => (float) ($summaryBySource['総支給額'] ?? 0),
            'deductionAmount' => (float) ($summaryBySource['控除額計'] ?? 0),
            'netAmount' => (float) (($summaryBySource['差引支給'] ?? $summaryBySource['振込支給'] ?? 0)),
        ];
    }

    public function renderPdf(array $statement): string
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('勤怠管理');
        $pdf->SetAuthor('勤怠管理');
        $pdf->SetTitle((string) $statement['statementTypeLabel']);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->setFontSubsetting(true);
        $pdf->AddPage();
        $pdf->SetFont('kozminproregular', '', 9.5);
        $pdf->writeHTML($this->buildPdfHtml($statement), true, false, true, false, '');

        return $pdf->Output('', 'S');
    }

    public function makeOriginalFileName(string $statementType, string $targetYearMonth, string $employeeCode): string
    {
        $normalizedType = $this->catalog->normalizeStatementType($statementType);

        return strtolower($normalizedType) . '_statement_' . $targetYearMonth . '_' . $employeeCode . '.pdf';
    }

    private function readCsvRows(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());
        if ($content === false) {
            throw new ApiException('FILE_UPLOAD_ERROR', 'CSVファイルを読み取れません。', 422);
        }

        $utf8Content = $this->convertCsvContentToUtf8($content);
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new ApiException('FILE_UPLOAD_ERROR', 'CSVファイルを読み取れません。', 422);
        }

        fwrite($handle, $utf8Content);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_map([$this, 'normalizeString'], $row);
        }

        fclose($handle);

        return $rows;
    }

    private function normalizeString(?string $value): string
    {
        $value ??= '';
        $value = preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;
        return trim($value);
    }

    private function buildHeaderIndexMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $columnName) {
            $map[$columnName] ??= [];
            $map[$columnName][] = $index;
        }

        return $map;
    }

    private function cell(array $row, array $headerIndexMap, string $header, int $occurrence = 0): string
    {
        $indexes = $headerIndexMap[$header] ?? [];
        $index = $indexes[$occurrence] ?? null;
        if ($index === null) {
            return '';
        }

        return $this->normalizeString($row[$index] ?? '');
    }

    private function extractItems(array $row, array $headerIndexMap, array $config, bool $hideZero = true): array
    {
        $items = [];

        foreach ($config as $itemConfig) {
            $rawValue = $this->cell(
                $row,
                $headerIndexMap,
                $itemConfig['source'],
                (int) ($itemConfig['occurrence'] ?? 0)
            );
            $amount = $this->parseAmount($rawValue);

            if ($hideZero && abs($amount) < 0.0001) {
                continue;
            }

            $items[] = [
                'label' => $itemConfig['label'],
                'amount' => $amount,
                'formattedAmount' => $this->formatAmount($amount),
                'sourceKey' => $itemConfig['source'],
            ];
        }

        return $items;
    }

    private function parseAmount(string $rawValue): float
    {
        $normalized = str_replace([',', ' '], '', $rawValue);
        if ($normalized === '') {
            return 0.0;
        }

        return (float) $normalized;
    }

    private function formatAmount(float $amount): string
    {
        if (abs($amount - round($amount)) < 0.0001) {
            return number_format((int) round($amount));
        }

        return number_format($amount, 2, '.', ',');
    }

    private function buildPdfHtml(array $statement): string
    {
        $title = $this->escape((string) $statement['statementTypeLabel']);
        $yearMonth = $this->escape((string) $statement['targetYearMonth']);
        $employeeCode = $this->escape((string) $statement['employeeCode']);
        $employeeName = $this->escape((string) $statement['employeeName']);

        $payRows = $this->buildTableRows((array) $statement['payItems']);
        $deductionRows = $this->buildTableRows((array) $statement['deductionItems']);
        $summaryRows = $this->buildTableRows((array) $statement['summaryItems']);
        $remarks = trim((string) ($statement['remarks'] ?? ''));
        $remarksHtml = $remarks !== '' ? '<br /><table class="box" width="100%" cellpadding="0" cellspacing="0"><tr><th>備考</th></tr><tr><td>' . $this->escape($remarks) . '</td></tr></table>' : '';

        return <<<HTML
<style>
    h1 { font-size: 18px; text-align: center; margin-bottom: 10px; }
    .meta-table td { border: 1px solid #666; padding: 4px 6px; font-size: 9px; }
    .box { border: 1px solid #666; }
    .box th, .box td { border: 1px solid #999; padding: 4px 6px; font-size: 8.5px; }
    .box th { background-color: #f5f5f5; }
    .amount { text-align: right; }
</style>
<h1>{$title}</h1>
<table class="meta-table" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td width="20%">対象年月</td>
        <td width="30%">{$yearMonth}</td>
        <td width="20%">社員番号</td>
        <td width="30%">{$employeeCode}</td>
    </tr>
    <tr>
        <td>氏名</td>
        <td colspan="3">{$employeeName}</td>
    </tr>
</table>
<br />
<table width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td width="49%" valign="top">
            <table class="box" width="100%" cellpadding="0" cellspacing="0">
                <tr><th colspan="2">支給項目</th></tr>
                {$payRows}
            </table>
        </td>
        <td width="2%"></td>
        <td width="49%" valign="top">
            <table class="box" width="100%" cellpadding="0" cellspacing="0">
                <tr><th colspan="2">控除項目</th></tr>
                {$deductionRows}
            </table>
        </td>
    </tr>
</table>
<br />
<table class="box" width="100%" cellpadding="0" cellspacing="0">
    <tr><th colspan="2">集計</th></tr>
    {$summaryRows}
</table>
{$remarksHtml}
HTML;
    }

    private function buildTableRows(array $items): string
    {
        if ($items === []) {
            return '<tr><td colspan="2">該当なし</td></tr>';
        }

        $rows = [];
        foreach ($items as $item) {
            $label = $this->escape((string) $item['label']);
            $amount = $this->escape((string) $item['formattedAmount']);
            $rows[] = "<tr><td width=\"65%\">{$label}</td><td width=\"35%\" class=\"amount\">{$amount}</td></tr>";
        }

        return implode('', $rows);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function convertCsvContentToUtf8(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }

        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        $detected = mb_detect_encoding($content, ['CP932', 'SJIS-win', 'UTF-8', 'ASCII'], true);
        $source = $detected ?: 'SJIS-win';

        return mb_convert_encoding($content, 'UTF-8', $source);
    }
}
