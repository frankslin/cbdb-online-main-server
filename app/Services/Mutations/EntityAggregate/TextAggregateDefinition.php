<?php

namespace App\Services\Mutations\EntityAggregate;

use App\Services\Import\EntityAggregateService;
use App\Services\Import\TextImportService;
use App\Services\Mutations\Concerns\ResolvesTextAggregateInput;

/**
 * 「文獻實體」的聚合定義（resource=text-entity）：TEXT_CODES ＋ TEXT_INSTANCE_DATA 版本列。
 *
 * resource 刻意不叫 text／texts——那兩個是人物子資源 BIOG_TEXT_DATA（著述）的既有別名
 * （TextMutationHandler），不可重載。create／update 共用 ResolvesTextAggregateInput 校驗
 * （必填僅 title，create／update 一致）。
 *
 * 護欄（文獻的 c_source 自引用層級，見 TextImportService 類註）：
 *  - update：改 c_source 為自己或自己的後代（成環）回 422；
 *  - delete：被人物出處／著述、其他表 c_source、子文獻或其他文獻版本引用時回 409。
 */
class TextAggregateDefinition extends AbstractEntityAggregateDefinition {
    use ResolvesTextAggregateInput;

    public function __construct(protected TextImportService $textService) {
    }

    public function resources(): array {
        return ['text-entity', 'text-entities', 'book', 'books'];
    }

    public function operations(): array {
        return ['create', 'update', 'delete'];
    }

    public function pkField(): string {
        return 'c_textid';
    }

    public function resourceName(): string {
        return 'text-entity';
    }

    public function notFoundMessage(): string {
        return '找不到文獻';
    }

    public function service(): EntityAggregateService {
        return $this->textService;
    }

    public function validate(string $operation, array $changes): array {
        return $this->validateTextAggregate($changes, $this->textService);
    }

    public function guardWrite(string $operation, ?int $id, array $input, ?array $existing): ?array {
        if ($operation === 'update' && ($input['source_id'] ?? null) !== null) {
            // 成環護欄：c_source 是 TEXT_CODES 自引用（著錄來源樹），指向自己或自己的
            // 後代會讓樹成環（上溯查詢死循環、層級語義損毀）。
            if ($this->textService->sourceCreatesCycle((int) $id, (int) $input['source_id'])) {
                return [
                    '來源文獻不可為此文獻自身或其後代（會使著錄來源樹成環）',
                    422,
                    ['source_id' => ['source_cycle']],
                ];
            }
        }

        if ($operation === 'delete') {
            $refCount = $this->textService->referenceCount((int) $id);
            if ($refCount > 0) {
                return [
                    "此文獻仍被 {$refCount} 筆資料引用（人物出處／著述、其他記錄的來源、或子文獻），無法刪除",
                    409,
                    ['c_textid' => ['referenced_by_other_records'], 'reference_count' => [$refCount]],
                ];
            }
        }

        return null;
    }

    public function result(string $operation, ?int $id, array $input, array $serviceResult): array {
        if ($operation === 'create') {
            return [
                'pk' => ['c_textid' => $serviceResult['textid']],
                'status' => 'created',
                'operation_id' => $serviceResult['operation_id_text'],
                'instances_added' => $serviceResult['instances_added'],
                'variant_replacements' => $serviceResult['variant_replacements'],
                'row' => [
                    'c_textid' => $serviceResult['textid'],
                    'c_title_chn' => $serviceResult['title'],
                    'c_title' => $serviceResult['title_pinyin'],
                ],
            ];
        }

        if ($operation === 'update') {
            return [
                'pk' => ['c_textid' => $id],
                'status' => 'updated',
                'operation_id' => $serviceResult['operation_id_text'],
                'instances_added' => $serviceResult['instances_added'],
                'instances_removed' => $serviceResult['instances_removed'],
                'instances_updated' => $serviceResult['instances_updated'],
                'row' => [
                    'c_textid' => $id,
                    'c_title_chn' => $serviceResult['title'],
                    'c_title' => $serviceResult['title_pinyin'],
                ],
            ];
        }

        // delete
        return [
            'pk' => ['c_textid' => $id],
            'status' => 'deleted',
            'operation_id' => $serviceResult['operation_id_text'],
            'instances_deleted' => $serviceResult['instances_deleted'],
        ];
    }
}
