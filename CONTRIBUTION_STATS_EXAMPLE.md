# Git 现存代码贡献统计脚本 - 使用示例

## 快速开始

### 1. 基本用法（统计默认路径）

```bash
python3 git_contribution_stats.py
```

**示例输出：**

```
正在统计 856 个文件...
进度: 100/856
进度: 200/856
进度: 300/856
...

TOTAL_LINES: 106865

Author                                             Lines    Percent
------------------------------------------------------------------------
<frank@example.com>                                48210     45.11%
<dependabot[bot]@users.noreply.github.com>         16234     15.19%
<alice@university.edu>                             12105     11.32%
<bob@company.com>                                   8743      8.18%
<carol@freelance.io>                                6521      6.10%
...

CSV 已保存: contribution_stats.csv

========================================================================
统计元信息
========================================================================
HEAD commit:      1f4d11d30031e735736c287436155ddd778c3c36
--since filter:   None
Paths analyzed:   app, resources, config, routes
Files scanned:    856
Files processed:  842
Total lines:      106865
Unique authors:   23
Bot contributions: 16234 lines (15.19%)
========================================================================

# 正确性说明
# 1. git blame 归因符合"现存代码责任"：每行归属最后修改者
# 2. 格式化、重构会改变贡献比例（这是 Git 语义的预期行为）
# 3. 此统计不包含历史贡献、commit 数、code review 等
```

**生成的 CSV 文件（contribution_stats.csv）：**

```csv
author_mail,lines,percent,is_bot
<frank@example.com>,48210,45.11,false
<dependabot[bot]@users.noreply.github.com>,16234,15.19,true
<alice@university.edu>,12105,11.32,false
<bob@company.com>,8743,8.18,false
<carol@freelance.io>,6521,6.10,false
...
```

---

### 2. 统计特定时间范围（2024 年以来的修改）

```bash
python3 git_contribution_stats.py --since 2024-01-01
```

**说明：** 只统计最后修改时间 >= 2024-01-01 的代码行。

**示例输出：**

```
正在统计 856 个文件...

TOTAL_LINES: 42318

Author                                             Lines    Percent
------------------------------------------------------------------------
<frank@example.com>                                28431     67.18%
<alice@university.edu>                              9214     21.78%
<bob@company.com>                                   4673     11.04%

CSV 已保存: contribution_stats.csv

========================================================================
统计元信息
========================================================================
HEAD commit:      1f4d11d30031e735736c287436155ddd778c3c36
--since filter:   2024-01-01
Paths analyzed:   app, resources, config, routes
Files scanned:    856
Files processed:  623
Total lines:      42318
Unique authors:   3
========================================================================
```

---

### 3. 统计指定目录

```bash
python3 git_contribution_stats.py --paths "app resources"
```

**说明：** 只统计 `app` 和 `resources` 目录。

---

### 4. 自定义输出文件名

```bash
python3 git_contribution_stats.py --output my_stats.csv
```

---

### 5. 组合使用

```bash
python3 git_contribution_stats.py --since 2023-06-01 --paths "app config" --output 2023_h2_stats.csv
```

**说明：** 统计 2023 年下半年 `app` 和 `config` 目录的贡献。

---

## 输出说明

### 终端输出

1. **进度信息**（stderr）：每 100 个文件显示一次
2. **贡献排行榜**（stdout）：按行数降序排列
3. **CSV 文件路径**
4. **统计元信息**：包含 HEAD commit、过滤条件、文件数、总行数等

### CSV 文件字段

| 字段         | 说明                          | 示例                                   |
|--------------|-------------------------------|----------------------------------------|
| author_mail  | 作者邮箱（Git author-mail）   | `<frank@example.com>`                  |
| lines        | 现存代码行数                  | `48210`                                |
| percent      | 占总行数百分比                | `45.11`                                |
| is_bot       | 是否为 bot 账号               | `true` / `false`                       |

---

## 排除规则

脚本默认排除以下目录：

- `node_modules/` - Node.js 依赖
- `vendor/` - PHP/Composer 依赖
- `storage/` - Laravel 存储目录
- `public/build/` - 前端构建产物
- `.git/` - Git 元数据
- `dist/` - 分发构建产物
- `build/` - 构建产物

同时自动排除常见二进制文件（图片、字体、压缩包等）。

---

## 实际应用场景

### 场景 1：项目交接前的贡献统计

```bash
# 统计整个项目的当前贡献
python3 git_contribution_stats.py
```

用于了解当前代码库中各成员的代码占比。

### 场景 2：年度代码审查

```bash
# 统计 2024 年的新增/修改代码
python3 git_contribution_stats.py --since 2024-01-01 --output 2024_review.csv
```

用于年度绩效评估、代码质量回顾。

### 场景 3：特定模块的维护者识别

```bash
# 统计核心业务模块的贡献
python3 git_contribution_stats.py --paths "app/Models app/Services app/Http/Controllers"
```

用于识别特定模块的主要维护者，便于分配任务或代码审查。

### 场景 4：重构影响评估

```bash
# 重构前统计
python3 git_contribution_stats.py --output before_refactor.csv

# ... 执行重构 ...

# 重构后统计
python3 git_contribution_stats.py --output after_refactor.csv
```

对比两次统计结果，了解重构对贡献比例的影响。

---

## 注意事项

### ✅ 该统计能告诉你什么

- 当前 HEAD 中每个作者的现存代码行数
- 各作者对当前代码库的维护责任比例
- 最近一段时间（--since）的代码修改归属

### ❌ 该统计不能告诉你什么

- 历史总贡献（包括已删除的代码）
- Commit 数量或提交频率
- Code review 贡献
- 文档、测试、设计等非代码贡献
- 代码质量或复杂度

### ⚠️ 可能导致统计结果偏差的情况

1. **代码格式化**：运行 Prettier、Black 等格式化工具会将所有被格式化的行归给格式化者
2. **大规模重构**：重命名变量、重构架构会将修改的行归给重构者
3. **文件移动**：虽然 git blame 会尝试追踪，但某些情况下可能归属不准确
4. **批量导入**：从其他项目导入代码会归给导入者

**这些都是 git blame 的预期行为，反映了"现存代码责任"的真实情况。**

---

## 技术实现细节

### 核心原理

1. **使用 `git ls-files` 获取当前 HEAD 的文件列表**
   - 确保只统计 Git 跟踪的文件
   - 排除生成物和依赖目录

2. **使用 `git blame --line-porcelain` 获取每行归因**
   - `--line-porcelain` 提供机器可读的详细输出
   - 包含 author-mail、author-time 等完整信息

3. **以 author-mail 作为唯一标识**
   - 避免同一作者使用不同名字导致的统计分散
   - 符合 Git 的标准做法

### 性能考虑

- 对每个文件运行一次 `git blame`（无法避免，这是 Git 的工作方式）
- 使用 `--line-porcelain` 一次性获取所有信息，避免多次调用
- 排除二进制文件和生成物以减少不必要的处理

### 正确性保证

- 完全依赖 Git 原生命令，不进行任何推断或猜测
- Bot 识别仅基于邮箱中的 `[bot]` 标识
- 日期过滤基于 Git 记录的 author-time（而非 commit-time）

---

## 故障排查

### 问题：提示"当前目录不是 Git 仓库"

**解决：** 确保在 Git 仓库根目录或子目录中运行脚本。

### 问题：统计结果为 0

**可能原因：**
1. 指定的路径不存在或为空
2. 所有文件都被排除规则过滤
3. --since 日期过滤掉了所有代码

**解决：** 检查路径、排除规则和日期参数。

### 问题：统计很慢

**原因：** `git blame` 对大文件和大仓库比较慢，这是正常现象。

**建议：**
- 使用 `--paths` 限制统计范围
- 排除不必要的目录
- 在性能较好的机器上运行

---

## 许可与贡献

本脚本为开源工具，仅依赖 Python 3 标准库和 Git，可自由使用和修改。
