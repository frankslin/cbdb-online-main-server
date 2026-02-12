# 審計日誌實施評估報告

## 文件資訊
- **評估日期**: 2026-02-12
- **評估範圍**: `/basicinformation` 模組審計日誌實施狀況
- **參考文件**: `docs/AUDIT_LOG_PROPOSAL.md` (Version 0.3, 2026-02-11)

## 執行摘要

審計日誌（Audit Log）功能已完成**核心基礎建設**與**部分整合**，但距離提案中的完整目標仍有顯著差距。目前狀態為「可用但不完整」，存在多個**關鍵風險**需要優先處理。

**關鍵發現**：
- ✅ 核心服務與資料庫結構完整
- ✅ UI 查詢介面已實作（僅限超級管理員）
- ⚠️ **事務一致性風險高**：多數寫入路徑未包裹在單一事務中
- ⚠️ **覆蓋率不足**：14 個目標控制器中僅 6 個整合審計日誌
- ⚠️ **測試斷言缺失**：多數測試僅創建表格，未驗證審計記錄正確性

## 實施完成度評估

### 1. 核心基礎建設 ✅ 已完成

| 項目 | 狀態 | 備註 |
|------|------|------|
| Migration (`2026_02_08_000000_create_audit_log_table.php`) | ✅ 完成 | 使用 Schema Builder，兼容 MySQL/SQLite |
| `AuditLogService` 服務 | ✅ 完成 | 支援複合主鍵、RFC 3986 編碼 |
| `AdminAuditLogController` 控制器 | ✅ 完成 | 提供查詢、篩選介面 |
| UI 頁面 (`admin/audit_logs/index.blade.php`) | ✅ 完成 | 支援比較、查看 old_data/new_data |
| 路由設定 | ✅ 完成 | `/admin/audit-logs` 路由已註冊 |

**核心服務品質評估**：
- ✅ `buildRowPkText()` 正確使用 `CompositePrimaryKey::getSchema()` 保證順序一致性
- ✅ `http_build_query(..., PHP_QUERY_RFC3986)` 符合提案規範
- ✅ `normalizeRow()` 支援多種輸入格式
- ✅ 預設使用 ULID 作為 `operation_id`
- ✅ 自動偵測 actor（user/system）

### 2. Repository 層整合 🟡 部分完成

| Repository | 整合狀態 | 涵蓋表格 | 備註 |
|------------|---------|---------|------|
| `BiogMainRepository` | ✅ 已整合 | `POSTED_TO_OFFICE_DATA`<br>`POSTED_TO_ADDR_DATA` | 使用事務包裹，品質良好 |
| 其他 Repositories | ❌ 未整合 | - | 未發現其他 repository 使用 `AuditLogService` |

**BiogMainRepository 整合品質**：
- ✅ 官職相關寫入已包裹在 `DB::transaction()` 中
- ✅ 正確處理 INSERT/UPDATE/DELETE 三種操作
- ✅ 地址變更的差異追蹤正確（before/after 比對）
- ✅ 與 `operations` 表寫入在同一事務內

### 3. Controller 層整合 🔴 嚴重不足

**目標範圍**：14 個 BasicInformation 控制器（對應 14 個目標表格）

| 控制器 | 目標表格 | 整合狀態 | 事務包裹 |
|--------|---------|---------|---------|
| `BasicInformationController` | `BIOG_MAIN` | ✅ 已整合 | ❌ 無事務 |
| `BasicInformationAltnamesController` | `ALTNAME_DATA` | ✅ 已整合 | ❌ 無事務 |
| `BasicInformationAddressesController` | `BIOG_ADDR_DATA` | ✅ 已整合 | ❌ 無事務 |
| `BasicInformationTextsController` | `BIOG_TEXT_DATA` (aka `TEXT_DATA`) | ✅ 已整合 | ❌ 無事務 |
| `BasicInformationSourcesController` | `BIOG_SOURCE_DATA` | ✅ 已整合 | ❌ 無事務 |
| `BasicInformationEntriesController` | `ENTRY_DATA` | ✅ 已整合 | ❌ 無事務 |
| `BasicInformationOfficesController` | `POSTED_TO_OFFICE_DATA`<br>`POSTED_TO_ADDR_DATA` | ⚠️ 部分（via Repository） | ✅ 有事務 |
| `BasicInformationAssocController` | `ASSOC_DATA` | ❌ 未整合 | - |
| `BasicInformationKinshipController` | `KIN_DATA` | ❌ 未整合 | - |
| `BasicInformationEventsController` | `EVENTS_DATA` | ❌ 未整合 | - |
| `BasicInformationStatusesController` | `STATUS_DATA` | ❌ 未整合 | - |
| `BasicInformationPossessionController` | `POSSESSION_DATA` | ❌ 未整合 | - |
| `BasicInformationSocialInstController` | `BIOG_INST_DATA` | ❌ 未整合 | - |
| `BasicInformationProposalController` | (審批流程) | ❌ 未整合 | - |

**統計**：
- ✅ 已整合：6/14 (43%)
- ❌ 未整合：8/14 (57%)
- ✅ 有事務包裹：1/6 (17%)
- ❌ 無事務包裹：5/6 (83%)

**關鍵問題範例**（`BasicInformationAltnamesController`）：
```php
// ❌ 錯誤模式：三次獨立寫入，無事務包裹
DB::table('ALTNAME_DATA')->insert($data);  // 寫入 1
$operation = $this->operationRepository->store(...);  // 寫入 2
(new AuditLogService())->write(...);  // 寫入 3
```

若第三次寫入失敗，資料與審計日誌將不一致。

### 4. 測試覆蓋率 🟡 架構完整但斷言不足

**單元測試**：
- ✅ `tests/Unit/AuditLogServiceTest.php` 存在
- ✅ 測試 `buildRowPkText()` 的 RFC 3986 編碼
- ✅ 測試 `write()` 方法的基本功能

**功能測試**：
發現 11 個功能測試創建 `audit_log` 表格：
- `AssocDataDeleteTest.php`
- `BasicInformationSourcesControllerTest.php`
- `BasicInformationUpdateTest.php`
- `BiogMainBasicInfoNameMergeTest.php`
- `FormUrlEncodingTest.php`
- `NameSearchIndexAutoSyncTest.php`
- `OfficeAddressOperationLoggingTest.php`
- `OfficeIdChangeAddressLossTest.php`
- `OfficePostingStoreTest.php`
- `OfficeStoreRedirectTest.php`
- `UnidirectionalRelationshipRepairControllerTest.php`

**問題**：
- ⚠️ 多數測試僅創建表格結構，**未驗證審計記錄內容**
- ⚠️ 缺少以下斷言：
  - 記錄數量（應增加 1 筆）
  - `operation` 類型（INSERT/UPDATE/DELETE）
  - `row_pk` 與 `row_pk_text` 正確性
  - `old_data` / `new_data` 內容正確性
  - `operation_id` 是否與 `operations` 表關聯

## 關鍵風險分析

### 🔴 高風險：事務一致性缺失

**影響範圍**：6 個已整合審計日誌的控制器（除 `BasicInformationOfficesController` 外）

**風險場景**：
1. 資料寫入成功 → `operations` 寫入成功 → **審計日誌寫入失敗**
2. 結果：業務資料存在，但審計日誌缺失，無法追蹤變更

**符合規範的模式**（`BiogMainRepository` 範例）：
```php
DB::transaction(function () use ($data, $auditLog, ...) {
    // 1. 鎖定並更新業務表
    $updated = DB::table('POSTED_TO_OFFICE_DATA')
        ->where($conditions)
        ->lockForUpdate()
        ->update($data);
    
    // 2. 寫入 operations 記錄
    $operation = $this->operationRepository->store(...);
    
    // 3. 寫入審計日誌
    $auditLog->logChange('POSTED_TO_OFFICE_DATA', 'UPDATE', ...);
});
```

**違反規範的模式**（目前多數控制器）：
```php
// ❌ 三次獨立寫入
DB::table('ALTNAME_DATA')->insert($data);
$operation = $this->operationRepository->store(...);
(new AuditLogService())->write(...);
```

### 🟡 中風險：ALTNAME 刪除斷言風險

**問題來源**：提案中提及的已知問題
```php
// 可能影響多筆記錄，但僅記錄一筆審計日誌
DB::table('ALTNAME_DATA')->where([
    ['c_personid', '=', $id],
    ['c_sequence', '=', $data['c_sequence']],
    ['c_alt_name_chn', 'like', '%'.$search.'%'],  // ❌ LIKE 可能匹配多筆
    ['c_alt_name_type_code', '=', $code],
])->delete();
```

**影響**：審計日誌可能不完整，無法追蹤所有被刪除的記錄。

### 🟡 中風險：測試斷言缺失

**影響**：
- 無法驗證審計日誌功能在重構後是否仍正常運作
- 回歸風險高

## 後續執行策略

### 第一階段：修復高風險問題（優先）

**目標**：確保已整合審計日誌的 6 個控制器具備事務一致性

**執行步驟**：
1. **重構 Controller → Repository 模式**：
   - 將 `BasicInformationAltnamesController` 的寫入邏輯移至 `AltnameRepository`
   - 將 `BasicInformationAddressesController` 的寫入邏輯移至 `BiogAddrRepository`
   - 將 `BasicInformationTextsController` 的寫入邏輯移至 `BiogTextRepository`
   - 將 `BasicInformationSourcesController` 的寫入邏輯移至 `BiogSourceRepository`
   - 將 `BasicInformationEntriesController` 的寫入邏輯移至 `EntryRepository`

2. **在 Repository 中包裹事務**：
   ```php
   public function store(int $personId, array $data): void {
       DB::transaction(function () use ($personId, $data) {
           // 1. 寫入業務資料
           DB::table('ALTNAME_DATA')->insert($data);
           
           // 2. 寫入 operations（可選，若需要）
           $operation = app(OperationRepository::class)->store(...);
           
           // 3. 寫入審計日誌
           $auditLog = app(AuditLogService::class);
           $auditLog->write('ALTNAME_DATA', 'INSERT', ...);
       });
   }
   ```

3. **修復 ALTNAME 刪除斷言問題**：
   - 先 `select()` 取得所有符合條件的記錄
   - 對每筆記錄寫入審計日誌
   - 再執行 `delete()`
   - 全部包裹在同一事務中

**預期成果**：
- ✅ 6 個已整合控制器具備事務一致性
- ✅ ALTNAME 刪除操作的審計記錄完整

**時間估計**：2-3 天（包含測試撰寫）

### 第二階段：補充測試斷言

**目標**：確保測試能夠驗證審計日誌功能的正確性

**執行步驟**：
1. 為現有 11 個功能測試添加審計日誌斷言：
   ```php
   // 檢查審計記錄數量
   $this->assertSame(1, DB::table('audit_log')
       ->where('table_name', 'ALTNAME_DATA')
       ->where('operation', 'INSERT')
       ->count());
   
   // 檢查 row_pk_text
   $auditLog = DB::table('audit_log')
       ->where('table_name', 'ALTNAME_DATA')
       ->latest('id')
       ->first();
   $this->assertSame('c_personid=123&c_sequence=1&...', $auditLog->row_pk_text);
   
   // 檢查 new_data
   $newData = json_decode($auditLog->new_data, true);
   $this->assertSame('測試名稱', $newData['c_alt_name_chn']);
   ```

2. 對於每個目標表格，至少創建一個完整的審計日誌測試：
   - 測試 INSERT 操作
   - 測試 UPDATE 操作（驗證 old_data/new_data）
   - 測試 DELETE 操作（驗證 old_data）

**預期成果**：
- ✅ 11+ 個功能測試具備完整審計日誌斷言
- ✅ 回歸風險降低

**時間估計**：2-3 天

### 第三階段：完成剩餘整合

**目標**：整合剩餘 8 個未整合審計日誌的控制器

**執行順序**（按業務重要性與變更頻率）：
1. `BasicInformationAssocController` (ASSOC_DATA) - 關聯資料
2. `BasicInformationKinshipController` (KIN_DATA) - 親屬關係
3. `BasicInformationStatusesController` (STATUS_DATA) - 身份地位
4. `BasicInformationEventsController` (EVENTS_DATA) - 事件
5. `BasicInformationPossessionController` (POSSESSION_DATA) - 財產
6. `BasicInformationSocialInstController` (BIOG_INST_DATA) - 社會機構
7. `BasicInformationProposalController` - 審批流程（若需要）

**執行步驟**（每個控制器）：
1. 創建對應的 Repository（如不存在）
2. 將寫入邏輯移至 Repository
3. 在 Repository 中實作事務包裹的審計日誌寫入
4. 撰寫完整的功能測試（包含審計日誌斷言）
5. 更新提案文件的進度追蹤器

**預期成果**：
- ✅ 14/14 控制器完成審計日誌整合
- ✅ 全部使用事務包裹

**時間估計**：5-7 天

### 第四階段：優化與監控（可選）

**執行項目**：
1. **效能監控**：
   - 監控 `audit_log` 表的成長速度
   - 監控寫入操作的延遲變化

2. **索引優化**（若查詢變慢）：
   ```sql
   CREATE INDEX idx_audit_table_pk ON audit_log(table_name, row_pk_text);
   CREATE INDEX idx_audit_occurred ON audit_log(occurred_at DESC);
   CREATE INDEX idx_audit_actor ON audit_log(actor_type, actor_id);
   ```

3. **UI 改進**：
   - 添加時間範圍篩選
   - 添加「顯示完整 JSON」展開功能
   - 添加匯出功能（CSV/JSON）

**時間估計**：2-3 天

## 總結與建議

### 完成度評分

| 類別 | 分數 | 說明 |
|------|------|------|
| 核心基礎建設 | 100% | ✅ 完全符合提案規範 |
| Repository 整合 | 15% | ⚠️ 僅 1/7+ 完成，但品質良好 |
| Controller 整合 | 43% | ⚠️ 6/14 完成，但缺少事務包裹 |
| 測試覆蓋率 | 40% | ⚠️ 架構完整，但斷言不足 |
| **總體完成度** | **50%** | 🟡 可用但不完整 |

### 優先建議

1. **立即執行**（第一階段）：
   - 修復已整合控制器的事務一致性問題
   - 修復 ALTNAME 刪除斷言風險
   - 這是**資料正確性的核心保證**，應優先處理

2. **短期執行**（第二階段）：
   - 補充測試斷言
   - 確保測試能夠捕捉回歸問題

3. **中期執行**（第三階段）：
   - 完成剩餘 8 個控制器的整合
   - 達到提案中的完整目標

4. **長期執行**（第四階段）：
   - 效能監控與優化
   - UI 改進

### 技術債務警示

**目前狀態存在的主要技術債務**：
1. 🔴 **事務一致性缺失**：83% 的已整合控制器未使用事務包裹
2. 🟡 **覆蓋率不足**：57% 的目標控制器未整合審計日誌
3. 🟡 **測試斷言不足**：多數測試未驗證審計記錄正確性

**建議的修復時間軸**：
- 第一階段：1 週內（高優先級）
- 第二階段：2 週內（中優先級）
- 第三階段：1 個月內（低優先級）

## 附錄

### A. 已驗證的程式碼路徑

**AuditLogService 核心功能**：
- ✅ `buildRowPkText()` 使用 `CompositePrimaryKey::getSchema()` 保證順序
- ✅ RFC 3986 編碼正確（空格 → `%20`，不是 `+`）
- ✅ ULID 生成正確（26 字符）
- ✅ 自動偵測 actor_type 和 actor_id

**BiogMainRepository 整合品質**：
- ✅ `officeUpdateById()` 使用 `DB::transaction()`
- ✅ 正確處理 `POSTED_TO_OFFICE_DATA` 與 `POSTED_TO_ADDR_DATA` 的差異追蹤
- ✅ 地址變更僅在實際變動時寫入審計日誌

### B. 未驗證的區域（需要實際測試）

由於 PHP 版本不匹配（需要 PHP 8.4，環境僅有 8.3.6），以下項目未能實際執行測試驗證：
- ⚠️ Migration 在 SQLite 上的實際執行結果
- ⚠️ 單元測試與功能測試的實際通過率
- ⚠️ UI 頁面的實際顯示效果

### C. 參考資料

- `docs/AUDIT_LOG_PROPOSAL.md` - 原始提案文件
- `app/Services/AuditLogService.php` - 核心服務實作
- `app/Support/CompositePrimaryKey.php` - 複合主鍵支援
- `database/migrations/2026_02_08_000000_create_audit_log_table.php` - 資料庫結構
- `resources/views/admin/audit_logs/index.blade.php` - UI 介面
