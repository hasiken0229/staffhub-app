<?php

namespace App\Services;

use Closure;
use Throwable;

final class BatchOperation
{
    public static function run(array $ids, Closure $operation): array
    {
        $items = $errors = [];
        foreach ($ids as $id) {
            try { $items[] = $operation($id); }
            catch (ApiException $exception) {
                $errors[] = ['id' => $id, 'code' => $exception->errorCode, 'message' => $exception->getMessage(), 'status' => 'FAILED'];
            } catch (Throwable $exception) {
                report($exception);
                $errors[] = ['id' => $id, 'code' => 'RESULT_UNKNOWN', 'status' => 'UNKNOWN',
                    'message' => '処理結果を確認できません。再実行する前に対象の状態と履歴を確認してください。'];
            }
        }
        return ['updatedCount' => count(array_filter($items, fn ($item) => !($item['unchanged'] ?? false))),
            'unchangedCount' => count(array_filter($items, fn ($item) => $item['unchanged'] ?? false)),
            'items' => $items, 'errorCount' => count($errors), 'errors' => $errors];
    }
}
