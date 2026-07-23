<?php

namespace App\Services\Mutations;

use App\Services\Import\OfficeImportService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * mutation API 的「刪除官職實體」handler（resource=office、operation=delete）。
 * 委派 OfficeImportService::delete()：先刪 OFFICE_CODE_TYPE_REL 各行、再刪 OFFICE_CODES。
 *
 * 安全護欄：仍被人物任官（POSTED_TO_OFFICE_DATA）引用者不可刪，回 409，避免製造孤兒外鍵。
 * target.pk 須帶 c_office_id；不存在回 404。person_id 對本資源無意義（僅記入 operations）。
 */
class OfficeDeleteHandler extends AbstractMutationHandler {
    public function __construct(protected OfficeImportService $service) {
    }

    public function supports(string $resource, string $mode, string $operation): bool {
        return $operation === 'delete'
            && $mode === 'direct'
            && in_array($resource, ['office', 'offices'], true);
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authError = $this->authorizeDirect();
        if ($authError) {
            return $authError;
        }

        $officeId = $this->scalarOrNull($targetPk['c_office_id'] ?? $targetPk['office_id'] ?? null);
        if ($officeId === null || $officeId === '' || !ctype_digit((string) $officeId)) {
            return $this->errorResponse('target.pk 缺少有效的 c_office_id', 422, ['c_office_id' => ['required_integer']]);
        }
        $officeId = (int) $officeId;

        if ($this->service->load($officeId) === null) {
            return $this->errorResponse('找不到官職', 404, ['c_office_id' => ['not_found']]);
        }

        $refCount = $this->service->referenceCount($officeId);
        if ($refCount > 0) {
            return $this->errorResponse(
                "此官職仍被 {$refCount} 筆人物任官引用，無法刪除",
                409,
                ['c_office_id' => ['referenced_by_postings'], 'reference_count' => [$refCount]]
            );
        }

        try {
            $result = DB::transaction(fn () => $this->service->delete($officeId, $personId));
        } catch (QueryException $e) {
            // OFFICE_CODES 入邊外鍵已翻成 ON DELETE RESTRICT（去級聯 Phase 1 批次 3）：
            // referenceCount() 只擋 POSTED_TO_OFFICE_DATA，若仍有漏網引用（如 POSTED_TO_ADDR_DATA
            // 殘留列），DELETE 會被 DB 以 1451 擋下、交易回滾（含 operations 記錄）。
            // fail-closed、零資料損失，這裡轉為友好訊息。
            if (($e->errorInfo[1] ?? null) !== 1451) {
                throw $e;
            }

            return $this->errorResponse(
                '此官職仍被其他資料引用（如任官地址），無法刪除。請先移除引用後再試。',
                409,
                ['c_office_id' => ['referenced_by_other_records']]
            );
        }

        return response()->json([
            'ok' => true,
            'resource' => 'office',
            'mode' => 'direct',
            'operation' => 'delete',
            'result' => [
                'pk' => ['c_office_id' => $officeId],
                'status' => 'deleted',
                'operation_id' => $result['operation_id_office'],
                'rel_deleted' => $result['rel_deleted'],
            ],
        ]);
    }
}
