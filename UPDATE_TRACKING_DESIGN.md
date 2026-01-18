# Updates 记录系统设计方案

## 一、问题分析

### 1.1 现有 operations 表的局限

```sql
-- 当前结构
CREATE TABLE `operations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `c_personid` int NOT NULL,
  `op_type` smallint NOT NULL,
  `resource` varchar(255) NOT NULL,
  `resource_id` varchar(255) NOT NULL DEFAULT '',
  `resource_data` longtext NOT NULL,           -- 整个资源的 JSON
  `resource_original` longtext,                -- 修改前的 JSON
  ...
) ENGINE=InnoDB;
```

**问题**：
- ❌ 无法追踪单个字段的修改历史
- ❌ 批量操作（如合并人物）的多条记录没有关联
- ❌ 查询某字段历史需要解析所有 JSON
- ❌ 无法统计"哪个字段被修改最频繁"

### 1.2 合并人物场景的需求

```php
// 一次合并可能产生的操作：
UPDATE BIOG_MAIN SET c_name_chn = ..., c_notes = ... WHERE c_personid = 123;
UPDATE ALTNAME_DATA SET c_personid = 123 WHERE c_personid = 456;
UPDATE KIN_DATA SET c_personid = 123 WHERE c_personid = 456;
UPDATE ASSOC_DATA SET c_personid = 123 WHERE c_personid = 456;
// ... 十几个表的更新
INSERT INTO MERGED_PERSON_DATA (c_personid, c_merged_from_personid, ...) VALUES (123, 456, ...);
```

**需求**：
1. 记录这些操作来自同一次"合并人物"批量操作
2. 追踪每个字段的变化（如 `c_notes` 从 "原备注" 变为 "原备注\\n[merged #123 and #456]"）
3. 能够查询"某次合并修改了哪些表、哪些字段"
4. 保持与现有 operations 表的兼容

## 二、设计方案：三层追踪系统

### 2.1 架构概览

```
Level 1: operation_batches    (批量操作层)
         └─> operations        (用户操作层，现有表增强)
              └─> field_changes (字段变更层，新增)
```

### 2.2 数据库表设计

#### Table 1: `operation_batches` (新增)

记录批量操作，一次批量操作可包含多个 operations。

```sql
CREATE TABLE `operation_batches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `batch_type` varchar(50) NOT NULL COMMENT '批量类型: merge_person, bulk_import, batch_update, etc.',
  `user_id` int NOT NULL COMMENT '执行用户ID',
  `title` varchar(255) DEFAULT NULL COMMENT '批量操作标题（用于显示）',
  `description` text COMMENT '批量操作描述',
  `metadata` json COMMENT '扩展元数据',
  `status` varchar(20) DEFAULT 'completed' COMMENT 'pending, completed, failed, rolled_back',
  `operations_count` int DEFAULT 0 COMMENT '包含的操作数量（冗余字段，方便查询）',
  `created_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_batch_type` (`batch_type`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='批量操作记录';
```

**metadata JSON 示例**（合并人物）：
```json
{
  "primary_personid": 123,
  "secondary_personid": 456,
  "merge_reason": "同一人物，资料重复",
  "auto_arrange": true,
  "affected_tables": ["BIOG_MAIN", "ALTNAME_DATA", "KIN_DATA", "ASSOC_DATA", "POSTED_TO_OFFICE_DATA", "MERGED_PERSON_DATA"],
  "preview_url": "https://example.com/merge-preview?primary_id=123&secondary_id=456"
}
```

#### Table 2: `operations` (修改：增加 batch_id)

```sql
-- 在现有 operations 表增加一个外键字段
ALTER TABLE `operations`
ADD COLUMN `batch_id` bigint unsigned DEFAULT NULL COMMENT '所属批量操作ID（为NULL表示单独操作）' AFTER `id`,
ADD KEY `idx_batch_id` (`batch_id`),
ADD CONSTRAINT `fk_operations_batch`
  FOREIGN KEY (`batch_id`)
  REFERENCES `operation_batches`(`id`)
  ON DELETE SET NULL
  ON UPDATE CASCADE;
```

**向后兼容性**：
- ✅ `batch_id` 允许为 NULL，现有单独操作不受影响
- ✅ 现有代码无需修改即可继续工作
- ✅ 只在需要批量追踪时才设置 `batch_id`

#### Table 3: `field_changes` (新增)

记录字段级别的变更，每条记录代表一个字段的一次修改。

```sql
CREATE TABLE `field_changes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `operation_id` int unsigned NOT NULL COMMENT '关联的 operation ID',
  `table_name` varchar(100) NOT NULL COMMENT '数据表名',
  `record_key` json NOT NULL COMMENT '记录主键（复合主键用JSON: {"c_personid":123,"c_sequence":1}）',
  `field_name` varchar(100) NOT NULL COMMENT '字段名',
  `old_value` text COMMENT '修改前的值（NULL 表示新增字段）',
  `new_value` text COMMENT '修改后的值（NULL 表示删除字段）',
  `value_type` varchar(20) DEFAULT 'string' COMMENT '值类型: string, int, float, json, date, null',
  `change_type` varchar(20) NOT NULL COMMENT '变更类型: created, updated, deleted',
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_operation_id` (`operation_id`),
  KEY `idx_table_field` (`table_name`, `field_name`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_field_changes_operation`
    FOREIGN KEY (`operation_id`)
    REFERENCES `operations`(`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='字段级变更记录';
```

**record_key JSON 示例**：
```json
// 单主键表
{"c_personid": 123}

// 复合主键表（如 ALTNAME_DATA）
{"c_personid": 123, "c_sequence": 1, "c_alt_name_chn": "李白", "c_alt_name_type_code": 2}

// 任官地址表（复合主键）
{"c_personid": 123, "c_posting_id": 5, "c_office_id": 10, "c_addr_id": 20}
```

### 2.3 索引策略

```sql
-- operation_batches 索引
CREATE INDEX idx_batch_user_time ON operation_batches(user_id, created_at DESC);
CREATE INDEX idx_batch_type_status ON operation_batches(batch_type, status);

-- operations 索引（已有的保持，新增 batch_id）
-- 已有: idx_c_personid
CREATE INDEX idx_batch_created ON operations(batch_id, created_at DESC);

-- field_changes 索引
CREATE INDEX idx_field_changes_composite ON field_changes(table_name, field_name, created_at DESC);
CREATE INDEX idx_field_changes_operation ON field_changes(operation_id);
```

## 三、实现方式

### 3.1 Repository 增强

#### 新增 `OperationBatchRepository.php`

```php
<?php

namespace App\Repositories;

use App\Models\OperationBatch;
use Illuminate\Support\Facades\DB;

class OperationBatchRepository {
    /**
     * 创建批量操作记录
     */
    public function create(string $batchType, int $userId, array $metadata = [], ?string $title = null, ?string $description = null): OperationBatch {
        return OperationBatch::create([
            'batch_type' => $batchType,
            'user_id' => $userId,
            'title' => $title,
            'description' => $description,
            'metadata' => $metadata,
            'status' => 'pending',
            'operations_count' => 0,
        ]);
    }

    /**
     * 完成批量操作
     */
    public function complete(int $batchId, int $operationsCount): void {
        DB::table('operation_batches')
            ->where('id', $batchId)
            ->update([
                'status' => 'completed',
                'operations_count' => $operationsCount,
                'completed_at' => now(),
            ]);
    }

    /**
     * 标记批量操作失败
     */
    public function markFailed(int $batchId, ?string $errorMessage = null): void {
        $metadata = DB::table('operation_batches')
            ->where('id', $batchId)
            ->value('metadata');

        $metadataArray = json_decode($metadata, true) ?? [];
        $metadataArray['error'] = $errorMessage;

        DB::table('operation_batches')
            ->where('id', $batchId)
            ->update([
                'status' => 'failed',
                'metadata' => json_encode($metadataArray),
                'completed_at' => now(),
            ]);
    }
}
```

#### 增强 `OperationRepository.php`

```php
<?php

namespace App\Repositories;

use App\Models\Operation;
use Illuminate\Support\Facades\DB;

class OperationRepository {
    /**
     * 存储操作记录（增强版，支持 batch_id 和字段级追踪）
     *
     * @param int|null $batchId 批量操作ID（可选）
     * @param bool $trackFields 是否追踪字段级变更
     */
    public function store(
        $user_id,
        $c_personid,
        $op_type,
        $resource,
        $resource_id,
        $resource_data,
        $ori = '',
        $crowdsourcing_status = 0,
        $batchId = null,
        $trackFields = false
    ) {
        $operation = new Operation();
        $operation->batch_id = $batchId;
        $operation->user_id = $user_id;
        $operation->c_personid = $c_personid;
        $operation->op_type = $op_type;
        $operation->resource = $resource;
        $operation->resource_id = $resource_id;
        $operation->resource_data = json_encode($resource_data, JSON_UNESCAPED_UNICODE);

        if (!empty($ori)) {
            $operation->resource_original = json_encode($ori, JSON_UNESCAPED_UNICODE);
        }

        if ($crowdsourcing_status != 0) {
            $operation->crowdsourcing_status = $crowdsourcing_status;
        }

        $operation->save();

        // 如果启用字段级追踪，生成 field_changes 记录
        if ($trackFields && !empty($ori)) {
            $this->generateFieldChanges($operation->id, $resource, $resource_id, $resource_data, $ori);
        }

        return $operation;
    }

    /**
     * 生成字段级变更记录
     */
    protected function generateFieldChanges(int $operationId, string $tableName, string $resourceId, array $newData, $oldData): void {
        $oldArray = is_array($oldData) ? $oldData : (is_object($oldData) ? (array)$oldData : []);

        // 解析 record_key
        $recordKey = $this->parseRecordKey($tableName, $resourceId, $newData, $oldArray);

        // 需要忽略的字段（Laravel 相关字段）
        $ignoreFields = ['_method', '_token', 'created_at', 'updated_at'];

        // 比对字段
        $allFields = array_unique(array_merge(array_keys($newData), array_keys($oldArray)));

        $changes = [];
        foreach ($allFields as $field) {
            if (in_array($field, $ignoreFields) || strpos($field, '__') === 0) {
                continue;
            }

            $oldValue = $oldArray[$field] ?? null;
            $newValue = $newData[$field] ?? null;

            // 标准化值（空字符串视为 NULL）
            $oldNormalized = $this->normalizeValue($oldValue);
            $newNormalized = $this->normalizeValue($newValue);

            if ($oldNormalized !== $newNormalized) {
                $changeType = 'updated';
                if ($oldNormalized === null && $newNormalized !== null) {
                    $changeType = 'created';
                } elseif ($oldNormalized !== null && $newNormalized === null) {
                    $changeType = 'deleted';
                }

                $changes[] = [
                    'operation_id' => $operationId,
                    'table_name' => $tableName,
                    'record_key' => json_encode($recordKey, JSON_UNESCAPED_UNICODE),
                    'field_name' => $field,
                    'old_value' => $this->formatValueForStorage($oldValue),
                    'new_value' => $this->formatValueForStorage($newValue),
                    'value_type' => $this->detectValueType($newValue ?? $oldValue),
                    'change_type' => $changeType,
                    'created_at' => now(),
                ];
            }
        }

        if (!empty($changes)) {
            DB::table('field_changes')->insert($changes);
        }
    }

    /**
     * 解析 record_key（主键）
     */
    protected function parseRecordKey(string $tableName, string $resourceId, array $newData, array $oldData): array {
        // 复合主键表的映射
        $compositePrimaryKeys = [
            'ALTNAME_DATA' => ['c_personid', 'c_sequence', 'c_alt_name_chn', 'c_alt_name_type_code'],
            'KIN_DATA' => ['c_personid', 'c_kin_id', 'c_kin_code'],
            'ASSOC_DATA' => ['c_personid', 'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id', 'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title'],
            'POSTED_TO_ADDR_DATA' => ['c_personid', 'c_posting_id', 'c_office_id', 'c_addr_id'],
            'POSTED_TO_OFFICE_DATA' => ['c_personid', 'c_posting_id', 'c_office_id'],
            'BIOG_SOURCE_DATA' => ['c_personid', 'c_textid', 'c_pages'],
            'STATUS_DATA' => ['c_personid', 'c_sequence', 'c_status_code'],
            'EVENTS_DATA' => ['c_personid', 'c_sequence'],
        ];

        $keys = $compositePrimaryKeys[strtoupper($tableName)] ?? ['c_personid'];

        $recordKey = [];
        $dataSource = !empty($newData) ? $newData : $oldData;

        foreach ($keys as $keyField) {
            $recordKey[$keyField] = $dataSource[$keyField] ?? null;
        }

        return $recordKey;
    }

    /**
     * 标准化值（用于比较）
     */
    protected function normalizeValue($value) {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        return trim((string)$value);
    }

    /**
     * 格式化值用于存储
     */
    protected function formatValueForStorage($value): ?string {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        return (string)$value;
    }

    /**
     * 检测值类型
     */
    protected function detectValueType($value): string {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return 'bool';
        }
        if (is_int($value)) {
            return 'int';
        }
        if (is_float($value)) {
            return 'float';
        }
        if (is_array($value) || is_object($value)) {
            return 'json';
        }
        if ($value instanceof \DateTimeInterface) {
            return 'date';
        }
        // 简单的日期格式检测
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$value)) {
            return 'date';
        }
        return 'string';
    }
}
```

### 3.2 合并人物场景实现示例

#### 新增 `MergePersonService.php`

```php
<?php

namespace App\Services;

use App\Repositories\OperationRepository;
use App\Repositories\OperationBatchRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class MergePersonService {
    protected $operationRepo;
    protected $batchRepo;

    public function __construct(
        OperationRepository $operationRepo,
        OperationBatchRepository $batchRepo
    ) {
        $this->operationRepo = $operationRepo;
        $this->batchRepo = $batchRepo;
    }

    /**
     * 执行人物合并（带完整追踪）
     */
    public function merge(int $primaryId, int $secondaryId, string $reason = '', bool $autoArrange = true): array {
        $userId = Auth::id();

        // 1. 创建批量操作记录
        $batch = $this->batchRepo->create(
            batchType: 'merge_person',
            userId: $userId,
            metadata: [
                'primary_personid' => $primaryId,
                'secondary_personid' => $secondaryId,
                'merge_reason' => $reason,
                'auto_arrange' => $autoArrange,
            ],
            title: "合并人物 #{$secondaryId} → #{$primaryId}",
            description: $reason
        );

        DB::beginTransaction();

        try {
            $operationsCount = 0;

            // 2. 更新 BIOG_MAIN（如果需要合并备注等字段）
            $primaryPerson = DB::table('BIOG_MAIN')->where('c_personid', $primaryId)->first();
            $secondaryPerson = DB::table('BIOG_MAIN')->where('c_personid', $secondaryId)->first();

            if ($primaryPerson && $secondaryPerson) {
                $mergedData = $this->calculateMergedBiogMain($primaryPerson, $secondaryPerson, $reason);

                if (!empty($mergedData)) {
                    DB::table('BIOG_MAIN')->where('c_personid', $primaryId)->update($mergedData);

                    $this->operationRepo->store(
                        user_id: $userId,
                        c_personid: $primaryId,
                        op_type: 3, // TYPE_UPDATE
                        resource: 'BIOG_MAIN',
                        resource_id: (string)$primaryId,
                        resource_data: array_merge(['c_personid' => $primaryId], $mergedData),
                        ori: (array)$primaryPerson,
                        batchId: $batch->id,
                        trackFields: true  // 启用字段级追踪
                    );

                    $operationsCount++;
                }
            }

            // 3. 更新关联表
            $relatedTables = [
                'ALTNAME_DATA' => ['c_personid'],
                'KIN_DATA' => ['c_personid', 'c_kin_id'],
                'ASSOC_DATA' => ['c_personid', 'c_kin_id', 'c_assoc_id', 'c_assoc_kin_id'],
                'BIOG_ADDR_DATA' => ['c_personid'],
                'BIOG_INST_DATA' => ['c_personid'],
                'BIOG_SOURCE_DATA' => ['c_personid'],
                'BIOG_TEXT_DATA' => ['c_personid'],
                'ENTRY_DATA' => ['c_personid'],
                'EVENTS_DATA' => ['c_personid'],
                'POSSESSION_DATA' => ['c_personid'],
                'POSTED_TO_ADDR_DATA' => ['c_personid'],
                'POSTING_DATA' => ['c_personid'],
                'POSTED_TO_OFFICE_DATA' => ['c_personid'],
                'STATUS_DATA' => ['c_personid'],
            ];

            foreach ($relatedTables as $table => $columns) {
                foreach ($columns as $column) {
                    $affectedRows = DB::table($table)
                        ->where($column, $secondaryId)
                        ->get();

                    if ($affectedRows->isNotEmpty()) {
                        foreach ($affectedRows as $row) {
                            $rowArray = (array)$row;
                            $updatedRow = $rowArray;
                            $updatedRow[$column] = $primaryId;

                            // 记录每一行的修改
                            $this->operationRepo->store(
                                user_id: $userId,
                                c_personid: $primaryId,
                                op_type: 3,
                                resource: $table,
                                resource_id: $this->buildResourceId($table, $updatedRow),
                                resource_data: $updatedRow,
                                ori: $rowArray,
                                batchId: $batch->id,
                                trackFields: true
                            );
                        }

                        DB::table($table)
                            ->where($column, $secondaryId)
                            ->update([$column => $primaryId]);

                        $operationsCount++;
                    }
                }
            }

            // 4. 插入 MERGED_PERSON_DATA
            $mergeRecord = [
                'c_personid' => $primaryId,
                'c_merged_from_personid' => $secondaryId,
                'c_notes' => $reason,
                'c_created_by' => Auth::user()->name ?? 'admin',
                'c_created_date' => now()->format('Ymd'),
                'c_modified_by' => Auth::user()->name ?? 'admin',
                'c_modified_date' => now()->format('Ymd'),
            ];

            DB::table('MERGED_PERSON_DATA')->insertOrIgnore($mergeRecord);

            $this->operationRepo->store(
                user_id: $userId,
                c_personid: $primaryId,
                op_type: 1, // TYPE_CREATE
                resource: 'MERGED_PERSON_DATA',
                resource_id: "{$primaryId}-{$secondaryId}",
                resource_data: $mergeRecord,
                ori: '',
                batchId: $batch->id,
                trackFields: false
            );

            $operationsCount++;

            // 5. 删除次要人物
            DB::table('BIOG_MAIN')->where('c_personid', $secondaryId)->delete();

            $this->operationRepo->store(
                user_id: $userId,
                c_personid: $secondaryId,
                op_type: 4, // TYPE_DELETE
                resource: 'BIOG_MAIN',
                resource_id: (string)$secondaryId,
                resource_data: ['c_personid' => $secondaryId],
                ori: (array)$secondaryPerson,
                batchId: $batch->id,
                trackFields: false
            );

            $operationsCount++;

            // 6. 完成批量操作
            $this->batchRepo->complete($batch->id, $operationsCount);

            DB::commit();

            return [
                'success' => true,
                'batch_id' => $batch->id,
                'operations_count' => $operationsCount,
                'primary_id' => $primaryId,
                'secondary_id' => $secondaryId,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->batchRepo->markFailed($batch->id, $e->getMessage());

            throw $e;
        }
    }

    protected function calculateMergedBiogMain($primary, $secondary, string $reason): array {
        $merged = [];

        // 合并 c_notes
        $primaryNotes = trim((string)($primary->c_notes ?? ''));
        $secondaryNotes = trim((string)($secondary->c_notes ?? ''));

        $notesLines = array_filter([$primaryNotes, $secondaryNotes]);
        $mergeTag = "[merged #{$primary->c_personid} and #{$secondary->c_personid} on ".now()->format('Ymd')."] {$reason}";
        $notesLines[] = $mergeTag;

        $newNotes = implode("\\n", $notesLines);

        if ($newNotes !== $primaryNotes) {
            $merged['c_notes'] = $newNotes;
        }

        // 更新修改者和时间
        $merged['c_modified_by'] = Auth::user()->name ?? 'admin';
        $merged['c_modified_date'] = now()->format('Ymd');

        return $merged;
    }

    protected function buildResourceId(string $table, array $data): string {
        // 根据表名构建 resource_id（与现有逻辑一致）
        $compositePrimaryKeys = [
            'ALTNAME_DATA' => ['c_personid', 'c_sequence', 'c_alt_name_chn', 'c_alt_name_type_code'],
            'KIN_DATA' => ['c_personid', 'c_kin_id', 'c_kin_code'],
            // ... 其他表的主键定义
        ];

        $keys = $compositePrimaryKeys[strtoupper($table)] ?? ['c_personid'];

        $parts = [];
        foreach ($keys as $key) {
            $parts[] = $data[$key] ?? '';
        }

        return implode('-', $parts);
    }
}
```

### 3.3 使用示例

#### Controller 中调用

```php
<?php

namespace App\Http\Controllers;

use App\Services\MergePersonService;
use Illuminate\Http\Request;

class MergePersonController extends Controller {
    protected $mergeService;

    public function __construct(MergePersonService $mergeService) {
        $this->mergeService = $mergeService;
    }

    public function execute(Request $request) {
        $request->validate([
            'primary_id' => 'required|integer|exists:BIOG_MAIN,c_personid',
            'secondary_id' => 'required|integer|exists:BIOG_MAIN,c_personid',
            'reason' => 'nullable|string|max:500',
            'auto_arrange' => 'nullable|boolean',
        ]);

        try {
            $result = $this->mergeService->merge(
                primaryId: $request->integer('primary_id'),
                secondaryId: $request->integer('secondary_id'),
                reason: $request->input('reason', ''),
                autoArrange: $request->boolean('auto_arrange', true)
            );

            flash("成功合并人物，批量操作ID: {$result['batch_id']}", 'success');

            return redirect()->route('operations.batch.show', $result['batch_id']);
        } catch (\Throwable $e) {
            flash("合并失败: {$e->getMessage()}", 'error');

            return back()->withInput();
        }
    }
}
```

## 四、查询示例

### 4.1 查询某次批量操作的所有变更

```php
// 查询批量操作详情
$batch = DB::table('operation_batches')->find($batchId);

// 查询该批量操作的所有 operations
$operations = DB::table('operations')
    ->where('batch_id', $batchId)
    ->get();

// 查询该批量操作修改的所有字段
$fieldChanges = DB::table('field_changes')
    ->whereIn('operation_id', $operations->pluck('id'))
    ->orderBy('created_at')
    ->get();
```

### 4.2 查询某个字段的修改历史

```php
// 查询 BIOG_MAIN.c_notes 字段的所有修改历史
$history = DB::table('field_changes')
    ->join('operations', 'field_changes.operation_id', '=', 'operations.id')
    ->join('users', 'operations.user_id', '=', 'users.id')
    ->where('field_changes.table_name', 'BIOG_MAIN')
    ->where('field_changes.field_name', 'c_notes')
    ->whereRaw('JSON_EXTRACT(field_changes.record_key, "$.c_personid") = ?', [123])
    ->select(
        'field_changes.*',
        'operations.user_id',
        'operations.batch_id',
        'users.name as user_name'
    )
    ->orderBy('field_changes.created_at', 'desc')
    ->get();
```

### 4.3 统计最常修改的字段

```php
// 统计过去30天内修改最频繁的字段
$topFields = DB::table('field_changes')
    ->select(
        'table_name',
        'field_name',
        DB::raw('COUNT(*) as change_count'),
        DB::raw('COUNT(DISTINCT operation_id) as operation_count')
    )
    ->where('created_at', '>=', now()->subDays(30))
    ->groupBy('table_name', 'field_name')
    ->orderByDesc('change_count')
    ->limit(20)
    ->get();
```

### 4.4 查询某个用户的批量操作历史

```php
$userBatches = DB::table('operation_batches')
    ->where('user_id', $userId)
    ->orderBy('created_at', 'desc')
    ->paginate(20);
```

## 五、迁移文件

### Migration 1: 创建 operation_batches 表

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::create('operation_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_type', 50)->comment('批量类型');
            $table->integer('user_id')->comment('执行用户ID');
            $table->string('title')->nullable()->comment('批量操作标题');
            $table->text('description')->nullable()->comment('批量操作描述');
            $table->json('metadata')->nullable()->comment('扩展元数据');
            $table->string('status', 20)->default('completed')->comment('状态');
            $table->integer('operations_count')->default(0)->comment('包含的操作数量');
            $table->timestamps();
            $table->timestamp('completed_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index('batch_type');
            $table->index('status');
        });
    }

    public function down() {
        Schema::dropIfExists('operation_batches');
    }
};
```

### Migration 2: 为 operations 表添加 batch_id

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::table('operations', function (Blueprint $table) {
            $table->unsignedBigInteger('batch_id')->nullable()->after('id')->comment('所属批量操作ID');
            $table->index('batch_id');
        });
    }

    public function down() {
        Schema::table('operations', function (Blueprint $table) {
            $table->dropIndex(['batch_id']);
            $table->dropColumn('batch_id');
        });
    }
};
```

### Migration 3: 创建 field_changes 表

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::create('field_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('operation_id')->comment('关联的 operation ID');
            $table->string('table_name', 100)->comment('数据表名');
            $table->json('record_key')->comment('记录主键');
            $table->string('field_name', 100)->comment('字段名');
            $table->text('old_value')->nullable()->comment('修改前的值');
            $table->text('new_value')->nullable()->comment('修改后的值');
            $table->string('value_type', 20)->default('string')->comment('值类型');
            $table->string('change_type', 20)->comment('变更类型');
            $table->timestamp('created_at')->nullable();

            $table->index('operation_id');
            $table->index(['table_name', 'field_name']);
            $table->index('created_at');

            $table->foreign('operation_id')
                ->references('id')
                ->on('operations')
                ->onDelete('cascade')
                ->onUpdate('cascade');
        });
    }

    public function down() {
        Schema::dropIfExists('field_changes');
    }
};
```

## 六、优缺点分析

### 优点

✅ **向后兼容**：现有代码无需修改，`batch_id` 和 `trackFields` 都是可选参数
✅ **细粒度追踪**：可以追踪到字段级别的变更
✅ **批量操作关联**：多个 operations 可以关联到一个 batch
✅ **查询灵活**：支持按用户、人物、表、字段、批次等多维度查询
✅ **性能可控**：`trackFields` 参数可选，不需要时不生成 field_changes
✅ **扩展性强**：`metadata` JSON 字段可以存储任意扩展信息

### 缺点

❌ **存储开销**：field_changes 表会增长较快，需要定期归档
❌ **写入性能**：每次操作会多写入 N 条 field_changes 记录（N = 变更字段数）
❌ **复杂度增加**：三层结构增加了系统复杂度
❌ **迁移成本**：需要修改部分代码以支持批量操作追踪

### 优化建议

1. **选择性追踪**：只对重要表（如 BIOG_MAIN、POSTED_TO_OFFICE_DATA）启用字段追踪
2. **异步写入**：使用 Laravel Queue 异步生成 field_changes，减少主流程延迟
3. **定期归档**：超过1年的 field_changes 迁移到历史表或归档存储
4. **索引优化**：根据实际查询模式调整索引策略

## 七、实施建议

### 阶段一：基础设施（1-2周）
1. ✅ 创建 migration 文件
2. ✅ 创建 Model 类（OperationBatch、FieldChange）
3. ✅ 增强 OperationRepository
4. ✅ 创建 OperationBatchRepository
5. ✅ 编写单元测试

### 阶段二：合并人物场景（1周）
1. ✅ 创建 MergePersonService
2. ✅ 修改 MergePreviewController 集成新服务
3. ✅ 创建批量操作查看页面
4. ✅ 测试合并人物流程

### 阶段三：其他批量操作（按需）
1. ✅ 批量导入
2. ✅ 批量更新
3. ✅ 批量删除

### 阶段四：优化与监控（持续）
1. ✅ 性能监控（写入延迟、表大小）
2. ✅ 归档策略
3. ✅ 查询优化

## 八、替代方案

如果认为三层架构过于复杂，可以考虑以下简化方案：

### 方案 A：只增加 batch_id（最小化改动）

只在 operations 表增加 `batch_id` 字段，不引入 field_changes 表。

- ✅ 实现简单，改动最小
- ✅ 可以关联批量操作
- ❌ 仍然无法追踪字段级变更

### 方案 B：只增加 field_changes（单层扩展）

不引入 operation_batches，直接在 operations 增加 `parent_operation_id` 字段，用于关联同一批量操作的多个 operations。

- ✅ 可以追踪字段级变更
- ✅ 可以通过 parent_operation_id 关联批量操作
- ❌ 无法存储批量操作的元数据（如合并原因）
- ❌ 批量操作状态管理困难

## 九、总结

本设计方案提供了一个**渐进式、可扩展**的 updates 记录系统，既保持了向后兼容，又提供了细粒度的追踪能力。核心思想是：

1. **operations 表**：继续作为用户操作的主记录（单位：一次用户操作）
2. **operation_batches 表**：管理批量操作的生命周期（单位：一次批量操作）
3. **field_changes 表**：提供字段级的审计追踪（单位：一个字段的一次修改）

这样的设计可以满足：
- ✅ 合并人物场景的批量追踪需求
- ✅ 字段级修改历史查询需求
- ✅ 审计和溯源需求
- ✅ 未来扩展其他批量操作的需求

是否实施建议根据实际业务需求和开发资源决定，可以先实施**阶段一和阶段二**，验证效果后再考虑推广到其他场景。
