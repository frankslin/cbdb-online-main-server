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

## 二、设计方案：简化的两层追踪系统

### 2.1 架构概览

```
operations (用户操作层，现有表增强)
    ├─> 增加 batch_id 字段（关联同批次操作）
    └─> field_modifications (字段变更层，新增表)
```

**核心思想**：
- 不需要单独的 `operation_batches` 表
- `batch_id` 作为普通字段，用于关联同一批次的多个操作
- 批量操作的元数据存储在第一条 operation 的 `batch_metadata` 字段中
- 字段级追踪通过 `field_modifications` 表实现

### 2.2 数据库表设计

#### Table 1: `operations` (修改：增加 batch_id 和 batch_metadata)

```sql
-- 在现有 operations 表增加两个字段
ALTER TABLE `operations`
ADD COLUMN `batch_id` varchar(64) DEFAULT NULL COMMENT '批量操作ID（同一批次的操作共享此ID）' AFTER `id`,
ADD COLUMN `batch_metadata` json DEFAULT NULL COMMENT '批量操作元数据（仅第一条记录存储）' AFTER `resource_original`,
ADD KEY `idx_batch_id` (`batch_id`);
```

**batch_id 生成规则**：
```php
// 方式1：使用 UUID
$batchId = (string) Str::uuid();  // "550e8400-e29b-41d4-a716-446655440000"

// 方式2：使用时间戳 + 用户ID
$batchId = sprintf('BATCH_%d_%d', Auth::id(), now()->timestamp);  // "BATCH_1_1705564800"

// 方式3：使用雪花算法（推荐，短且唯一）
$batchId = Snowflake::make()->id();  // "1234567890123456"
```

**batch_metadata JSON 示例**（合并人物）：
```json
{
  "batch_type": "merge_person",
  "title": "合并人物 #456 → #123",
  "primary_personid": 123,
  "secondary_personid": 456,
  "merge_reason": "同一人物，资料重复",
  "auto_arrange": true,
  "affected_tables": ["BIOG_MAIN", "ALTNAME_DATA", "KIN_DATA"],
  "operations_count": 15,
  "started_at": "2025-01-18 10:30:00",
  "completed_at": "2025-01-18 10:30:05"
}
```

**向后兼容性**：
- ✅ `batch_id` 和 `batch_metadata` 都允许为 NULL，现有单独操作不受影响
- ✅ 现有代码无需修改即可继续工作
- ✅ 只在需要批量追踪时才设置这两个字段

#### Table 2: `field_modifications` (新增)

记录字段级别的变更，每条记录代表一个字段的一次修改。

```sql
CREATE TABLE `field_modifications` (
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
  CONSTRAINT `fk_field_modifications_operation`
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
-- operations 索引（已有的保持，新增 batch_id）
-- 已有: idx_c_personid
CREATE INDEX idx_batch_id ON operations(batch_id);
CREATE INDEX idx_batch_created ON operations(batch_id, created_at DESC);

-- field_modifications 索引
CREATE INDEX idx_table_field ON field_modifications(table_name, field_name, created_at DESC);
CREATE INDEX idx_operation_id ON field_modifications(operation_id);
```

## 三、实现方式

### 3.1 Repository 增强

#### 增强 `OperationRepository.php`

```php
<?php

namespace App\Repositories;

use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationRepository {
    /**
     * 存储操作记录（增强版，支持 batch_id 和字段级追踪）
     *
     * @param string|null $batchId 批量操作ID（可选，同一批次共享）
     * @param array|null $batchMetadata 批量操作元数据（仅第一条记录需要）
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
        $batchMetadata = null,
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

        // 如果提供了批量元数据，存储到第一条记录
        if ($batchMetadata !== null) {
            $operation->batch_metadata = json_encode($batchMetadata, JSON_UNESCAPED_UNICODE);
        }

        $operation->save();

        // 如果启用字段级追踪，生成 field_modifications 记录
        if ($trackFields && !empty($ori)) {
            $this->generateFieldModifications($operation->id, $resource, $resource_id, $resource_data, $ori);
        }

        return $operation;
    }

    /**
     * 生成批量操作ID
     */
    public function generateBatchId(): string {
        // 使用 UUID 方式（推荐）
        return (string) Str::uuid();
    }

    /**
     * 生成字段级变更记录
     */
    protected function generateFieldModifications(int $operationId, string $tableName, string $resourceId, array $newData, $oldData): void {
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
            DB::table('field_modifications')->insert($changes);
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class MergePersonService {
    protected $operationRepo;

    public function __construct(OperationRepository $operationRepo) {
        $this->operationRepo = $operationRepo;
    }

    /**
     * 执行人物合并（带完整追踪）
     */
    public function merge(int $primaryId, int $secondaryId, string $reason = '', bool $autoArrange = true): array {
        $userId = Auth::id();
        $startTime = now();

        // 1. 生成批量操作ID
        $batchId = $this->operationRepo->generateBatchId();

        // 2. 准备批量元数据（将存储在第一条 operation 记录中）
        $batchMetadata = [
            'batch_type' => 'merge_person',
            'title' => "合并人物 #{$secondaryId} → #{$primaryId}",
            'primary_personid' => $primaryId,
            'secondary_personid' => $secondaryId,
            'merge_reason' => $reason,
            'auto_arrange' => $autoArrange,
            'started_at' => $startTime->toDateTimeString(),
        ];

        DB::beginTransaction();

        try {
            $operationsCount = 0;
            $isFirstOperation = true;

            // 3. 更新 BIOG_MAIN（如果需要合并备注等字段）
            $primaryPerson = DB::table('BIOG_MAIN')->where('c_personid', $primaryId)->first();
            $secondaryPerson = DB::table('BIOG_MAIN')->where('c_personid', $secondaryId)->first();

            if ($primaryPerson && $secondaryPerson) {
                $mergedData = $this->calculateMergedBiogMain($primaryPerson, $secondaryPerson, $reason);

                if (!empty($mergedData)) {
                    DB::table('BIOG_MAIN')->where('c_personid', $primaryId)->update($mergedData);

                    // 第一条记录存储批量元数据
                    $this->operationRepo->store(
                        user_id: $userId,
                        c_personid: $primaryId,
                        op_type: 3, // TYPE_UPDATE
                        resource: 'BIOG_MAIN',
                        resource_id: (string)$primaryId,
                        resource_data: array_merge(['c_personid' => $primaryId], $mergedData),
                        ori: (array)$primaryPerson,
                        crowdsourcing_status: 0,
                        batchId: $batchId,
                        batchMetadata: $isFirstOperation ? $batchMetadata : null,  // 仅第一条记录存储元数据
                        trackFields: true  // 启用字段级追踪
                    );

                    $operationsCount++;
                    $isFirstOperation = false;
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

                            // 记录每一行的修改（后续记录不传 batchMetadata）
                            $this->operationRepo->store(
                                user_id: $userId,
                                c_personid: $primaryId,
                                op_type: 3,
                                resource: $table,
                                resource_id: $this->buildResourceId($table, $updatedRow),
                                resource_data: $updatedRow,
                                ori: $rowArray,
                                crowdsourcing_status: 0,
                                batchId: $batchId,
                                batchMetadata: $isFirstOperation ? $batchMetadata : null,
                                trackFields: true
                            );

                            if ($isFirstOperation) {
                                $isFirstOperation = false;
                            }
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
                crowdsourcing_status: 0,
                batchId: $batchId,
                batchMetadata: null,  // 不存储元数据
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
                crowdsourcing_status: 0,
                batchId: $batchId,
                batchMetadata: null,
                trackFields: false
            );

            $operationsCount++;

            // 6. 更新第一条记录的 batch_metadata，添加完成信息
            DB::table('operations')
                ->where('batch_id', $batchId)
                ->whereNotNull('batch_metadata')
                ->update([
                    'batch_metadata' => DB::raw(sprintf(
                        "JSON_SET(batch_metadata, '$.completed_at', '%s', '$.operations_count', %d)",
                        now()->toDateTimeString(),
                        $operationsCount
                    ))
                ]);

            DB::commit();

            return [
                'success' => true,
                'batch_id' => $batchId,
                'operations_count' => $operationsCount,
                'primary_id' => $primaryId,
                'secondary_id' => $secondaryId,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();

            // 记录失败信息到第一条记录的 batch_metadata
            DB::table('operations')
                ->where('batch_id', $batchId)
                ->whereNotNull('batch_metadata')
                ->update([
                    'batch_metadata' => DB::raw(sprintf(
                        "JSON_SET(batch_metadata, '$.failed_at', '%s', '$.error', %s)",
                        now()->toDateTimeString(),
                        DB::getPdo()->quote($e->getMessage())
                    ))
                ]);

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
// 查询批量操作的元数据（从第一条 operation 记录获取）
$batchInfo = DB::table('operations')
    ->where('batch_id', $batchId)
    ->whereNotNull('batch_metadata')
    ->first();

$batchMetadata = $batchInfo ? json_decode($batchInfo->batch_metadata, true) : null;

// 查询该批量操作的所有 operations
$operations = DB::table('operations')
    ->where('batch_id', $batchId)
    ->orderBy('id')
    ->get();

// 查询该批量操作修改的所有字段
$fieldModifications = DB::table('field_modifications')
    ->whereIn('operation_id', $operations->pluck('id'))
    ->orderBy('created_at')
    ->get();

// 汇总信息
$summary = [
    'batch_id' => $batchId,
    'batch_type' => $batchMetadata['batch_type'] ?? null,
    'title' => $batchMetadata['title'] ?? null,
    'operations_count' => $operations->count(),
    'field_modifications_count' => $fieldModifications->count(),
    'started_at' => $batchMetadata['started_at'] ?? null,
    'completed_at' => $batchMetadata['completed_at'] ?? null,
];
```

### 4.2 查询某个字段的修改历史

```php
// 查询 BIOG_MAIN.c_notes 字段的所有修改历史
$history = DB::table('field_modifications')
    ->join('operations', 'field_modifications.operation_id', '=', 'operations.id')
    ->join('users', 'operations.user_id', '=', 'users.id')
    ->where('field_modifications.table_name', 'BIOG_MAIN')
    ->where('field_modifications.field_name', 'c_notes')
    ->whereRaw('JSON_EXTRACT(field_modifications.record_key, "$.c_personid") = ?', [123])
    ->select(
        'field_modifications.*',
        'operations.user_id',
        'operations.batch_id',
        'users.name as user_name'
    )
    ->orderBy('field_modifications.created_at', 'desc')
    ->get();

// 格式化输出
foreach ($history as $record) {
    echo sprintf(
        "%s: %s changed c_notes from '%s' to '%s' (batch: %s)\n",
        $record->created_at,
        $record->user_name,
        $record->old_value ?? '(null)',
        $record->new_value ?? '(null)',
        $record->batch_id ?? 'single operation'
    );
}
```

### 4.3 统计最常修改的字段

```php
// 统计过去30天内修改最频繁的字段
$topFields = DB::table('field_modifications')
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
// 查询用户的所有批量操作（通过 batch_metadata 不为空的记录）
$userBatches = DB::table('operations')
    ->select(
        'batch_id',
        'batch_metadata',
        'created_at',
        DB::raw('COUNT(*) OVER (PARTITION BY batch_id) as operations_count')
    )
    ->where('user_id', $userId)
    ->whereNotNull('batch_id')
    ->whereNotNull('batch_metadata')
    ->orderBy('created_at', 'desc')
    ->get()
    ->map(function ($row) {
        $metadata = json_decode($row->batch_metadata, true);
        return [
            'batch_id' => $row->batch_id,
            'batch_type' => $metadata['batch_type'] ?? null,
            'title' => $metadata['title'] ?? null,
            'operations_count' => $row->operations_count,
            'started_at' => $row->created_at,
            'completed_at' => $metadata['completed_at'] ?? null,
        ];
    });
```

### 4.5 查询某个人物的所有批量操作

```php
// 查询某个人物参与的所有批量操作
$personBatches = DB::table('operations')
    ->select('batch_id', 'batch_metadata', 'created_at')
    ->where('c_personid', $personId)
    ->whereNotNull('batch_id')
    ->groupBy('batch_id', 'batch_metadata', 'created_at')
    ->orderBy('created_at', 'desc')
    ->get()
    ->map(function ($row) {
        $metadata = json_decode($row->batch_metadata, true);
        return [
            'batch_id' => $row->batch_id,
            'batch_type' => $metadata['batch_type'] ?? null,
            'title' => $metadata['title'] ?? null,
            'created_at' => $row->created_at,
        ];
    });
```

## 五、迁移文件

### Migration 1: 为 operations 表添加 batch_id 和 batch_metadata

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::table('operations', function (Blueprint $table) {
            $table->string('batch_id', 64)->nullable()->after('id')->comment('批量操作ID（同一批次共享）');
            $table->json('batch_metadata')->nullable()->after('resource_original')->comment('批量操作元数据（仅第一条记录存储）');

            $table->index('batch_id');
        });
    }

    public function down() {
        Schema::table('operations', function (Blueprint $table) {
            $table->dropIndex(['batch_id']);
            $table->dropColumn(['batch_id', 'batch_metadata']);
        });
    }
};
```

### Migration 2: 创建 field_modifications 表

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::create('field_modifications', function (Blueprint $table) {
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
        Schema::dropIfExists('field_modifications');
    }
};
```

## 六、优缺点分析

### 优点

✅ **极简设计**：只增加 2 个字段 + 1 个新表，改动最小
✅ **向后兼容**：现有代码无需修改，`batch_id`、`batch_metadata`、`trackFields` 都是可选参数
✅ **无外键依赖**：`batch_id` 是普通字符串字段，不需要额外表维护
✅ **细粒度追踪**：可以追踪到字段级别的变更
✅ **批量操作关联**：多个 operations 通过相同的 `batch_id` 关联
✅ **元数据灵活**：批量操作元数据存储在第一条记录的 JSON 字段中，灵活扩展
✅ **查询高效**：支持按用户、人物、表、字段、批次等多维度查询
✅ **性能可控**：`trackFields` 参数可选，不需要时不生成 field_modifications

### 缺点

❌ **存储开销**：field_modifications 表会增长较快，需要定期归档
❌ **写入性能**：每次操作会多写入 N 条 field_modifications 记录（N = 变更字段数）
❌ **元数据冗余**：批量元数据只存在第一条记录，查询时需要 `whereNotNull('batch_metadata')`
❌ **迁移成本**：需要修改部分代码以支持批量操作追踪

### 优化建议

1. **选择性追踪**：只对重要表（如 BIOG_MAIN、POSTED_TO_OFFICE_DATA）启用字段追踪
2. **异步写入**：使用 Laravel Queue 异步生成 field_modifications，减少主流程延迟
3. **定期归档**：超过1年的 field_modifications 迁移到历史表或归档存储
4. **索引优化**：根据实际查询模式调整索引策略
5. **批量查询优化**：创建物化视图或缓存层，加速批量操作列表查询

## 七、实施建议

### 阶段一：基础设施（1-2周）
1. ✅ 创建 2 个 migration 文件（为 operations 表添加字段 + 创建 field_modifications 表）
2. ✅ 创建 Model 类（`FieldModification` 模型）
3. ✅ 增强 `OperationRepository`（添加 `generateBatchId()` 和 `generateFieldModifications()` 方法）
4. ✅ 编写单元测试（测试 batch_id 生成、字段追踪逻辑）

### 阶段二：合并人物场景（1周）
1. ✅ 创建 `MergePersonService`
2. ✅ 修改 `MergePreviewController` 集成新服务
3. ✅ 创建批量操作查看页面（展示 batch_metadata 和关联的 operations）
4. ✅ 测试合并人物流程（验证批量追踪和字段级追踪）

### 阶段三：其他批量操作（按需）
1. ✅ 批量导入（使用相同的 batch_id 机制）
2. ✅ 批量更新（多个人物的相同字段修改）
3. ✅ 批量删除

### 阶段四：优化与监控（持续）
1. ✅ 性能监控（写入延迟、表大小、查询效率）
2. ✅ 归档策略（定期迁移老旧的 field_modifications 记录）
3. ✅ 查询优化（根据实际使用模式调整索引）
4. ✅ 考虑创建 View 简化批量操作查询

## 八、更简化的替代方案

如果认为当前的两层设计仍然复杂，可以考虑以下更简单的方案：

### 方案 A：只增加 batch_id（最小化改动）

只在 operations 表增加 `batch_id` 和 `batch_metadata` 字段，不引入 field_modifications 表。

**优点**：
- ✅ 实现极简，改动最小（只增加 2 个字段）
- ✅ 可以关联批量操作
- ✅ 可以存储批量操作元数据

**缺点**：
- ❌ 仍然无法追踪字段级变更
- ❌ 需要解析 JSON 才能查看字段修改历史

### 方案 B：只增加 field_modifications（不做批量关联）

只创建 field_modifications 表，不在 operations 表增加 batch_id 字段。

**优点**：
- ✅ 可以追踪字段级变更
- ✅ 查询单个字段历史非常方便

**缺点**：
- ❌ 无法关联批量操作
- ❌ 不知道哪些操作是同一次合并产生的

### 方案 C：使用现有 operations 表 + 后处理

不修改任何表结构，只在查询时通过时间窗口和用户ID推断批量操作。

**优点**：
- ✅ 零改动，完全向后兼容

**缺点**：
- ❌ 推断不准确（如果用户在短时间内执行多个独立操作）
- ❌ 无法区分真正的批量操作和偶然的连续操作

## 九、总结

本设计方案提供了一个**极简的两层追踪系统**，在保持向后兼容的同时，提供了批量操作关联和字段级追踪能力。核心思想是：

1. **operations 表**：继续作为用户操作的主记录，增加 `batch_id` 和 `batch_metadata` 字段
   - `batch_id`：将同一批次的多个操作关联在一起
   - `batch_metadata`：在第一条记录中存储批量操作的元数据（类型、标题、参数等）

2. **field_modifications 表**：提供字段级的审计追踪（单位：一个字段的一次修改）
   - 记录每个字段的 `old_value` → `new_value` 变化
   - 支持复合主键（通过 `record_key` JSON 字段）
   - 可选功能（通过 `trackFields` 参数控制）

### 设计优势

✅ **极简架构**：只增加 2 个字段 + 1 个新表，无需额外的 `operation_batches` 表
✅ **向后兼容**：现有代码无需修改，所有新功能都是可选的
✅ **批量追踪**：一次合并人物的十几个操作都关联到同一个 `batch_id`
✅ **字段级追踪**：可以查看某个字段（如 `c_notes`）的完整修改历史
✅ **查询灵活**：支持按批次、用户、人物、表、字段等多维度查询

### 适用场景

这样的设计可以满足：
- ✅ 合并人物场景的批量追踪需求
- ✅ 字段级修改历史查询需求（审计）
- ✅ 统计分析需求（哪些字段被修改最频繁）
- ✅ 未来扩展其他批量操作的需求（批量导入、批量更新等）

### 实施建议

建议采用**渐进式实施**策略：
1. **先实施阶段一**（基础设施），创建 migration 和增强 Repository
2. **再实施阶段二**（合并人物场景），验证批量追踪效果
3. **根据效果决定是否推广**到其他批量操作场景
4. **持续优化**（性能监控、归档策略、查询优化）

### 与原始三层架构的对比

| 特性 | 三层架构 | 两层架构（当前方案） |
|------|---------|---------------------|
| 新增表数量 | 2 个 | 1 个 |
| 新增字段数量 | 1 个 | 2 个 |
| 批量操作关联 | ✅ 外键关联 | ✅ batch_id 字符串 |
| 字段级追踪 | ✅ | ✅ |
| 元数据存储 | 独立表 | JSON 字段 |
| 查询复杂度 | 需要 JOIN 3 个表 | 需要 JOIN 2 个表 |
| 实现复杂度 | 中等 | 低 |
| 维护成本 | 中等 | 低 |

**结论**：两层架构在功能上完全满足需求，同时实现和维护成本更低，是更优的选择。
