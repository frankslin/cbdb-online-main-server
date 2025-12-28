# Select 组件测试指南

本文档提供完整的 Select 组件（Select.vue、AsyncSelect.vue）回归测试策略，解决 UI 测试依赖后端和数据库的挑战。

## 测试金字塔策略

我们采用**分层测试策略**，从快到慢、从简单到复杂：

```
        ┌─────────────────┐
        │   E2E 测试      │  ← 最慢，最真实
        │  (Playwright)   │
        ├─────────────────┤
        │  Feature 测试   │  ← 中速，测试 API
        │  (PHPUnit)      │
        ├─────────────────┤
        │  组件单元测试   │  ← 最快，Mock 一切
        │  (Vitest)       │
        └─────────────────┘
```

---

## 策略 1：前端组件单元测试 ⚡（推荐起点）

### 优势
- ✅ **最快**：无需启动服务器或数据库
- ✅ **隔离性好**：只测试组件逻辑
- ✅ **易于调试**：错误定位精确
- ✅ **CI 友好**：运行快，资源消耗低

### 技术栈
- **Vitest**：快速的 Vite 原生测试框架
- **Vue Test Utils**：Vue 官方测试工具
- **Mock Axios**：模拟 API 响应

### 安装依赖

```bash
npm install -D vitest @vue/test-utils @vitest/ui jsdom
```

### 配置 Vitest

```javascript
// vitest.config.js
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import path from 'path';

export default defineConfig({
    plugins: [vue()],
    test: {
        globals: true,
        environment: 'jsdom',
        setupFiles: ['./tests/JavaScript/setup.js'],
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
});
```

### 运行测试

```bash
# 运行所有前端测试
npm run test

# 监听模式（开发时推荐）
npm run test:watch

# 生成覆盖率报告
npm run test:coverage

# 打开 UI 界面
npm run test:ui
```

### 测试示例

参考文件：`tests/JavaScript/AsyncSelect.spec.js`

**关键测试场景**：
- ✅ 组件渲染
- ✅ Props 验证
- ✅ 初始值加载（同步 + 异步）
- ✅ Select2 初始化配置
- ✅ API 响应处理
- ✅ 组件销毁清理

---

## 策略 2：Laravel Feature 测试 🔧

### 优势
- ✅ **测试真实 API**：验证后端逻辑
- ✅ **数据库隔离**：使用 In-Memory SQLite
- ✅ **固定测试数据**：Seeder 提供可预测数据
- ✅ **快速重置**：每次测试自动重建数据库

### 技术栈
- **PHPUnit 10.1**：Laravel 内置测试框架
- **RefreshDatabase**：自动重建数据库
- **TestSelectDataSeeder**：固定测试数据

### 准备测试数据

```bash
# 运行测试数据填充（仅测试环境）
php artisan db:seed --class=TestSelectDataSeeder --env=testing
```

### 运行测试

```bash
# 运行所有 API 测试
./vendor/bin/phpunit --filter SelectApiTest

# 运行单个测试方法
./vendor/bin/phpunit --filter test_text_search_returns_correct_structure

# 生成覆盖率报告
./vendor/bin/phpunit --coverage-html coverage/
```

### 测试数据说明

`TestSelectDataSeeder` 提供以下固定数据集：

| 表格 | 数据量 | 用途 |
|------|--------|------|
| TEXT_DATA | 12 条 | 文献搜索测试 |
| ADDRESSES | 8 条 | 地址搜索测试 |
| OFFICES | 5 条 | 官职搜索测试 |
| BIOG_MAIN | 3 条 | 人物搜索测试 |
| 代码表 | 若干 | 下拉选择测试 |

**关键 ID**：
- 文献 123：四庫全書
- 文献 456：資治通鑑
- 人物 1001：蘇軾
- 地址 1：北京

### 测试示例

参考文件：`tests/Feature/Api/SelectApiTest.php`

**关键测试场景**：
- ✅ API 响应结构验证
- ✅ 搜索过滤逻辑
- ✅ 分页功能
- ✅ 按 ID 查询
- ✅ 节流限制
- ✅ 性能测试

---

## 策略 3：E2E 端到端测试 🌐

### 优势
- ✅ **最接近真实用户**：测试完整交互流程
- ✅ **捕获 UI 问题**：CSS、布局、动画
- ✅ **跨浏览器测试**：Chrome、Firefox、Safari
- ✅ **可视化调试**：录屏、截图、追踪

### 技术栈
- **Playwright**：现代 E2E 测试框架
- **Mock API**：可选，避免数据库依赖

### 安装 Playwright

```bash
# 安装 Playwright
npm install -D @playwright/test

# 安装浏览器（首次）
npx playwright install
```

### 运行测试

```bash
# 运行所有 E2E 测试
npx playwright test

# 运行单个测试文件
npx playwright test select-components.spec.js

# 调试模式（逐步执行）
npx playwright test --debug

# 查看报告
npx playwright show-report

# 指定浏览器
npx playwright test --project=chromium
```

### Mock vs 真实数据库

#### 选项 A：Mock API（推荐用于 CI）

```javascript
test.beforeEach(async ({ page }) => {
    await page.route('**/api/select/search/text*', async (route) => {
        await route.fulfill({
            status: 200,
            body: JSON.stringify(mockTextData)
        });
    });
});
```

**优点**：快速、稳定、无数据库依赖

#### 选项 B：真实测试数据库

```bash
# 启动测试环境
APP_ENV=testing php artisan serve --port=8001

# 运行测试（指向测试服务器）
APP_URL=http://localhost:8001 npx playwright test
```

**优点**：测试真实数据流、发现集成问题

### 测试示例

参考文件：`tests/E2E/select-components.spec.js`

**关键测试场景**：
- ✅ 下拉框渲染
- ✅ 初始值显示
- ✅ 搜索交互
- ✅ 选择项目
- ✅ 多选功能
- ✅ 分页加载
- ✅ 表单提交
- ✅ 键盘导航
- ✅ 错误处理

---

## 测试数据管理策略

### 方案 A：固定 Fixture 数据 ⭐（推荐）

**位置**：`tests/JavaScript/fixtures/apiResponses.js`

**优点**：
- 完全可控，测试可重复
- 无需数据库连接
- 适合前端和 E2E 测试

**使用示例**：

```javascript
import { createMockApiResponse } from '@/tests/JavaScript/fixtures/apiResponses';

// 生成 Mock 数据
const mockData = createMockApiResponse('text', { q: '史', page: 1 });
```

### 方案 B：Seeder 数据库

**位置**：`database/seeders/TestSelectDataSeeder.php`

**优点**：
- 测试真实数据库交互
- 适合 Feature 测试
- 可生成大量数据（压力测试）

**使用示例**：

```php
// 在测试中自动填充
use RefreshDatabase;

protected function setUp(): void {
    parent::setUp();
    $this->seed(TestSelectDataSeeder::class);
}
```

### 方案 C：Factory + Seeder 组合

**适用场景**：需要随机数据但保持一致性

```php
// 创建 100 个随机文献，但保留特定 ID
Text::factory()->count(100)->create();
Text::factory()->create(['c_text_id' => 123, 'c_text_name_chn' => '四庫全書']);
```

---

## CI/CD 集成

### GitHub Actions 配置

```yaml
name: Tests

on: [push, pull_request]

jobs:
  frontend-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - uses: actions/setup-node@v3
        with:
          node-version: 18
      - run: npm ci
      - run: npm run test

  backend-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - uses: shivammathur/setup-php@v2
        with:
          php-version: 8.4
      - run: composer install
      - run: ./vendor/bin/phpunit

  e2e-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - uses: actions/setup-node@v3
      - run: npm ci
      - run: npx playwright install --with-deps
      - run: npx playwright test
      - uses: actions/upload-artifact@v3
        if: always()
        with:
          name: playwright-report
          path: playwright-report/
```

---

## 测试覆盖率目标

| 测试类型 | 覆盖目标 | 重点 |
|---------|---------|------|
| **组件单元测试** | 80%+ | 组件逻辑、Props、事件 |
| **Feature 测试** | 100% API 端点 | 所有 `/api/select/*` 路由 |
| **E2E 测试** | 关键用户流程 | 创建、编辑、搜索、提交 |

---

## 最佳实践总结

### ✅ DO（推荐）

1. **优先写单元测试**：快速验证逻辑
2. **使用 Fixture 数据**：确保测试可重复
3. **Mock 外部依赖**：隔离被测单元
4. **测试边界条件**：空值、错误、极端情况
5. **保持测试独立**：每个测试自包含
6. **命名清晰**：`test_should_return_paginated_results_when_searching`

### ❌ DON'T（避免）

1. **依赖生产数据库**：测试应该隔离
2. **测试间共享状态**：导致不稳定
3. **测试实现细节**：应测试行为，而非内部实现
4. **忽略异步问题**：使用 `flushPromises()` 等待
5. **过度 Mock**：Mock 太多会失去真实性
6. **忽略性能**：测试套件应在 5 分钟内完成

---

## 故障排查指南

### 问题 1：Vitest 找不到模块

```bash
# 检查 vitest.config.js 的 alias 配置
# 确保 @/ 指向 resources/js
```

### 问题 2：PHPUnit 数据库错误

```bash
# 确保使用 In-Memory SQLite
# phpunit.xml 配置：
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

### 问题 3：Playwright 超时

```bash
# 增加超时时间
npx playwright test --timeout=60000

# 或在配置中调整
timeout: 60 * 1000
```

### 问题 4：Select2 未初始化

```javascript
// 确保等待 Vite 加载完成
await page.waitForFunction(() => window.viteReady === true);
```

---

## 快速开始检查清单

- [ ] 安装前端测试依赖：`npm install -D vitest @vue/test-utils`
- [ ] 安装 Playwright：`npm install -D @playwright/test`
- [ ] 创建测试数据 Seeder：`TestSelectDataSeeder.php`
- [ ] 创建 Fixture 数据：`tests/JavaScript/fixtures/apiResponses.js`
- [ ] 运行前端测试：`npm run test`
- [ ] 运行后端测试：`./vendor/bin/phpunit --filter SelectApiTest`
- [ ] 运行 E2E 测试：`npx playwright test`
- [ ] 配置 CI：添加 GitHub Actions 工作流
- [ ] 设置覆盖率目标：至少 80% 组件覆盖率

---

## 相关资源

- [Vitest 文档](https://vitest.dev/)
- [Vue Test Utils](https://test-utils.vuejs.org/)
- [Playwright 文档](https://playwright.dev/)
- [PHPUnit 文档](https://phpunit.de/)
- [Laravel Testing](https://laravel.com/docs/10.x/testing)

---

**最后更新**：2025-12-28
**维护者**：开发团队
