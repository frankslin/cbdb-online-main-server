# 審計日誌後續執行行動方案

## 文件資訊
- **制定日期**: 2026-02-12
- **依據文件**: `AUDIT_LOG_EVALUATION.md`
- **執行期限**: 2026-03-15（4 週）

## 執行優先級與時間線

```
第一週（2/12-2/18）：修復事務一致性問題 [P0 - 緊急]
第二週（2/19-2/25）：補充測試斷言 [P1 - 高優先級]
第三-四週（2/26-3/15）：完成剩餘整合 [P2 - 中優先級]
```

## 第一階段：修復事務一致性問題 [P0]

### 📅 時間：2/12 - 2/18（1 週）
### 🎯 目標：確保已整合審計日誌的控制器具備事務一致性

### 任務清單

#### 1.1 創建必要的 Repository
- [ ] 創建 `app/Repositories/AltnameRepository.php`
- [ ] 創建 `app/Repositories/BiogAddrRepository.php`
- [ ] 創建 `app/Repositories/BiogTextRepository.php`
- [ ] 創建 `app/Repositories/BiogSourceRepository.php`
- [ ] 創建 `app/Repositories/EntryRepository.php`

**程式碼模板**：
```php
<?php

namespace App\Repositories;

use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;

class AltnameRepository {
    protected AuditLogService $auditLog;
    protected OperationRepository $operationRepo;

    public function __construct(
        AuditLogService $auditLog,
        OperationRepository $operationRepo
    ) {
        $this->auditLog = $auditLog;
        $this->operationRepo = $operationRepo;
    }

    public function store(int $personId, array $data): void {
        DB::transaction(function () use ($personId, $data) {
            // 1. 寫入業務資料
            DB::table('ALTNAME_DATA')->insert($data);

            // 2. 記錄操作（若需要）
            $operation = $this->operationRepo->store(
                auth()->id(),
                $personId,
                1, // op_type = 新增
                'ALTNAME_DATA',
                $this->buildResourceId($data),
                $data
            );

            // 3. 寫入審計日誌
            $rowPk = $this->auditLog->buildRowPkFromData('ALTNAME_DATA', $data);
            $this->auditLog->write(
                'ALTNAME_DATA',
                'INSERT',
                $rowPk,
                null,
                $data,
                'user',
                (string) auth()->id(),
                $operation ? (string) $operation->id : null
            );
        });
    }

    public function update(int $personId, array $originalPk, array $data): void {
        DB::transaction(function () use ($personId, $originalPk, $data) {
            // 1. 鎖定並讀取舊資料
            $oldData = DB::table('ALTNAME_DATA')
                ->where($originalPk)
                ->lockForUpdate()
                ->first();

            if (!$oldData) {
                throw new \Exception('Record not found');
            }

            // 2. 更新資料
            DB::table('ALTNAME_DATA')
                ->where($originalPk)
                ->update($data);

            // 3. 記錄操作
            $newPk = array_merge($originalPk, array_intersect_key($data, $originalPk));
            $operation = $this->operationRepo->store(
                auth()->id(),
                $personId,
                3, // op_type = 修改
                'ALTNAME_DATA',
                $this->buildResourceId($newPk),
                array_merge((array) $oldData, $data),
                (array) $oldData
            );

            // 4. 寫入審計日誌
            $rowPk = $this->auditLog->buildRowPkFromData('ALTNAME_DATA', $newPk);
            $this->auditLog->write(
                'ALTNAME_DATA',
                'UPDATE',
                $rowPk,
                $this->auditLog->normalizeRow($oldData),
                array_merge($this->auditLog->normalizeRow($oldData), $data),
                'user',
                (string) auth()->id(),
                $operation ? (string) $operation->id : null
            );
        });
    }

    public function delete(int $personId, array $pk): void {
        DB::transaction(function () use ($personId, $pk) {
            // 1. 鎖定並讀取資料
            $oldData = DB::table('ALTNAME_DATA')
                ->where($pk)
                ->lockForUpdate()
                ->first();

            if (!$oldData) {
                return; // 記錄不存在，靜默返回
            }

            // 2. 刪除資料
            DB::table('ALTNAME_DATA')
                ->where($pk)
                ->delete();

            // 3. 記錄操作
            $operation = $this->operationRepo->store(
                auth()->id(),
                $personId,
                2, // op_type = 刪除
                'ALTNAME_DATA',
                $this->buildResourceId($pk),
                (array) $oldData
            );

            // 4. 寫入審計日誌
            $rowPk = $this->auditLog->buildRowPkFromData('ALTNAME_DATA', $pk);
            $this->auditLog->write(
                'ALTNAME_DATA',
                'DELETE',
                $rowPk,
                $this->auditLog->normalizeRow($oldData),
                null,
                'user',
                (string) auth()->id(),
                $operation ? (string) $operation->id : null
            );
        });
    }

    private function buildResourceId(array $pk): string {
        return \App\Support\CompositePrimaryKey::buildStoredResourceId($pk);
    }
}
```

#### 1.2 修復 ALTNAME 刪除斷言問題
- [ ] 在 `AltnameRepository::delete()` 中修正 LIKE 查詢問題
- [ ] 改為先 SELECT 所有符合條件的記錄
- [ ] 對每筆記錄執行刪除並寫入審計日誌

**程式碼範例**：
```php
public function deleteByLikeMatch(int $personId, array $conditions): int {
    return DB::transaction(function () use ($personId, $conditions) {
        // 1. 鎖定並取得所有符合條件的記錄
        $query = DB::table('ALTNAME_DATA')
            ->where('c_personid', $personId);
        
        foreach ($conditions as $field => $value) {
            if (str_contains($value, '%')) {
                $query->where($field, 'like', $value);
            } else {
                $query->where($field, $value);
            }
        }
        
        $rows = $query->lockForUpdate()->get();
        
        if ($rows->isEmpty()) {
            return 0;
        }

        $deletedCount = 0;
        
        // 2. 對每筆記錄執行刪除與審計
        foreach ($rows as $row) {
            $pk = [
                'c_personid' => $row->c_personid,
                'c_sequence' => $row->c_sequence,
                'c_alt_name_chn' => $row->c_alt_name_chn,
                'c_alt_name_type_code' => $row->c_alt_name_type_code,
            ];
            
            DB::table('ALTNAME_DATA')->where($pk)->delete();
            
            // 記錄操作
            $operation = $this->operationRepo->store(
                auth()->id(),
                $personId,
                2,
                'ALTNAME_DATA',
                $this->buildResourceId($pk),
                (array) $row
            );
            
            // 寫入審計日誌
            $rowPk = $this->auditLog->buildRowPkFromData('ALTNAME_DATA', $pk);
            $this->auditLog->write(
                'ALTNAME_DATA',
                'DELETE',
                $rowPk,
                $this->auditLog->normalizeRow($row),
                null,
                'user',
                (string) auth()->id(),
                $operation ? (string) $operation->id : null
            );
            
            $deletedCount++;
        }
        
        return $deletedCount;
    });
}
```

#### 1.3 重構 Controller 使用 Repository
- [ ] `BasicInformationAltnamesController` → 使用 `AltnameRepository`
- [ ] `BasicInformationAddressesController` → 使用 `BiogAddrRepository`
- [ ] `BasicInformationTextsController` → 使用 `BiogTextRepository`
- [ ] `BasicInformationSourcesController` → 使用 `BiogSourceRepository`
- [ ] `BasicInformationEntriesController` → 使用 `EntryRepository`

**重構範例**：
```php
// 舊程式碼（Controller 直接寫入）
public function store(Request $request, $id) {
    $data = [...];
    DB::table('ALTNAME_DATA')->insert($data);
    $operation = $this->operationRepository->store(...);
    (new AuditLogService())->write(...);
    return redirect()->back();
}

// 新程式碼（使用 Repository）
public function store(Request $request, $id, AltnameRepository $repo) {
    $data = [...];
    $repo->store($id, $data);
    return redirect()->back();
}
```

#### 1.4 撰寫測試
- [ ] 為每個 Repository 創建單元測試（`tests/Unit/*RepositoryTest.php`）
- [ ] 測試事務回滾（模擬審計日誌寫入失敗）
- [ ] 測試審計記錄的正確性

**測試範例**：
```php
/** @test */
public function it_rolls_back_transaction_if_audit_log_fails(): void {
    // 模擬 AuditLogService 拋出例外
    $mockAuditLog = Mockery::mock(AuditLogService::class);
    $mockAuditLog->shouldReceive('buildRowPkFromData')->andReturn([]);
    $mockAuditLog->shouldReceive('write')->andThrow(new \Exception('Audit log error'));
    
    $repo = new AltnameRepository($mockAuditLog, app(OperationRepository::class));
    
    $countBefore = DB::table('ALTNAME_DATA')->count();
    
    try {
        $repo->store(123, ['c_personid' => 123, ...]);
    } catch (\Exception $e) {
        // 預期拋出例外
    }
    
    // 確認資料未寫入（事務已回滾）
    $this->assertSame($countBefore, DB::table('ALTNAME_DATA')->count());
}
```

### 驗收標準
- ✅ 5 個新 Repository 創建完成
- ✅ 所有寫入操作包裹在 `DB::transaction()` 中
- ✅ ALTNAME LIKE 刪除問題修復
- ✅ 所有單元測試通過
- ✅ 事務回滾測試通過

---

## 第二階段：補充測試斷言 [P1]

### 📅 時間：2/19 - 2/25（1 週）
### 🎯 目標：確保測試能夠驗證審計日誌功能的正確性

### 任務清單

#### 2.1 補充現有功能測試的審計斷言
- [ ] `AssocDataDeleteTest.php`
- [ ] `BasicInformationSourcesControllerTest.php`
- [ ] `BasicInformationUpdateTest.php`
- [ ] `BiogMainBasicInfoNameMergeTest.php`
- [ ] `OfficeAddressOperationLoggingTest.php`
- [ ] `OfficeIdChangeAddressLossTest.php`
- [ ] `OfficePostingStoreTest.php`
- [ ] `OfficeStoreRedirectTest.php`

**斷言模板**：
```php
// 檢查審計記錄數量
$auditCount = DB::table('audit_log')
    ->where('table_name', 'ALTNAME_DATA')
    ->where('operation', 'INSERT')
    ->count();
$this->assertSame(1, $auditCount, '應該記錄 1 筆 INSERT 審計日誌');

// 檢查最新審計記錄
$auditLog = DB::table('audit_log')
    ->where('table_name', 'ALTNAME_DATA')
    ->latest('id')
    ->first();

$this->assertNotNull($auditLog, '審計日誌應該存在');
$this->assertSame('INSERT', $auditLog->operation);
$this->assertSame('user', $auditLog->actor_type);
$this->assertSame((string) $this->user->id, $auditLog->actor_id);

// 檢查 row_pk_text
$expectedPkText = 'c_personid=123&c_sequence=1&c_alt_name_chn=%E6%B8%AC%E8%A9%A6&c_alt_name_type_code=1';
$this->assertSame($expectedPkText, $auditLog->row_pk_text);

// 檢查 new_data
$newData = json_decode($auditLog->new_data, true);
$this->assertIsArray($newData, 'new_data 應該是 JSON 陣列');
$this->assertSame('測試', $newData['c_alt_name_chn']);

// 檢查 old_data（INSERT 應為 null，UPDATE/DELETE 應有值）
$this->assertNull($auditLog->old_data, 'INSERT 操作的 old_data 應為 null');
```

#### 2.2 創建專用的審計日誌功能測試
- [ ] `tests/Feature/AuditLogAltnameTest.php`
- [ ] `tests/Feature/AuditLogBiogAddrTest.php`
- [ ] `tests/Feature/AuditLogBiogTextTest.php`
- [ ] `tests/Feature/AuditLogBiogSourceTest.php`
- [ ] `tests/Feature/AuditLogEntryTest.php`

**測試範例**：
```php
<?php

namespace Tests\Feature;

use App\Repositories\AltnameRepository;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogAltnameTest extends TestCase {
    use RefreshDatabase;

    /** @test */
    public function it_logs_insert_operation(): void {
        $repo = app(AltnameRepository::class);
        
        $data = [
            'c_personid' => 123,
            'c_sequence' => 1,
            'c_alt_name_chn' => '測試名稱',
            'c_alt_name_type_code' => 1,
        ];
        
        $repo->store(123, $data);
        
        $auditLog = DB::table('audit_log')
            ->where('table_name', 'ALTNAME_DATA')
            ->where('operation', 'INSERT')
            ->latest('id')
            ->first();
        
        $this->assertNotNull($auditLog);
        $this->assertSame('c_personid=123&c_sequence=1&c_alt_name_chn=%E6%B8%AC%E8%A9%A6%E5%90%8D%E7%A8%B1&c_alt_name_type_code=1', $auditLog->row_pk_text);
        
        $newData = json_decode($auditLog->new_data, true);
        $this->assertSame('測試名稱', $newData['c_alt_name_chn']);
    }

    /** @test */
    public function it_logs_update_operation_with_old_and_new_data(): void {
        // ... 測試 UPDATE 操作
    }

    /** @test */
    public function it_logs_delete_operation_with_old_data(): void {
        // ... 測試 DELETE 操作
    }

    /** @test */
    public function it_rolls_back_on_audit_log_failure(): void {
        // ... 測試事務回滾
    }
}
```

### 驗收標準
- ✅ 11 個現有功能測試補充審計斷言
- ✅ 5 個新的專用審計測試創建完成
- ✅ 所有測試通過
- ✅ 測試覆蓋率達到 80% 以上

---

## 第三階段：完成剩餘整合 [P2]

### 📅 時間：2/26 - 3/15（3 週）
### 🎯 目標：整合剩餘 8 個未整合審計日誌的控制器

### 任務清單（按優先級排序）

#### 3.1 第一批：高變更頻率模組（1 週）
- [ ] `BasicInformationAssocController` → `AssocRepository` (ASSOC_DATA)
- [ ] `BasicInformationKinshipController` → `KinRepository` (KIN_DATA)
- [ ] `BasicInformationStatusesController` → `StatusRepository` (STATUS_DATA)

#### 3.2 第二批：中變更頻率模組（1 週）
- [ ] `BasicInformationEventsController` → `EventRepository` (EVENTS_DATA)
- [ ] `BasicInformationPossessionController` → `PossessionRepository` (POSSESSION_DATA)
- [ ] `BasicInformationSocialInstController` → `SocialInstRepository` (BIOG_INST_DATA)

#### 3.3 第三批：特殊模組（1 週）
- [ ] `BasicInformationProposalController` - 審批流程（若需要）
- [ ] 額外整合路徑檢查（API 端點、批次操作等）

### 每個模組的執行步驟
1. 創建 Repository（參考第一階段模板）
2. 實作 store/update/delete 方法（包含事務與審計）
3. 重構 Controller 使用 Repository
4. 撰寫單元測試與功能測試
5. 更新 `AUDIT_LOG_PROPOSAL.md` 進度追蹤器

### 驗收標準
- ✅ 8 個新 Repository 創建完成
- ✅ 所有 BasicInformation 控制器整合審計日誌
- ✅ 所有測試通過
- ✅ `AUDIT_LOG_PROPOSAL.md` 進度更新為 100%

---

## 第四階段：優化與監控（可選）

### 📅 時間：TBD（根據實際需求）
### 🎯 目標：效能優化與使用者體驗改進

### 任務清單
- [ ] 監控 `audit_log` 表成長速度
- [ ] 監控寫入操作延遲變化
- [ ] 添加索引（若查詢變慢）
- [ ] UI 改進（時間範圍篩選、匯出功能）

---

## 風險管理

### 風險 1：測試資料庫設定問題
**風險描述**：SQLite 與 MySQL 的行為差異可能導致測試通過但生產環境失敗  
**緩解措施**：
- 使用 `is_mysql()` / `is_sqlite()` 條件分支
- 在 CI/CD 中同時測試 SQLite 和 MySQL
- 避免使用資料庫專屬功能

### 風險 2：效能影響
**風險描述**：審計日誌寫入可能降低寫入操作效能  
**緩解措施**：
- 監控效能指標
- 若影響顯著，考慮非同步寫入（需評估一致性取捨）
- 優化索引策略

### 風險 3：儲存空間成長
**風險描述**：`audit_log` 表可能快速成長  
**緩解措施**：
- 監控表大小
- 規劃定期歸檔策略（如保留 1 年後移至歷史表）
- 考慮 JSON 欄位壓縮

---

## 進度追蹤

| 階段 | 任務數 | 完成數 | 進度 | 狀態 |
|------|--------|--------|------|------|
| 第一階段 | 14 | 0 | 0% | ⏸️ 待開始 |
| 第二階段 | 13 | 0 | 0% | ⏸️ 待開始 |
| 第三階段 | 24 | 0 | 0% | ⏸️ 待開始 |
| **總計** | **51** | **0** | **0%** | ⏸️ 待開始 |

---

## 附錄：參考資源

### 相關文件
- `docs/AUDIT_LOG_PROPOSAL.md` - 原始提案
- `docs/AUDIT_LOG_EVALUATION.md` - 現況評估
- `AGENTS.md` - 專案規範與最佳實踐

### 程式碼範例
- `app/Repositories/BiogMainRepository.php` - 事務包裹範例
- `app/Services/AuditLogService.php` - 核心服務
- `tests/Unit/AuditLogServiceTest.php` - 測試範例

### 工具與指令
```bash
# 執行完整測試
./vendor/bin/phpunit

# 執行特定測試
./vendor/bin/phpunit tests/Unit/AuditLogServiceTest.php

# 程式碼格式化
./vendor/bin/php-cs-fixer fix

# 檢查 Migration
php artisan migrate:status
```
