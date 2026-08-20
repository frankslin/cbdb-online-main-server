<?php

/**
 * config 驅動的 code 表「新增／刪除」定義（供 CodeTableCreateHandler / CodeTableDeleteHandler）。
 *
 * 與 config/code_table_mutations.php（只做 update、拼音表）不同：此處是讓單主鍵 code 表可經
 * /api/v2/{create,delete} 與 batch_mutate 機器化寫入（token、operations + AuditLog、可回滾）。
 *
 * 每項欄位：
 * - resource / aliases：請求 resource 別名。
 * - table：資料表名。
 * - key_column：單一主鍵欄。
 * - auto_assign_id：create 未給主鍵時，服務端以 max(key)+1 分配（低頻；並發撞號由唯一鍵兜底 409）。
 * - allowed_fields：create 允許寫入的非主鍵欄白名單。
 */
return [
    'tables' => [
        // TEXT_CODES 已於 2026-08 收斂為文獻實體聚合（resource=text-entity，
        // TextImportService／TextAggregateDefinition）：裸表 create/delete 自此下架，
        // 寫入改走 /api/v2/{create,mutate,delete} 的 text-entity（含拼音派生、書名字形
        // 標準化與版本列）。見 docs/ENTITY_AGGREGATE_ARCHITECTURE.md §4.4。
        'char_variant_map' => [
            'resource' => 'char-variant-map',
            'aliases' => ['char-variant-map', 'char_variant_map', 'charvariantmap'],
            'table' => 'char_variant_map',
            'display_name' => '異體字落地替換對照表',
            'key_column' => 'id',
            'auto_assign_id' => true,
            'allowed_fields' => ['c_variant_char', 'c_reference_char', 'c_strict_excluded', 'c_notes'],
        ],
    ],
];
