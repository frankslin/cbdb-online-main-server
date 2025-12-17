# Git Blame 统计的局限性分析

## 问题概述

用户提出了两个关键问题，揭示了基于 `git blame` 的代码贡献统计的根本性局限：

### 问题 1：历史作者被覆盖

**现象：** 能看到 7-8 年前某个作者写的代码，但在统计中没有体现。

**原因：** `git blame` 只记录**最后修改者**，不记录**原始作者**。

**示例场景：**
```
2018 年：Alice 写了 1000 行核心代码
2024 年：Bob 运行了代码格式化工具（Prettier/PHP-CS-Fixer）
结果：git blame 将这 1000 行全部归属给 Bob
```

**验证案例：**
```bash
# BiogMain.php 的修改历史只有两条：
# 2025-12-09: Frank Lin - 运行 php-cs-fixer（格式化）
# 2025-11-21: frankslin - 最早的记录

# 如果这个文件在 2018 年由其他人创建，那些作者的贡献已被格式化操作覆盖
```

---

### 问题 2：Squash Merge 改变作者归属

**现象：** Claude 写代码 → 真人提 PR → 用 squash merge → commit author 变成 PR 提交者

**实际案例：**

```bash
Commit: ffbada5d371088501f923d18cfd4bd696a939b33
Author: Hongsu Wang <sudospace@gmail.com>
Committer: GitHub <noreply@github.com>
Date: 2025-12-16

标题: style: 降低 content 和 footer 區域藍色連結飽和度 (#605)

Co-authored-by: Claude Sonnet 4.5 <noreply@anthropic.com>
              ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
              真正的代码作者！
```

**问题分析：**

1. **实际情况：**
   - Claude 写了样式调整代码
   - Hongsu Wang 提交 PR
   - 使用 GitHub "Squash and merge"

2. **Git 记录：**
   - `Author`: Hongsu Wang（PR 提交者）
   - `Co-authored-by`: Claude（在 commit message 中，但不是正式的 Git 字段）
   - `Committer`: GitHub

3. **git blame 的行为：**
   - 只看 `Author` 字段
   - 忽略 `Co-authored-by` 标签
   - **结果：这 11 行代码被归属给 Hongsu Wang，而不是 Claude**

**Rebase Merge vs Squash Merge 的差异：**

| 合并方式 | Author 保留情况 | 统计结果 |
|----------|----------------|----------|
| **Rebase Merge** | ✅ 保留原始 commits 的 author | ✅ 正确归属给 Claude |
| **Squash Merge** | ❌ Author 变成 PR 提交者 | ❌ 错误归属给 PR 提交者 |

---

## 统计偏差的具体影响

### 案例 1：格式化导致的贡献转移

```bash
2025-12-09: Frank Lin 运行 `vendor/bin/php-cs-fixer fix`
```

**后果：** 所有被格式化的文件，所有被修改的行，全部归属给 Frank Lin。

**估算影响：**
- 如果格式化修改了 10,000 行代码
- 原本分散在 10 个作者手中
- 现在全部算作 Frank Lin 的贡献

**这可能解释为什么 Frank Lin 占比高达 94.62%！**

---

### 案例 2：Co-authored-by 被忽略

从 commit message 中统计 `Co-authored-by` 标签：

```bash
git log --all --pretty=format:"%b" | grep "Co-authored-by" | sort | uniq -c
```

**预期发现：** Claude 的实际贡献可能比统计数字高得多。

---

## 为什么 git blame 有这些局限性？

### 设计哲学

`git blame` 的目的是回答：
> **"谁应该对当前这行代码负责？"**

而不是：
> "谁最初写了这行代码？"

### 合理性

这个设计在很多场景下是合理的：
- 查找 bug 时，你想找最后修改者（他最了解当前状态）
- 代码 review 时，你想找维护者（不是历史作者）

### 局限性

但对于**贡献统计**来说，这个设计有严重缺陷：
- ❌ 格式化工具"窃取"了历史贡献
- ❌ Squash merge 隐藏了实际作者
- ❌ 大规模重构掩盖了原始贡献
- ❌ Co-authored-by 标签被完全忽略

---

## 解决方案探讨

### 方案 1：解析 Co-authored-by（部分解决问题 2）

**原理：** 从 commit message 中提取 `Co-authored-by` 标签，按比例分配贡献。

**实现：**
```python
def parse_co_authors(commit_hash):
    result = subprocess.run(
        ['git', 'show', '-s', '--format=%b', commit_hash],
        capture_output=True, text=True
    )
    co_authors = []
    for line in result.stdout.split('\n'):
        if line.startswith('Co-authored-by:'):
            # 提取 "Name <email>"
            match = re.search(r'Co-authored-by: (.+) <(.+)>', line)
            if match:
                co_authors.append(f'<{match.group(2)}>')
    return co_authors
```

**局限性：**
- 只能解决有 `Co-authored-by` 标签的 commits
- 无法解决格式化覆盖的问题
- 如何分配比例？（主 author 50%，co-author 50%？）

---

### 方案 2：基于 commit 历史的统计（替代 git blame）

**原理：** 统计每个作者的 commits 和修改行数（insertions），而不是 blame。

**命令：**
```bash
git log --all --numstat --pretty=format:"%H|%an|%ae" |
awk '...' # 统计每个作者的 insertions
```

**优点：**
- ✅ 反映历史总贡献
- ✅ 不受格式化影响
- ✅ 保留 rebase merge 的正确归属

**缺点：**
- ❌ 统计的是"历史贡献"，不是"现存代码责任"
- ❌ 已删除的代码也会计入（可能不公平）
- ❌ 仍然不能解决 squash merge 的问题

---

### 方案 3：混合统计（推荐）

**提供两种视角：**

1. **现存代码责任**（基于 git blame）
   - 回答："谁维护当前代码？"
   - 用于：分配维护任务、代码 review

2. **历史总贡献**（基于 git log）
   - 回答："谁写了最多代码？"
   - 用于：绩效评估、贡献认可

**实现：**
```bash
# 现存代码责任
python3 git_contribution_stats.py --mode blame

# 历史总贡献
python3 git_contribution_stats.py --mode log
```

---

### 方案 4：手动 .mailmap 扩展（解决 Co-authored-by）

**创建增强版 .mailmap：**
```
# 标准用法：合并重复身份
Frank Lin <github@linshuang.info> frankslin <frankslin@users.noreply.github.com>

# 扩展用法：将 co-author 的 commit 也归属给他们
# （需要手动维护 squash merge 的映射关系）
Claude <noreply@anthropic.com> Hongsu Wang <sudospace@gmail.com>  # 仅对特定 commit
```

**局限性：**
- ❌ .mailmap 无法区分"这个 commit 是 co-authored"
- ❌ 会错误地将 Hongsu 的所有 commits 归给 Claude
- ❌ 不可行

---

## 实际建议

### 对于你的项目：

1. **承认局限性**
   - `git blame` 统计有系统性偏差
   - Frank Lin 94.62% 可能包含了：
     - 格式化贡献（技术性修改）
     - 其他人通过 squash merge 的贡献

2. **补充其他数据**
   ```bash
   # 查看 commit 数量分布（更公平）
   git shortlog -sn --all

   # 查看 Co-authored-by 贡献
   git log --all --pretty=format:"%b" | grep "Co-authored-by" |
     sed 's/Co-authored-by: //' | sort | uniq -c | sort -rn
   ```

3. **改进工作流**
   - 建议团队使用 **Rebase Merge** 而不是 Squash Merge
   - 或者确保 Squash Merge 时手动调整 Author 字段
   - 使用 GitHub CLI：
     ```bash
     gh pr merge --rebase  # 保留原始 author
     ```

4. **创建多维度统计**
   - 我可以更新脚本，提供：
     - `--mode blame`：现存代码责任（当前实现）
     - `--mode log`：历史总贡献
     - `--mode co-authors`：解析 Co-authored-by

---

## 你想要什么？

请告诉我你的需求：

1. **修正当前统计**
   - 手动调整已知的 co-author commits？
   - 排除格式化 commits？

2. **增加历史统计模式**
   - 基于 git log 的总贡献统计？
   - 包括已删除的代码？

3. **详细审计**
   - 列出所有 Co-authored-by commits？
   - 找出格式化操作覆盖的代码？

4. **改进工作流**
   - 建议团队改用 rebase merge？
   - 创建 pre-commit hook 检查 author？
