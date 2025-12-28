# 测试文档总览

本目录包含 Select 组件（Vue + Select2）的完整测试策略和指南。

## 📚 文档导航

### 🚀 快速开始（推荐先看这个）

**[测试策略总结](testing-strategy-summary.md)** ⭐⭐⭐⭐⭐
- 3 分钟了解核心测试策略
- 快速决策树：应该用哪种测试？
- 立即开始检查清单
- **适合**：想快速上手的开发者

### 📖 完整指南

**[TESTING_SELECT_COMPONENTS.md](../TESTING_SELECT_COMPONENTS.md)** ⭐⭐⭐⭐
- 详细的测试策略说明
- 每种测试方法的优缺点分析
- 完整的代码示例
- 故障排查指南
- **适合**：需要深入了解的开发者

### 📁 文件导航

**[测试文件结构](testing-files-structure.md)** ⭐⭐⭐
- 所有测试文件的位置清单
- 快速命令参考
- 工作流示例
- **适合**：查找特定文件

---

## ⚡ 超快速开始（1 分钟）

### 步骤 1：安装依赖

```bash
# 运行自动安装脚本
./scripts/setup-testing.sh
```

### 步骤 2：运行测试

```bash
# 前端测试（最快）
npm run test

# 后端测试
./vendor/bin/phpunit --filter SelectApiTest

# E2E 测试（最慢）
npm run e2e
```

### 步骤 3：查看结果

- 前端测试：终端输出
- 后端测试：终端输出
- E2E 测试：`npx playwright show-report`

---

## 🎯 测试策略一览

| 测试类型 | 速度 | 数据依赖 | 何时使用 | 文档链接 |
|---------|------|---------|---------|---------|
| **组件单元测试** | ⚡⚡⚡ | ❌ 无 | 验证组件逻辑 | [详细指南](../TESTING_SELECT_COMPONENTS.md#策略-1前端组件单元测试-推荐起点) |
| **Feature 测试** | ⚡⚡ | 测试 DB | 验证 API 端点 | [详细指南](../TESTING_SELECT_COMPONENTS.md#策略-2laravel-feature-测试) |
| **E2E 测试** | ⚡ | 可选 | 验证完整流程 | [详细指南](../TESTING_SELECT_COMPONENTS.md#策略-3e2e-端到端测试) |

---

## 📂 关键文件快速链接

### 测试文件

```
tests/
├── JavaScript/
│   ├── AsyncSelect.spec.js          # 前端组件测试
│   ├── setup.js                      # 测试环境设置
│   └── fixtures/apiResponses.js      # Mock 数据
│
├── Feature/Api/
│   └── SelectApiTest.php             # API 端点测试
│
└── E2E/
    └── select-components.spec.js     # E2E 测试
```

### 配置文件

```
根目录/
├── vitest.config.js                  # 前端测试配置
├── playwright.config.js              # E2E 测试配置
└── phpunit.xml                       # PHP 测试配置
```

### 测试数据

```
database/seeders/
└── TestSelectDataSeeder.php          # 测试数据填充器
```

---

## 🔧 常用命令速查

### 前端测试

```bash
npm run test              # 运行所有测试
npm run test:watch        # 监听模式（开发推荐）
npm run test:ui           # 打开可视化界面
npm run test:coverage     # 生成覆盖率报告
```

### 后端测试

```bash
./vendor/bin/phpunit                          # 运行所有测试
./vendor/bin/phpunit --filter SelectApiTest   # 运行特定测试
./vendor/bin/phpunit --coverage-html coverage # 生成覆盖率
```

### E2E 测试

```bash
npm run e2e               # 运行所有 E2E 测试
npm run e2e:debug         # 调试模式
npm run e2e:ui            # 打开可视化界面
npx playwright show-report # 查看报告
```

---

## 💡 推荐学习路径

### 第 1 天：前端单元测试

1. 阅读：[测试策略总结 - 策略 1](testing-strategy-summary.md#阶段-1从单元测试开始1-2-天)
2. 安装：`./scripts/setup-testing.sh`
3. 查看示例：`tests/JavaScript/AsyncSelect.spec.js`
4. 运行测试：`npm run test:watch`
5. 编写第一个测试

### 第 2 天：后端 Feature 测试

1. 阅读：[测试策略总结 - 策略 2](testing-strategy-summary.md#阶段-2添加-feature-测试1-天)
2. 查看示例：`tests/Feature/Api/SelectApiTest.php`
3. 填充数据：`php artisan db:seed --class=TestSelectDataSeeder --env=testing`
4. 运行测试：`./vendor/bin/phpunit --filter SelectApiTest`

### 第 3-4 天：E2E 测试

1. 阅读：[测试策略总结 - 策略 3](testing-strategy-summary.md#阶段-3关键流程-e2e-测试2-3-天)
2. 查看示例：`tests/E2E/select-components.spec.js`
3. 运行测试：`npm run e2e:debug`

---

## ❓ 常见问题

### Q: 我应该从哪个测试开始？

**A**: 从**前端单元测试**开始（Vitest），因为：
- ✅ 最快（秒级反馈）
- ✅ 无需配置数据库
- ✅ 最容易上手

### Q: 测试会污染生产数据吗？

**A**: 不会！所有测试都隔离：
- 单元测试：完全 Mock
- Feature 测试：In-Memory SQLite
- E2E 测试：测试环境

### Q: 测试运行很慢怎么办？

**A**: 分层运行：
```bash
# 快速反馈（< 10 秒）
npm run test:watch

# 中等速度（1-2 分钟）
npm test && ./vendor/bin/phpunit

# 完整测试（5-10 分钟，发布前）
npm test && ./vendor/bin/phpunit && npm run e2e
```

### Q: 如何调试失败的测试？

**A**:
- 前端：`npm run test:ui`
- E2E：`npm run e2e:debug`
- 后端：`./vendor/bin/phpunit --filter test_name --testdox`

---

## 📊 测试覆盖率目标

| 组件 | 单元测试 | Feature 测试 | E2E 测试 |
|------|---------|------------|---------|
| AsyncSelect.vue | **80%+** | - | ✅ |
| PersonSelect.vue | **80%+** | - | ✅ |
| Select API | - | **100%** | - |
| 表单页面 | - | - | 关键流程 |

---

## 🛠️ 技术栈

- **Vitest** - 快速的 Vite 原生测试框架
- **Vue Test Utils** - Vue 官方测试工具
- **Playwright** - 现代 E2E 测试框架
- **PHPUnit 10.1** - Laravel 内置测试框架

---

## 📞 获取帮助

- 查看详细指南：[TESTING_SELECT_COMPONENTS.md](../TESTING_SELECT_COMPONENTS.md)
- 查看测试文件结构：[testing-files-structure.md](testing-files-structure.md)
- 查看快速总结：[testing-strategy-summary.md](testing-strategy-summary.md)

---

## 🔗 外部资源

- [Vitest 文档](https://vitest.dev/)
- [Vue Test Utils](https://test-utils.vuejs.org/)
- [Playwright 文档](https://playwright.dev/)
- [PHPUnit 文档](https://phpunit.de/)
- [Laravel Testing](https://laravel.com/docs/10.x/testing)

---

**最后更新**：2025-12-28
**版本**：1.0.0
**维护者**：开发团队
