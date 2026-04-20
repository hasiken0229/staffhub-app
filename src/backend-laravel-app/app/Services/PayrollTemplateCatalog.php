<?php

namespace App\Services;

final class PayrollTemplateCatalog
{
    private const TYPE_CONFIG = [
        'PAYROLL' => [
            'title' => '給与明細',
            'defaultDefinitionName' => '標準給与テンプレート',
            'sampleFileName' => 'r8.3kyuyo.csv',
            'headers' => [
                '社員番号', '姓', '名', '基本給', '管理職手当', '特殊業務手当', '調整手当', '扶養手当', '特別手当',
                '超過勤務手当', '住宅手当', '給与差額', '減　額', '人勧差額', '休日出勤', '主幹手当', '業務責任手当',
                '通勤手当', '保育海外研修', '基礎分手当', '質の向上手当', '賃金改善手当', '非課税支給計', '課税支給額計',
                '総支給額', '健康保険', '厚生年金', '雇用保険', '社保調整', '社会保険合計', '課税対象額', '所得税',
                '住民税', '共済会', '食　費', '財　形', '保育海外研修', '控除額計', '差引支給', '振込支給',
            ],
            'sampleRow' => [
                '101', '橋本', '良孝', '195000', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0',
                '0', '0', '0', '0', '0', '0', '195000', '195000', '10240', '0', '0', '0', '10240', '184760', '2180',
                '10500', '3060', '7000', '0', '0', '32980', '162020', '162020',
            ],
            'payItems' => [
                ['label' => '基本給', 'source' => '基本給'],
                ['label' => '管理職手当', 'source' => '管理職手当'],
                ['label' => '特殊業務手当', 'source' => '特殊業務手当'],
                ['label' => '調整手当', 'source' => '調整手当'],
                ['label' => '扶養手当', 'source' => '扶養手当'],
                ['label' => '特別手当', 'source' => '特別手当'],
                ['label' => '超過勤務手当', 'source' => '超過勤務手当'],
                ['label' => '住宅手当', 'source' => '住宅手当'],
                ['label' => '給与差額', 'source' => '給与差額'],
                ['label' => '減額', 'source' => '減　額'],
                ['label' => '人勧差額', 'source' => '人勧差額'],
                ['label' => '休日出勤', 'source' => '休日出勤'],
                ['label' => '主幹手当', 'source' => '主幹手当'],
                ['label' => '業務責任手当', 'source' => '業務責任手当'],
                ['label' => '通勤手当', 'source' => '通勤手当'],
                ['label' => '保育海外研修', 'source' => '保育海外研修', 'occurrence' => 0],
                ['label' => '基礎分手当', 'source' => '基礎分手当'],
                ['label' => '質の向上手当', 'source' => '質の向上手当'],
                ['label' => '賃金改善手当', 'source' => '賃金改善手当'],
            ],
            'deductionItems' => [
                ['label' => '健康保険', 'source' => '健康保険'],
                ['label' => '厚生年金', 'source' => '厚生年金'],
                ['label' => '雇用保険', 'source' => '雇用保険'],
                ['label' => '社保調整', 'source' => '社保調整'],
                ['label' => '所得税', 'source' => '所得税'],
                ['label' => '住民税', 'source' => '住民税'],
                ['label' => '共済会', 'source' => '共済会'],
                ['label' => '食費', 'source' => '食　費'],
                ['label' => '財形', 'source' => '財　形'],
                ['label' => '保育海外研修', 'source' => '保育海外研修', 'occurrence' => 1],
            ],
            'summaryItems' => [
                ['label' => '非課税支給計', 'source' => '非課税支給計'],
                ['label' => '課税支給額計', 'source' => '課税支給額計'],
                ['label' => '総支給額', 'source' => '総支給額'],
                ['label' => '社会保険合計', 'source' => '社会保険合計'],
                ['label' => '課税対象額', 'source' => '課税対象額'],
                ['label' => '控除額計', 'source' => '控除額計'],
                ['label' => '差引支給', 'source' => '差引支給'],
                ['label' => '振込支給', 'source' => '振込支給'],
            ],
        ],
        'BONUS' => [
            'title' => '賞与明細',
            'defaultDefinitionName' => '標準賞与テンプレート',
            'sampleFileName' => 'r8.3syoyo.csv',
            'headers' => [
                '社員番号', '姓', '名', '賞　　与', '調整', '人勧改定分', '質の向上手当', '賃金改善手当', '特別手当',
                '課税支給額', '総支給額', '健康保険', '厚生年金', '雇用保険', '社会保険合計', '課税対象額',
                '所得税', '控除額計', '差引支給', '振込支給',
            ],
            'sampleRow' => [
                '103', '川野', '洋子', '0', '0', '510000', '50000', '350000', '0', '910000', '910000', '53462', '83265',
                '5005', '141732', '768268', '62752', '204484', '705516', '705516',
            ],
            'payItems' => [
                ['label' => '賞与', 'source' => '賞　　与'],
                ['label' => '調整', 'source' => '調整'],
                ['label' => '人勧改定分', 'source' => '人勧改定分'],
                ['label' => '質の向上手当', 'source' => '質の向上手当'],
                ['label' => '賃金改善手当', 'source' => '賃金改善手当'],
                ['label' => '特別手当', 'source' => '特別手当'],
            ],
            'deductionItems' => [
                ['label' => '健康保険', 'source' => '健康保険'],
                ['label' => '厚生年金', 'source' => '厚生年金'],
                ['label' => '雇用保険', 'source' => '雇用保険'],
                ['label' => '所得税', 'source' => '所得税'],
            ],
            'summaryItems' => [
                ['label' => '課税支給額', 'source' => '課税支給額'],
                ['label' => '総支給額', 'source' => '総支給額'],
                ['label' => '社会保険合計', 'source' => '社会保険合計'],
                ['label' => '課税対象額', 'source' => '課税対象額'],
                ['label' => '控除額計', 'source' => '控除額計'],
                ['label' => '差引支給', 'source' => '差引支給'],
                ['label' => '振込支給', 'source' => '振込支給'],
            ],
        ],
    ];

    public function normalizeStatementType(string $statementType): string
    {
        $normalized = strtoupper($statementType);
        if (!isset(self::TYPE_CONFIG[$normalized])) {
            throw new ApiException('VALIDATION_ERROR', '明細種別が不正です。', 422, [
                ['field' => 'statementType', 'message' => 'PAYROLL または BONUS を指定してください。'],
            ]);
        }

        return $normalized;
    }

    public function config(string $statementType): array
    {
        return self::TYPE_CONFIG[$this->normalizeStatementType($statementType)];
    }

    public function title(string $statementType): string
    {
        return $this->config($statementType)['title'];
    }

    public function defaultDefinitionName(string $statementType): string
    {
        return $this->config($statementType)['defaultDefinitionName'];
    }

    public function templateVersion(string $statementType): int
    {
        return 1;
    }

    public function sampleFileName(string $statementType): string
    {
        return $this->config($statementType)['sampleFileName'];
    }

    public function headers(string $statementType): array
    {
        return $this->config($statementType)['headers'];
    }

    public function sampleRows(string $statementType): array
    {
        $config = $this->config($statementType);

        return [
            $config['headers'],
            $config['sampleRow'],
        ];
    }

    public function fieldCount(string $statementType): int
    {
        return count($this->headers($statementType));
    }

    public function payItems(string $statementType): array
    {
        return $this->config($statementType)['payItems'];
    }

    public function deductionItems(string $statementType): array
    {
        return $this->config($statementType)['deductionItems'];
    }

    public function summaryItems(string $statementType): array
    {
        return $this->config($statementType)['summaryItems'];
    }

    public function buildLines(string $statementType, array $statement): array
    {
        $normalized = $this->normalizeStatementType($statementType);
        $lines = [];
        $displayOrder = 1;

        foreach (($statement['payItems'] ?? []) as $item) {
            $lines[] = [
                'sectionType' => 'PAY',
                'displayOrder' => $displayOrder++,
                'itemLabel' => $item['label'],
                'amount' => (float) $item['amount'],
                'rawSourceKey' => $item['sourceKey'] ?? $item['label'],
            ];
        }

        $displayOrder = 1;
        foreach (($statement['deductionItems'] ?? []) as $item) {
            $lines[] = [
                'sectionType' => 'DEDUCTION',
                'displayOrder' => $displayOrder++,
                'itemLabel' => $item['label'],
                'amount' => (float) $item['amount'],
                'rawSourceKey' => $item['sourceKey'] ?? $item['label'],
            ];
        }

        $displayOrder = 1;
        foreach (($statement['summaryItems'] ?? []) as $item) {
            $lines[] = [
                'sectionType' => 'SUMMARY',
                'displayOrder' => $displayOrder++,
                'itemLabel' => $item['label'],
                'amount' => (float) $item['amount'],
                'rawSourceKey' => $item['sourceKey'] ?? $item['label'],
            ];
        }

        if (!empty($statement['remarks'])) {
            $lines[] = [
                'sectionType' => 'OTHER',
                'displayOrder' => 1,
                'itemLabel' => $normalized === 'BONUS' ? '備考' : '備考',
                'amount' => 0,
                'rawSourceKey' => 'remarks',
            ];
        }

        return $lines;
    }

    public function toCsvString(string $statementType): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('テンプレートCSVを生成できません。');
        }

        foreach ($this->sampleRows($statementType) as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return mb_convert_encoding($csv, 'SJIS-win', 'UTF-8');
    }
}
