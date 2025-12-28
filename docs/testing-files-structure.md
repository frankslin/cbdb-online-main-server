# 测试文件结构总览

本文档列出所有测试相关文件的位置和用途，方便快速导航。

## 📁 目录结构

```
cbdb-online-main-server/
│
├── tests/                              # Laravel 测试目录
│   ├── Feature/                        # 功能测试
│   │   └── Api/
│   │       └── SelectApiTest.php       # Select API 端点测试
│   │
│   ├── Unit/                           # 单元测试（PHP）
│   │
│   ├── JavaScript/                     # 前端测试（新增）
│   │   ├── setup.js                    # Vitest 环境设置
│   │   ├── AsyncSelect.spec.js         # AsyncSelect 组件测试
│   │   └── fixtures/
│   │       └── apiResponses.js         # Mock API 数据
│   │
│   └── E2E/                            # E2E 测试（新增）
│       └── select-components.spec.js   # Select 组件 E2E 测试
│
├── database/
│   └── seeders/
│       └── TestSelectDataSeeder.php    # 测试数据填充器
│
├── docs/                               # 文档目录
│   ├── testing-strategy-summary.md     # 测试策略总结 ⭐
│   └── testing-files-structure.md      # 本文件
│
├── vitest.config.js                    # Vitest 配置
├── playwright.config.js                # Playwright 配置
├── package.json                        # npm 脚本配置
├── phpunit.xml                         # PHPUnit 配置
└── TESTING_SELECT_COMPONENTS.md        # 完整测试指南 ⭐
```

---

## 📄 文件清单与用途

### 1️⃣ 配置文件

| 文件 | 用途 | 关键配置 |
|------|------|---------|
| `vitest.config.js` | 前端单元测试配置 | 测试环境、覆盖率阈值、路径别名 |
| `playwright.config.js` | E2E 测试配置 | 浏览器、超时、截图、视频 |
| `phpunit.xml` | PHP 测试配置 | SQLite In-Memory、环境变量 |
| `package.json` | npm 脚本定义 | 测试命令快捷方式 |

---

### 2️⃣ 前端测试（Vitest）

#### 测试文件

| 文件 | 内容 | 运行命令 |
|------|------|---------|
| `tests/JavaScript/AsyncSelect.spec.js` | AsyncSelect.vue 组件测试 | `npm run test` |
| `tests/JavaScript/setup.js` | 全局测试环境设置 | 自动加载 |

#### Mock 数据

| 文件 | 内容 |
|------|------|
| `tests/JavaScript/fixtures/apiResponses.js` | API Mock 数据（文献、地址、人物等） |

**核心函数**：
- `createMockApiResponse(model, params)` - 生成 Mock API 响应
- `mockTextData` - 文献数据集
- `mockAddrData` - 地址数据集
- `mockPersonData` - 人物数据集

---

### 3️⃣ 后端测试（PHPUnit）

#### Feature 测试

| 文件 | 内容 | 运行命令 |
|------|------|---------|
| `tests/Feature/Api/SelectApiTest.php` | Select API 端点测试 | `./vendor/bin/phpunit --filter SelectApiTest` |

**测试覆盖**：
- ✅ API 响应结构验证
- ✅ 搜索过滤逻辑
- ✅ 分页功能
- ✅ 按 ID 查询
- ✅ 节流限制
- ✅ 性能测试

#### 测试数据

| 文件 | 内容 | 运行命令 |
|------|------|---------|
| `database/seeders/TestSelectDataSeeder.php` | 测试数据填充器 | `php artisan db:seed --class=TestSelectDataSeeder --env=testing` |

**数据集**：
- 12 条文献记录（TEXT_DATA）
- 8 条地址记录（ADDRESSES）
- 5 条官职记录（OFFICES）
- 3 条人物记录（BIOG_MAIN）
- 若干代码表记录

**关键测试 ID**：
- 文献 123：四庫全書
- 文献 456：資治通鑑
- 人物 1001：蘇軾
- 地址 1：北京

---

### 4️⃣ E2E 测试（Playwright）

| 文件 | 内容 | 运行命令 |
|------|------|---------|
| `tests/E2E/select-components.spec.js` | Select 组件端到端测试 | `npm run e2e` |

**测试场景**：
- ✅ 下拉框渲染
- ✅ 搜索交互
- ✅ 选择项目
- ✅ 多选功能
- ✅ 分页加载
- ✅ 表单提交
- ✅ 键盘导航
- ✅ 错误处理

---

### 5️⃣ 文档

| 文件 | 内容 | 适用人群 |
|------|------|---------|
| `TESTING_SELECT_COMPONENTS.md` | 完整测试指南（详细） | 想深入了解的开发者 |
| `docs/testing-strategy-summary.md` | 测试策略总结（精简） | 快速上手 ⭐ |
| `docs/testing-files-structure.md` | 本文件 | 导航查找 |

---

## 🚀 快速命令参考

### 前端测试

```bash
# 运行所有单元测试
npm run test

# 监听模式（开发时推荐）
npm run test:watch

# 查看覆盖率
npm run test:coverage

# 打开 UI 界面
npm run test:ui
```

### 后端测试

```bash
# 运行所有测试
./vendor/bin/phpunit

# 运行 Select API 测试
./vendor/bin/phpunit --filter SelectApiTest

# 运行单个测试方法
./vendor/bin/phpunit --filter test_text_search_returns_correct_structure

# 查看覆盖率
./vendor/bin/phpunit --coverage-html coverage/
```

### E2E 测试

```bash
# 运行所有 E2E 测试
npm run e2e

# 调试模式（逐步执行）
npm run e2e:debug

# 打开 UI 界面
npm run e2e:ui

# 查看报告
npx playwright show-report
```

### 测试数据

```bash
# 填充测试数据（测试环境）
php artisan db:seed --class=TestSelectDataSeeder --env=testing

# 刷新数据库并重新填充
php artisan migrate:fresh --seed --env=testing
```

---

## 📝 开发工作流示例

### 场景 1：开发新的 Vue 组件

```bash
# 1. 创建组件
resources/js/components/NewSelect.vue

# 2. 创建测试
tests/JavaScript/NewSelect.spec.js

# 3. 监听模式开发
npm run test:watch

# 4. 验证覆盖率
npm run test:coverage
```

### 场景 2：修改 API 端点

```bash
# 1. 修改控制器
app/Http/Controllers/Api/ApiController.php

# 2. 更新或创建测试
tests/Feature/Api/SelectApiTest.php

# 3. 运行测试
./vendor/bin/phpunit --filter SelectApiTest

# 4. 如需新测试数据，更新 Seeder
database/seeders/TestSelectDataSeeder.php
```

### 场景 3：发布前完整测试

```bash
# 1. 运行所有测试
npm run test && ./vendor/bin/phpunit && npm run e2e

# 2. 检查覆盖率
npm run test:coverage
./vendor/bin/phpunit --coverage-html coverage/

# 3. 查看 E2E 报告
npx playwright show-report
```

---

## 🔍 故障排查

### 问题：找不到测试文件

**解决方案**：检查文件路径和名称

```bash
# 前端测试必须匹配模式
tests/JavaScript/**/*.{test,spec}.{js,ts}

# 后端测试必须在
tests/Feature/ 或 tests/Unit/

# E2E 测试必须在
tests/E2E/
```

### 问题：Mock 数据不生效

**解决方案**：检查导入路径

```javascript
// 正确导入
import { createMockApiResponse } from '@/tests/JavaScript/fixtures/apiResponses';

// 错误导入
import { createMockApiResponse } from './fixtures/apiResponses';
```

### 问题：数据库连接错误

**解决方案**：确保 `phpunit.xml` 配置正确

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

---

## 📊 测试覆盖率目标

| 组件 | 单元测试 | Feature 测试 | E2E 测试 | 状态 |
|------|---------|------------|---------|------|
| AsyncSelect.vue | 80%+ | - | ✅ | 🟢 已完成 |
| PersonSelect.vue | 80%+ | - | ✅ | 🟡 待实现 |
| Select API 端点 | - | 100% | - | 🟢 已完成 |
| 表单页面 | - | - | 关键流程 | 🟡 待实现 |

---

## 📚 相关资源

- [Vitest 文档](https://vitest.dev/)
- [Vue Test Utils](https://test-utils.vuejs.org/)
- [Playwright 文档](https://playwright.dev/)
- [PHPUnit 文档](https://phpunit.de/)
- [Laravel Testing](https://laravel.com/docs/10.x/testing)

---

**维护者**：开发团队
**最后更新**：2025-12-28
**版本**：1.0.0
