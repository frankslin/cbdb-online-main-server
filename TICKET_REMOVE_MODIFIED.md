# 技術提案：移除 `/modified` 頁面並整合至 `/operations`

**類型**: 重構 (Refactoring)
**優先級**: 中
**預估工時**: 2-3 天
**標籤**: `refactoring`, `code-cleanup`, `operations`

---

## 📋 問題描述

目前系統存在兩個功能高度重複的操作記錄頁面：
- `/operations` - 操作記錄頁面
- `/modified` - 修改記錄頁面

兩者查詢同一張 `operations` 資料表，顯示幾乎相同的內容，造成代碼重複維護和使用者困惑。

---

## 🔍 現況分析

### 代碼重複情況

#### Controller 層

**ModifiedController.php** (136 行)
```php
public function index() {
    $lists = Operation::whereIn('crowdsourcing_status', [0,1])
        ->orderBy('updated_at', 'desc')
        ->limit(100)  // 此行實際上會被 paginate() 忽略
        ->paginate(20);

    // 差異比對邏輯（僅支援 5 種資源類型）
    for ($x = 0; $x < $all; $x++) {
        switch ($resource) {
            case "OFFICE_CODES":
            case "OFFICE_CODE_TYPE_REL":
            case "OFFICE_TYPE_TREE":
            case "POSTED_TO_ADDR_DATA":
            case "BIOG_MAIN":
            // ...
        }
    }
}
```

**OperationsController.php** (809 行)
```php
public function index(Request $request) {
    $query = Operation::where('crowdsourcing_status', 0);

    if ($proposalsOnly) {
        $query->whereIn('op_type', [
            Operation::TYPE_PROPOSAL_CREATE,
            Operation::TYPE_PROPOSAL_UPDATE,
        ]);
    }

    $lists = $query->orderBy('updated_at', 'desc')->paginate(20);

    // 差異比對邏輯（支援 15+ 種資源類型）
    for ($x = 0; $x < $all; $x++) {
        switch ($resource) {
            case "BIOG_MAIN":
            case "OFFICE_CODES":
            case "OFFICE_CODE_TYPE_REL":
            case "OFFICE_TYPE_TREE":
            case "BIOG_ADDR_DATA":
            case "ALTNAME_DATA":
            case "BIOG_TEXT_DATA":
            case "POSTED_TO_OFFICE_DATA":
            case "POSTED_TO_ADDR_DATA":
            case "ENTRY_DATA":
            case "EVENTS_DATA":
            case "STATUS_DATA":
            case "KIN_DATA":
            case "ASSOC_DATA":
            case "POSSESSION_DATA":
            case "BIOG_INST_DATA":
            case "BIOG_SOURCE_DATA":
            // ... 完整的差異比對邏輯
        }
    }
}
```

**問題**：
- 差異比對邏輯重複（約 400 行代碼）
- `/modified` 的差異比對功能不完整（只支援 5 種資源，`/operations` 支援 15+ 種）
- 未來新增資源類型時需要同步修改兩處

#### 視圖層

**modified/index.blade.php** (419 行)
**operations/index.blade.php** (463 行)

**差異對比**：
```diff
--- modified/index.blade.php
+++ operations/index.blade.php

+   @if(!empty($proposals_only))
+       {{-- 提案狀態篩選表單 --}}
+   @endif

    <thead>
    <tr>
        <th>人物</th>
        <th>修改資源</th>
        <th>修改值</th>
        <th>資源 TTS</th>
        <th>修改類型</th>
        <th>修改人</th>
-       <th>錄入時間</th>
        <th>修改時間</th>
-       <th>狀態</th>
    </tr>
    </thead>

-   <p>* 修改類型 0表示crowdsourcing記錄，...</p>
```

**相似度**: 視圖層代碼 **90% 相同**，僅差異在：
- `/operations` 支援提案管理功能
- `/modified` 顯示「錄入時間」和「狀態」欄位
- `/modified` 有說明文字

---

## ⚠️ 核心差異（僅有的差異）

| 功能 | `/modified` | `/operations` |
|------|-------------|---------------|
| **資料筆選** | `crowdsourcing_status IN (0, 1)` | `crowdsourcing_status = 0` |
| **顯示欄位** | ✅ 錄入時間<br/>✅ 狀態 | ❌ 無 |
| **說明文字** | ✅ 修改類型和狀態說明 | ❌ 無 |
| **提案管理** | ❌ 無 | ✅ 完整支援 |
| **操作復原** | ❌ 無 | ✅ 支援 |
| **差異比對** | ⚠️ 部分支援（5 種資源） | ✅ 完整支援（15+ 種資源） |

**結論**: `/modified` 唯一的獨特價值是「顯示眾包記錄」，其他功能均為 `/operations` 的子集或退化版本。

---

## 💡 提案內容

### 目標

將 `/modified` 的功能整合至 `/operations`，通過參數化實現功能切換，消除代碼重複。

### 實施方案

#### 階段一：功能整合（第 1-2 天）

**1. 提取共用邏輯到 Service**

```php
// app/Services/OperationDiffService.php

<?php

namespace App\Services;

use App\Models\BiogMain;
use App\Repositories\OperationRepository;
use Illuminate\Support\Facades\DB;

/**
 * 操作記錄差異比對服務
 *
 * 統一處理所有資源類型的實時差異比對邏輯
 */
class OperationDiffService {
    protected $operationRepository;

    public function __construct(OperationRepository $operationRepository) {
        $this->operationRepository = $operationRepository;
    }

    /**
     * 為 operations 列表附加差異比對資料
     *
     * @param \Illuminate\Pagination\LengthAwarePaginator $lists
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function attachDiffData($lists) {
        $listsArr = $this->operationRepository->objectToArray($lists);
        $dataRows = isset($listsArr['data']) && is_array($listsArr['data'])
            ? $listsArr['data']
            : [];

        $all = count($dataRows);

        for ($x = 0; $x < $all; $x++) {
            $resource = $dataRows[$x]['resource'];
            $arr1 = $dataRows[$x]['resource_data'];
            $arr2 = $dataRows[$x]['resource_original'];

            // 獲取當前資料庫狀態
            $arr3 = $this->fetchCurrentState($dataRows[$x]);

            // 計算差異
            $diff = $this->calculateDiff($resource, $arr1, $arr2, $arr3);

            $lists[$x]->setAttribute('resource_diff', $diff);
        }

        return $lists;
    }

    /**
     * 獲取資源的當前資料庫狀態
     */
    protected function fetchCurrentState(array $operationRow) {
        $c_personid = $operationRow['c_personid'] ?? null;
        $resource = $operationRow['resource'] ?? null;
        $resource_id = $operationRow['resource_id'] ?? null;

        if (!empty($c_personid) && $resource === 'BIOG_MAIN') {
            $person = BiogMain::find($c_personid);
            return $person ? $person->toArray() : [];
        }

        if (empty($resource_id) || empty($resource)) {
            return [];
        }

        // 支援所有 15+ 種資源類型的邏輯
        // （將 OperationsController::index() 的 switch case 移至此處）
        switch ($resource) {
            case "OFFICE_CODES":
                // ...
            case "BIOG_ADDR_DATA":
                // ...
            case "ALTNAME_DATA":
                // ...
            // ... 其他所有資源類型
            default:
                return [];
        }
    }

    /**
     * 計算差異
     */
    protected function calculateDiff(string $resource, $arr1, $arr2, $arr3) {
        $arr1Decoded = json_decode($arr1, true) ?: [];
        $arr2Decoded = json_decode($arr2, true) ?: [];

        if ($resource === 'POSTED_TO_ADDR_DATA') {
            $currentRows = is_array($arr3) ? ($arr3['rows'] ?? []) : [];
            return $this->operationRepository->buildPostedToAddrDiff(
                $arr1Decoded['rows'] ?? [],
                $arr2Decoded['rows'] ?? [],
                $currentRows
            );
        } elseif (!empty($arr2)) {
            return $this->operationRepository->getArrDiff(
                $arr1Decoded,
                $arr2Decoded,
                $arr3
            );
        }

        return null;
    }
}
```

**2. 修改 OperationsController**

```php
// app/Http/Controllers/OperationsController.php

use App\Services\OperationDiffService;

class OperationsController extends Controller {
    protected $operationRepository;
    protected $diffService;

    public function __construct(
        OperationRepository $operationRepository,
        OperationDiffService $diffService
    ) {
        $this->operationRepository = $operationRepository;
        $this->diffService = $diffService;
    }

    public function index(Request $request) {
        // 新增參數：是否包含眾包記錄
        $includeCrowdsourcing = filter_var(
            $request->input('include_crowdsourcing', false),
            FILTER_VALIDATE_BOOLEAN
        );

        // 新增參數：是否顯示詳細欄位
        $showDetailedColumns = filter_var(
            $request->input('show_details', $includeCrowdsourcing),
            FILTER_VALIDATE_BOOLEAN
        );

        $proposalsOnly = filter_var(
            $request->input('proposals_only', false),
            FILTER_VALIDATE_BOOLEAN
        );

        // 資料查詢
        $query = Operation::query();
        $statusFilters = [];

        if ($proposalsOnly) {
            $query->where('crowdsourcing_status', 0);
            $query->whereIn('op_type', [
                Operation::TYPE_PROPOSAL_CREATE,
                Operation::TYPE_PROPOSAL_UPDATE,
            ]);

            // 提案狀態篩選邏輯（現有代碼）
            // ...
        } elseif ($includeCrowdsourcing) {
            // 相當於舊的 /modified 頁面
            $query->whereIn('crowdsourcing_status', [0, 1]);
        } else {
            // 預設只顯示專業用戶記錄
            $query->where('crowdsourcing_status', 0);
        }

        $lists = $query->orderBy('updated_at', 'desc')->paginate(20);
        $lists->appends($request->except('page'));

        // 使用 Service 處理差異比對（取代原有的 400 行邏輯）
        $lists = $this->diffService->attachDiffData($lists);

        // 頁面標題
        if ($proposalsOnly) {
            $pageTitle = '最近提案列表';
            $pageTitleKey = 'OperationsProposals';
        } elseif ($includeCrowdsourcing) {
            $pageTitle = '最近修改記錄（含眾包）';
            $pageTitleKey = 'ModifiedWithCrowdsourcing';
        } else {
            $pageTitle = '最近操作記錄';
            $pageTitleKey = 'NewUpdate';
        }

        return view('operations.index', [
            'lists' => $lists,
            'page_title' => $pageTitle,
            'page_title_key' => $pageTitleKey,
            'page_description' => $pageTitle,
            'page_url' => '/operations',
            'proposals_only' => $proposalsOnly,
            'include_crowdsourcing' => $includeCrowdsourcing,
            'show_detailed_columns' => $showDetailedColumns,
            'status_filters' => $statusFilters,
        ]);
    }

    // ... 其他方法保持不變
}
```

**3. 更新視圖**

```blade
{{-- resources/views/operations/index.blade.php --}}

@extends('layouts.dashboard-v3')

@section('content')
@include('biogmains.defense')
    <div class="card card-default">
        <div class="card-body">
            {{-- 頁面模式切換按鈕 --}}
            <div class="btn-group mb-3" role="group" aria-label="頁面模式">
                <a href="{{ route('operations.index') }}"
                   class="btn btn-{{ !$include_crowdsourcing && !$proposals_only ? 'primary' : 'outline-primary' }}">
                    專業用戶記錄
                </a>
                <a href="{{ route('operations.index', ['include_crowdsourcing' => 1, 'show_details' => 1]) }}"
                   class="btn btn-{{ $include_crowdsourcing ? 'primary' : 'outline-primary' }}">
                    所有記錄（含眾包）
                </a>
                <a href="{{ route('operations.index', ['proposals_only' => 1]) }}"
                   class="btn btn-{{ $proposals_only ? 'primary' : 'outline-primary' }}">
                    提案列表
                </a>
            </div>

            {{-- 說明文字（僅在眾包模式顯示） --}}
            @if($include_crowdsourcing && $show_detailed_columns)
                <div class="alert alert-info">
                    <strong>說明：</strong>
                    <ul class="mb-0">
                        <li>修改類型：0=crowdsourcing記錄，1=新增，2=整體覆寫，3=修改，4=刪除，8=新增提案，9=修改提案</li>
                        <li>狀態：0=專業用戶修改，1=crowdsourcing記錄已插入資料庫</li>
                    </ul>
                </div>
            @endif

            @if(!empty($proposals_only))
                {{-- 提案狀態篩選表單（現有代碼） --}}
            @endif

            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                <thead>
                <tr>
                    <th>人物</th>
                    <th>修改資源</th>
                    <th>修改值</th>
                    <th>資源 TTS</th>
                    <th>修改類型</th>
                    <th>修改人</th>
                    @if($show_detailed_columns ?? false)
                        <th>錄入時間</th>
                    @endif
                    <th>修改時間</th>
                    @if($show_detailed_columns ?? false)
                        <th>狀態</th>
                    @endif
                </tr>
                </thead>
                <tbody>
                    {{-- 現有的表格內容 --}}
                    @foreach($lists as $item)
                        <tr>
                            {{-- ... --}}
                            @if($show_detailed_columns ?? false)
                                <td>{{ $item->created_at }}</td>
                            @endif
                            <td>{{ $item->updated_at }}</td>
                            @if($show_detailed_columns ?? false)
                                <td>{{ $item->crowdsourcing_status }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
                </table>
            </div>

            {{ $lists->links() }}
        </div>
    </div>
@endsection
```

**4. 設定路由重定向**

```php
// routes/web.php

// 保留 /modified 路由，但重定向到 /operations
Route::get('modified', function () {
    return redirect()->route('operations.index', [
        'include_crowdsourcing' => 1,
        'show_details' => 1,
    ]);
})->name('modified.index');

// 原有的 operations 路由保持不變
Route::get('operations', ['as' => 'operations.index', 'uses' => 'OperationsController@index']);
// ...
```

#### 階段二：清理代碼（第 3 天）

**1. 刪除過時文件**

```bash
# 刪除 Controller
rm app/Http/Controllers/ModifiedController.php

# 刪除視圖
rm -rf resources/views/modified/

# 確認沒有其他引用
grep -r "ModifiedController" app/
grep -r "modified.index" resources/
grep -r "route('modified" app/
```

**2. 更新導航選單**

```blade
{{-- resources/views/layouts/sidebar-v3.blade.php --}}

- <li class="nav-item">
-     <a href="{{ route('modified.index') }}" class="nav-link">
-         <i class="nav-icon fas fa-edit"></i>
-         <p>最近修改記錄</p>
-     </a>
- </li>

+ <li class="nav-item">
+     <a href="{{ route('operations.index', ['include_crowdsourcing' => 1, 'show_details' => 1]) }}" class="nav-link">
+         <i class="nav-icon fas fa-history"></i>
+         <p>所有修改記錄</p>
+     </a>
+ </li>
```

**3. 更新文檔**

- `AGENTS.md`：移除 `/modified` 的相關說明
- `CHANGELOG.md`：記錄此次重構

---

## ✅ 優勢

1. **消除代碼重複**
   - 刪除 ~550 行重複代碼（136 行 Controller + 419 行視圖）
   - 差異比對邏輯統一維護

2. **功能完整性**
   - 所有記錄（包括眾包）都能享受完整的差異比對功能（15+ 種資源類型）
   - 未來新增資源類型只需修改一處

3. **使用者體驗改善**
   - 統一的介面，減少學習成本
   - 通過按鈕快速切換不同模式
   - 保留舊連結（重定向），不會出現 404

4. **可維護性**
   - 單一職責：`OperationDiffService` 專門處理差異比對
   - 易於測試：Service 可獨立單元測試
   - 參數化設計：未來擴展更容易

5. **向後兼容**
   - `/modified` 路由繼續有效（重定向）
   - 現有書籤和連結不會失效

---

## ⚠️ 風險評估

### 風險 1：使用者習慣改變

**描述**: 用戶可能習慣直接訪問 `/modified` 頁面。

**緩解措施**:
- 保留 `/modified` 路由，自動重定向
- 在重定向時顯示提示訊息（可選）
- 更新系統公告通知用戶

**影響**: 低

### 風險 2：視圖層複雜度增加

**描述**: 單一視圖需要通過參數控制不同顯示模式。

**緩解措施**:
- 使用清晰的 `@if` 條件判斷
- 添加註解說明每個模式的用途
- 考慮未來拆分成 partial views

**影響**: 低

### 風險 3：效能影響

**描述**: `OperationDiffService` 需要查詢資料庫獲取當前狀態。

**緩解措施**:
- 保持現有的分頁機制（每頁 20 筆）
- 考慮未來添加快取機制
- 監控查詢效能

**影響**: 極低（與現有邏輯相同）

---

## 📝 測試計劃

### 單元測試

```php
// tests/Unit/Services/OperationDiffServiceTest.php

class OperationDiffServiceTest extends TestCase {
    public function test_attach_diff_data_for_biog_main() {
        // 測試 BIOG_MAIN 資源的差異比對
    }

    public function test_attach_diff_data_for_altname() {
        // 測試 ALTNAME_DATA 資源的差異比對
    }

    // ... 測試所有資源類型
}
```

### 功能測試

```php
// tests/Feature/OperationsControllerTest.php

class OperationsControllerTest extends TestCase {
    public function test_operations_index_default_mode() {
        // 測試預設模式（只顯示專業用戶）
        $response = $this->get('/operations');
        $response->assertStatus(200);
        $response->assertSee('最近操作記錄');
    }

    public function test_operations_index_with_crowdsourcing() {
        // 測試眾包模式
        $response = $this->get('/operations?include_crowdsourcing=1&show_details=1');
        $response->assertStatus(200);
        $response->assertSee('所有記錄（含眾包）');
        $response->assertSee('錄入時間');
        $response->assertSee('狀態');
    }

    public function test_modified_route_redirects() {
        // 測試 /modified 重定向
        $response = $this->get('/modified');
        $response->assertRedirect('/operations?include_crowdsourcing=1&show_details=1');
    }

    public function test_proposals_only_mode() {
        // 測試提案模式
        $response = $this->get('/operations?proposals_only=1');
        $response->assertStatus(200);
        $response->assertSee('最近提案列表');
    }
}
```

### 手動測試檢查清單

- [ ] 訪問 `/operations` 預設只顯示專業用戶記錄
- [ ] 訪問 `/operations?include_crowdsourcing=1` 顯示所有記錄
- [ ] 訪問 `/modified` 自動重定向並顯示正確內容
- [ ] 點擊「所有記錄（含眾包）」按鈕切換模式
- [ ] 確認「錄入時間」和「狀態」欄位僅在眾包模式顯示
- [ ] 確認說明文字僅在眾包模式顯示
- [ ] 確認差異比對功能對所有資源類型有效
- [ ] 確認提案管理功能在眾包模式下也能正常運作

---

## 📊 驗收標準

1. ✅ `/modified` 路由重定向到 `/operations?include_crowdsourcing=1&show_details=1`
2. ✅ `/operations` 支援三種模式切換（專業用戶/所有記錄/提案列表）
3. ✅ 眾包模式顯示「錄入時間」和「狀態」欄位
4. ✅ 差異比對功能支援所有 15+ 種資源類型
5. ✅ `ModifiedController.php` 和 `resources/views/modified/` 已刪除
6. ✅ 所有測試通過（單元測試 + 功能測試）
7. ✅ 代碼格式化檢查通過 (`php-cs-fixer fix`)
8. ✅ 文檔已更新（`AGENTS.md`, `CHANGELOG.md`）

---

## 📚 參考資料

- [OperationsController.php](app/Http/Controllers/OperationsController.php) - 當前實現
- [ModifiedController.php](app/Http/Controllers/ModifiedController.php) - 待刪除
- [AGENTS.md](AGENTS.md) - 專案開發指南

---

## 🔄 後續優化建議（可選）

完成基本重構後，可考慮進一步優化：

1. **快取機制**：為常用資源的當前狀態查詢添加快取
2. **非同步載入**：差異比對改為 AJAX 按需載入，減少初始頁面載入時間
3. **導出功能**：支援導出操作記錄為 CSV/Excel
4. **進階篩選**：添加日期範圍、操作人、資源類型等篩選條件

---

## 💬 討論

**問題**: 是否需要保留 `/modified` 路由？

**建議**:
- ✅ 保留（透過重定向），避免舊連結失效
- 可在 6 個月後評估使用情況，決定是否完全移除

**問題**: 視圖層是否會變得過於複雜？

**建議**:
- 目前透過參數控制顯示，複雜度可接受
- 如未來需求增加，可考慮拆分為多個 partial views

---

**提案人**: Claude (AI Assistant)
**日期**: 2025-12-29
**版本**: 1.0
