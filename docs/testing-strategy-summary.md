# Select 组件测试策略总结

## 你的问题：UI 回归测试很难搞

✅ **解决方案**：采用**三层测试金字塔**，避免完全依赖后端数据库。

---

## 推荐方案对比表

| 测试层级 | 速度 | 真实性 | 数据依赖 | 适用场景 | 推荐指数 |
|---------|------|--------|---------|---------|---------|
| **组件单元测试** | ⚡⚡⚡ 快（秒级） | ⭐⭐ 中 | ❌ 无需数据库 | 组件逻辑验证 | ⭐⭐⭐⭐⭐ |
| **Feature 测试** | ⚡⚡ 中（秒-分） | ⭐⭐⭐ 高 | ✅ 测试数据库 | API 端点验证 | ⭐⭐⭐⭐ |
| **E2E 测试** | ⚡ 慢（分级） | ⭐⭐⭐⭐ 最高 | ⚠️ 可选 Mock | 关键用户流程 | ⭐⭐⭐ |

---

## 快速决策树

```
需要测试 UI 组件？
│
├─ 测试组件逻辑？ → 组件单元测试（Vitest）
│   └─ Mock API 响应，快速验证
│
├─ 测试 API 功能？ → Feature 测试（PHPUnit）
│   └─ 使用 Seeder 固定数据，In-Memory 数据库
│
└─ 测试完整流程？ → E2E 测试（Playwright）
    └─ Mock API（快）或真实数据库（慢但真实）
```

---

## 实际操作建议

### 阶段 1：从单元测试开始（1-2 天）⭐

**为什么先做这个？**
- ✅ 无需配置数据库
- ✅ 运行速度快（CI 友好）
- ✅ 覆盖 80% 的组件逻辑

**步骤**：

```bash
# 1. 安装依赖
npm install -D vitest @vue/test-utils jsdom @vitest/ui

# 2. 已创建好的文件
# - vitest.config.js（配置）
# - tests/JavaScript/setup.js（环境设置）
# - tests/JavaScript/AsyncSelect.spec.js（示例测试）
# - tests/JavaScript/fixtures/apiResponses.js（Mock 数据）

# 3. 运行测试
npm run test

# 4. 监听模式（开发时）
npm run test:watch
```

**测试什么**：
- ✅ Props 传递是否正确
- ✅ 初始值加载逻辑
- ✅ Select2 配置是否正确
- ✅ 组件销毁是否清理资源

**不测试什么**：
- ❌ Select2 内部实现（第三方库）
- ❌ 真实 API 响应（交给 Feature 测试）
- ❌ CSS 样式（交给 E2E 或视觉测试）

---

### 阶段 2：添加 Feature 测试（1 天）

**为什么需要这个？**
- ✅ 验证 API 端点真实逻辑
- ✅ 测试数据库查询和分页
- ✅ 捕获后端 Bug

**步骤**：

```bash
# 1. 已创建好的文件
# - database/seeders/TestSelectDataSeeder.php（测试数据）
# - tests/Feature/Api/SelectApiTest.php（API 测试）

# 2. 配置 phpunit.xml（确保使用 In-Memory SQLite）
# <env name="DB_CONNECTION" value="sqlite"/>
# <env name="DB_DATABASE" value=":memory:"/>

# 3. 运行测试
./vendor/bin/phpunit --filter SelectApiTest

# 4. 查看覆盖率
./vendor/bin/phpunit --coverage-html coverage/
```

**测试什么**：
- ✅ `/api/select/search/{model}` 返回正确结构
- ✅ 搜索关键词过滤
- ✅ 分页逻辑
- ✅ 按 ID 查询
- ✅ 节流限制

---

### 阶段 3：关键流程 E2E 测试（2-3 天）

**为什么最后做这个？**
- ⚠️ 速度最慢，维护成本高
- ⚠️ 需要配置浏览器环境
- ✅ 但能捕获真实用户问题

**步骤**：

```bash
# 1. 安装 Playwright
npm install -D @playwright/test
npx playwright install

# 2. 已创建好的文件
# - playwright.config.js（配置）
# - tests/E2E/select-components.spec.js（E2E 测试）

# 3. 运行测试
npm run e2e

# 4. 调试模式（逐步执行）
npm run e2e:debug

# 5. 查看报告
npx playwright show-report
```

**测试什么**：
- ✅ 创建新记录（选择文献、地址等）
- ✅ 编辑现有记录（初始值加载）
- ✅ 搜索和选择交互
- ✅ 表单提交

**技巧**：优先 Mock API，避免数据库依赖

```javascript
// Mock API 响应（推荐）
await page.route('**/api/select/search/text*', route => {
    route.fulfill({ body: JSON.stringify(mockData) });
});
```

---

## 数据管理策略对比

### 方案 A：Fixture 数据（推荐）⭐

**文件**：`tests/JavaScript/fixtures/apiResponses.js`

**优点**：
- ✅ 完全可控，测试稳定
- ✅ 快速，无数据库连接
- ✅ 可复用（单元测试 + E2E）

**缺点**：
- ❌ 需要手动维护数据
- ❌ 不测试真实数据库逻辑

**适用场景**：
- 前端组件单元测试
- E2E 测试（Mock 模式）

**示例**：

```javascript
import { createMockApiResponse } from '@/tests/JavaScript/fixtures/apiResponses';

const mockData = createMockApiResponse('text', { q: '史', page: 1 });
// 返回固定的测试数据
```

---

### 方案 B：Seeder 数据库

**文件**：`database/seeders/TestSelectDataSeeder.php`

**优点**：
- ✅ 测试真实数据库交互
- ✅ 发现 SQL 查询问题
- ✅ 可生成大量数据（压力测试）

**缺点**：
- ❌ 需要数据库连接（In-Memory SQLite 缓解）
- ❌ 速度较慢

**适用场景**：
- Laravel Feature 测试
- E2E 测试（真实模式）

**示例**：

```php
use RefreshDatabase;

protected function setUp(): void {
    parent::setUp();
    $this->seed(TestSelectDataSeeder::class); // 自动填充测试数据
}
```

---

### 方案 C：混合模式（最佳实践）⭐⭐⭐

**策略**：
- 单元测试：Fixture 数据（快速）
- Feature 测试：Seeder 数据（真实）
- E2E 测试：Fixture Mock（快速）+ 定期真实数据回归

**配置 E2E 两种模式**：

```bash
# 快速模式（Mock API，适合日常开发）
npm run e2e

# 真实模式（连接测试数据库，适合发布前）
APP_ENV=testing npm run e2e
```

---

## 覆盖率建议

| 组件 | 单元测试 | Feature 测试 | E2E 测试 |
|------|---------|------------|---------|
| **AsyncSelect.vue** | 80%+ 代码覆盖 | - | 关键流程 |
| **PersonSelect.vue** | 80%+ 代码覆盖 | - | 人物搜索流程 |
| **API 端点** | - | 100% 路由 | - |
| **表单页面** | - | - | 增删改查流程 |

---

## CI/CD 集成建议

### GitHub Actions 配置

```yaml
# .github/workflows/tests.yml
name: Tests

on: [push, pull_request]

jobs:
  unit-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - uses: actions/setup-node@v3
      - run: npm ci
      - run: npm run test  # 单元测试（快）

  feature-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - uses: shivammathur/setup-php@v2
      - run: composer install
      - run: ./vendor/bin/phpunit  # Feature 测试（中速）

  e2e-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - run: npm ci
      - run: npx playwright install --with-deps
      - run: npm run e2e  # E2E 测试（慢，可选）
    # 只在主分支或 PR 合并前运行
    if: github.ref == 'refs/heads/main' || github.event_name == 'pull_request'
```

---

## 常见问题 FAQ

### Q1: 测试数据会污染生产数据库吗？

**A**: 不会！三层测试都隔离数据库：
- 单元测试：完全 Mock，无数据库连接
- Feature 测试：使用 In-Memory SQLite（`:memory:`）
- E2E 测试：连接测试环境（`APP_ENV=testing`）

### Q2: 测试运行很慢怎么办？

**A**: 优先运行单元测试（秒级），只在必要时运行 E2E：
```bash
# 快速反馈（< 10 秒）
npm run test:watch

# 完整测试（1-2 分钟）
npm test && ./vendor/bin/phpunit

# 全量测试（5-10 分钟，CI 或发布前）
npm test && ./vendor/bin/phpunit && npm run e2e
```

### Q3: 如何调试失败的测试？

**单元测试**：
```bash
npm run test:ui  # 打开可视化界面
```

**E2E 测试**：
```bash
npm run e2e:debug  # 逐步执行，查看浏览器
```

**Feature 测试**：
```bash
./vendor/bin/phpunit --filter test_name --testdox
```

### Q4: 需要测试所有浏览器吗？

**A**: 不需要！默认只测试 Chromium：
```javascript
// playwright.config.js
projects: [
    { name: 'chromium' },  // 主要测试
    // { name: 'firefox' },  // 可选
    // { name: 'webkit' },   // 可选
]
```

---

## 时间投入估算

| 任务 | 时间 | 优先级 |
|------|------|--------|
| 配置测试环境 | 1-2 小时 | ⭐⭐⭐⭐⭐ |
| 编写单元测试 | 1-2 天 | ⭐⭐⭐⭐⭐ |
| 编写 Feature 测试 | 0.5-1 天 | ⭐⭐⭐⭐ |
| 编写 E2E 测试 | 2-3 天 | ⭐⭐⭐ |
| 配置 CI/CD | 2-4 小时 | ⭐⭐⭐⭐ |
| **总计** | **4-7 天** | |

---

## 立即开始检查清单

### ✅ 第一步：环境准备（30 分钟）

- [ ] 安装 Vitest：`npm install -D vitest @vue/test-utils jsdom`
- [ ] 安装 Playwright：`npm install -D @playwright/test`
- [ ] 检查 `phpunit.xml` 配置（确保使用 SQLite）

### ✅ 第二步：创建第一个测试（1 小时）

- [ ] 复制 `tests/JavaScript/AsyncSelect.spec.js`
- [ ] 运行：`npm run test`
- [ ] 调整为你的组件

### ✅ 第三步：添加 API 测试（1 小时）

- [ ] 运行 Seeder：`php artisan db:seed --class=TestSelectDataSeeder --env=testing`
- [ ] 运行测试：`./vendor/bin/phpunit --filter SelectApiTest`

### ✅ 第四步：配置 CI（30 分钟）

- [ ] 创建 `.github/workflows/tests.yml`
- [ ] 推送代码，查看 GitHub Actions 运行结果

---

## 下一步行动

1. **今天**：运行 `npm install -D vitest` 并跑第一个单元测试
2. **本周**：完成核心组件的单元测试（AsyncSelect、PersonSelect）
3. **下周**：添加 Feature 测试覆盖所有 API 端点
4. **两周后**：配置 E2E 测试并集成到 CI

---

**相关文档**：
- 详细指南：`TESTING_SELECT_COMPONENTS.md`
- API Mock 数据：`tests/JavaScript/fixtures/apiResponses.js`
- 测试数据 Seeder：`database/seeders/TestSelectDataSeeder.php`

**最后更新**：2025-12-28
