# 審計日誌（Audit Log）文件導覽

## 文件概覽

本目錄包含審計日誌功能的完整文件集，涵蓋提案、評估與執行計劃。

### 核心文件

| 文件 | 用途 | 更新日期 | 目標讀者 |
|------|------|---------|---------|
| `AUDIT_LOG_PROPOSAL.md` | 原始提案與技術規格 | 2026-02-11 | 開發者、架構師 |
| `AUDIT_LOG_EVALUATION.md` | 現況評估報告 | 2026-02-12 | 專案經理、開發者 |
| `AUDIT_LOG_ACTION_PLAN.md` | 詳細執行行動方案 | 2026-02-12 | 開發者、QA |

---

## 快速導覽

### 🎯 我想了解審計日誌的設計
→ 閱讀 `AUDIT_LOG_PROPOSAL.md`

**關鍵章節**：
- **目標與範圍** (Goal & Intended Scope)
- **目標表格** (Target Tables) - 14 個 `/basicinformation` 相關表格
- **Schema 設計** (Proposed Schema) - `audit_log` 表結構
- **主鍵序列化** (Row Key Serialization) - `row_pk_text` 編碼規則
- **寫入位置** (Write Location) - Repository/Service 層，包裹在事務中

### 📊 我想知道目前實施到哪裡
→ 閱讀 `AUDIT_LOG_EVALUATION.md`

**關鍵發現**：
- ✅ 核心基礎建設完成（100%）
- 🟡 Controller 整合 43% 完成（6/14）
- 🔴 事務一致性僅 14% 完成（1/7）
- ⚠️ **高風險**：83% 的已整合控制器未使用事務包裹

### 🚀 我想開始執行整合工作
→ 閱讀 `AUDIT_LOG_ACTION_PLAN.md`

**執行階段**：
1. **第一階段**（1 週）：修復事務一致性問題 [P0 - 緊急]
2. **第二階段**（1 週）：補充測試斷言 [P1 - 高優先級]
3. **第三階段**（3 週）：完成剩餘 8 個控制器整合 [P2 - 中優先級]

---

## 文件詳細說明

### 1. AUDIT_LOG_PROPOSAL.md

**版本**: 0.3 (2026-02-11)

**內容**：
- 審計日誌的設計目標與非目標（non-goals）
- 14 個目標表格清單
- `audit_log` 表結構設計（兼容 MySQL/MariaDB 與 SQLite）
- 複合主鍵序列化規則（`row_pk_text` 使用 RFC 3986 編碼）
- `AuditLogService` API 設計
- 已知問題與限制
- 實施進度追蹤器（詳細控制器整合狀況）

**適用場景**：
- 新開發者需要了解審計日誌的設計理念
- 需要實作新表格的審計日誌整合
- 需要查閱技術規格（如主鍵編碼規則）

### 2. AUDIT_LOG_EVALUATION.md

**版本**: 評估報告 (2026-02-12)

**內容**：
- **執行摘要**：總體完成度 50%，可用但不完整
- **實施完成度評估**：
  - 核心基礎建設 ✅ 100%
  - Repository 層整合 🟡 14%
  - Controller 層整合 🟡 43%
  - 測試覆蓋率 🟡 40%
- **關鍵風險分析**：
  - 🔴 高風險：事務一致性缺失（83% 控制器無事務）
  - 🟡 中風險：ALTNAME LIKE 刪除可能影響多筆記錄
  - 🟡 中風險：測試斷言缺失
- **後續執行策略**（四階段）
- **附錄**：已驗證與未驗證的程式碼區域

**適用場景**：
- 專案經理需要了解進度與風險
- 技術主管需要評估技術債務
- 開發者需要了解哪些區域需要優先處理

### 3. AUDIT_LOG_ACTION_PLAN.md

**版本**: 行動方案 (2026-02-12)

**內容**：
- **第一階段**（2/12-2/18）：修復事務一致性
  - 創建 5 個新 Repository
  - 修復 ALTNAME LIKE 刪除問題
  - 重構 Controller 使用 Repository
  - 程式碼模板與範例
- **第二階段**（2/19-2/25）：補充測試斷言
  - 為 11 個現有功能測試添加斷言
  - 創建 5 個專用審計測試
  - 測試範例與模板
- **第三階段**（2/26-3/15）：完成剩餘 8 個控制器整合
  - 按優先級分三批執行
  - 每個控制器的詳細步驟
- **第四階段**（可選）：優化與監控
- **風險管理**：測試資料庫差異、效能影響、儲存成長
- **進度追蹤表**：51 個任務項目

**適用場景**：
- 開發者需要具體的實作指引與程式碼範例
- QA 需要了解測試策略
- 專案經理需要追蹤進度（進度追蹤表）

---

## 程式碼位置參考

### 核心程式碼
- **服務**: `app/Services/AuditLogService.php`
- **Migration**: `database/migrations/2026_02_08_000000_create_audit_log_table.php`
- **Controller**: `app/Http/Controllers/AdminAuditLogController.php`
- **View**: `resources/views/admin/audit_logs/index.blade.php`
- **複合主鍵支援**: `app/Support/CompositePrimaryKey.php`

### 已整合的 Repository
- `app/Repositories/BiogMainRepository.php` (✅ 包含事務)

### 已整合的 Controller（⚠️ 缺少事務）
- `app/Http/Controllers/BasicInformationController.php`
- `app/Http/Controllers/BasicInformationAltnamesController.php`
- `app/Http/Controllers/BasicInformationAddressesController.php`
- `app/Http/Controllers/BasicInformationTextsController.php`
- `app/Http/Controllers/BasicInformationSourcesController.php`
- `app/Http/Controllers/BasicInformationEntriesController.php`

### 未整合的 Controller（需要處理）
- `app/Http/Controllers/BasicInformationAssocController.php`
- `app/Http/Controllers/BasicInformationKinshipController.php`
- `app/Http/Controllers/BasicInformationEventsController.php`
- `app/Http/Controllers/BasicInformationStatusesController.php`
- `app/Http/Controllers/BasicInformationPossessionController.php`
- `app/Http/Controllers/BasicInformationSocialInstController.php`

### 測試
- **單元測試**: `tests/Unit/AuditLogServiceTest.php`
- **功能測試** (11 個檔案創建 `audit_log` 表，但缺少斷言)：
  - `tests/Feature/AssocDataDeleteTest.php`
  - `tests/Feature/BasicInformationSourcesControllerTest.php`
  - `tests/Feature/BasicInformationUpdateTest.php`
  - `tests/Feature/BiogMainBasicInfoNameMergeTest.php`
  - `tests/Feature/OfficeAddressOperationLoggingTest.php`
  - `tests/Feature/OfficeIdChangeAddressLossTest.php`
  - `tests/Feature/OfficePostingStoreTest.php`
  - ... 等

---

## 關鍵術語說明

| 術語 | 說明 |
|------|------|
| **audit_log** | 審計日誌資料表，記錄所有資料變更的完整歷史 |
| **row_pk** | JSON 格式的主鍵（支援複合主鍵） |
| **row_pk_text** | 主鍵的字串序列化形式（RFC 3986 編碼），用於查詢 |
| **old_data** | 變更前的完整欄位值（JSON） |
| **new_data** | 變更後的完整欄位值（JSON） |
| **operation** | 操作類型（INSERT/UPDATE/DELETE） |
| **operation_id** | 操作識別碼（ULID，26 字元） |
| **actor_type** | 操作者類型（user/system/job/api_key） |
| **actor_id** | 操作者識別碼（user ID 或 system） |
| **occurred_at** | 業務層變更實際發生時間 |
| **created_at** | 審計記錄寫入時間 |

---

## 常見問題

### Q1: 為什麼需要 row_pk_text？
**A**: 因為 MySQL/MariaDB 不支援 JSON 欄位的完整索引，`row_pk_text` 提供可查詢、可排序的主鍵表示。

### Q2: 為什麼要使用事務包裹？
**A**: 確保資料變更與審計日誌寫入的原子性。若審計日誌寫入失敗，資料變更應同時回滾，避免不一致。

### Q3: 如何查看某筆記錄的完整歷史？
**A**: 
1. UI 方式：訪問 `/admin/audit-logs`，使用 `row_pk_text` 篩選
2. SQL 方式：
   ```sql
   SELECT * FROM audit_log
   WHERE table_name = 'ALTNAME_DATA'
     AND row_pk_text = 'c_personid=123&c_sequence=1&...'
   ORDER BY occurred_at DESC;
   ```

### Q4: 如何處理複合主鍵？
**A**: 使用 `CompositePrimaryKey::getSchema()` 取得欄位順序，再用 `http_build_query()` 序列化。範例見 `AuditLogService::buildRowPkText()`。

### Q5: 測試時如何驗證審計日誌正確性？
**A**: 參考 `AUDIT_LOG_ACTION_PLAN.md` 第二階段的測試範例：
```php
$auditLog = DB::table('audit_log')
    ->where('table_name', 'ALTNAME_DATA')
    ->latest('id')
    ->first();

$this->assertSame('INSERT', $auditLog->operation);
$this->assertNotNull($auditLog->new_data);
```

---

## 聯絡資訊

若有疑問或需要協助，請參考以下資源：
- **專案文件**: `AGENTS.md` - 專案規範與最佳實踐
- **資料庫文件**: `DATABASE.md` - 資料庫結構與遷移指南
- **測試指南**: `.claude/skills/testing-guide.md` - PHPUnit 測試撰寫規範

---

## 更新日誌

| 日期 | 文件 | 變更說明 |
|------|------|---------|
| 2026-02-12 | `AUDIT_LOG_EVALUATION.md` | 初版評估報告 |
| 2026-02-12 | `AUDIT_LOG_ACTION_PLAN.md` | 初版執行計劃 |
| 2026-02-12 | `AUDIT_LOG_PROPOSAL.md` | 更新進度追蹤器（詳細控制器狀態） |
| 2026-02-11 | `AUDIT_LOG_PROPOSAL.md` | Version 0.3 |
