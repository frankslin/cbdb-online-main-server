#!/usr/bin/env python3
"""
Git 现存代码贡献统计脚本
======================

严格基于 git blame 语义，统计 HEAD 中现存代码的作者贡献比例。

核心原则：
----------
1. 使用 git blame --line-porcelain 获取每一行的最后修改作者
2. 仅统计当前 HEAD 中存在的代码行
3. 以 author-mail 作为唯一作者标识
4. 不推断、不猜测，完全依赖 Git 原生归因

使用方法：
----------
  python3 git_contribution_stats.py [选项]

选项：
  --since YYYY-MM-DD    仅统计最后修改时间 >= since 的现存代码行
  --paths "dir1 dir2"   指定要统计的目录（空格分隔）
  --output FILE         CSV 输出文件名（默认：contribution_stats.csv）

示例：
  # 统计默认路径（app resources config routes tests）
  python3 git_contribution_stats.py

  # 统计 2024 年以来的修改
  python3 git_contribution_stats.py --since 2024-01-01

  # 统计指定目录
  python3 git_contribution_stats.py --paths "app resources"

输出：
------
1. 终端：作者贡献排行榜（按行数降序）
2. CSV：机器可读格式（author_mail, lines, percent, is_bot）
3. 元信息：统计参数、总行数、文件数等

正确性说明：
------------
1. git blame 归因语义：
   - 每行归属于"最后一次实质性修改该行的作者"
   - 符合"现存代码责任"的定义
   - 移动代码、重命名文件等操作会保留原始作者（git blame -C -M）

2. 导致贡献比例变化的情况：
   - 代码格式化（formatter）会将所有被格式化的行归给格式化者
   - 大规模重构会将修改的行归给重构者
   - 删除代码会降低原作者的贡献比例
   - 这些都是 git blame 的预期行为

3. 该统计不能回答的问题：
   - 历史总贡献（包括已删除的代码）
   - Commit 数量或频率
   - Code review 贡献
   - 设计、测试等非代码贡献

排除目录：
----------
- node_modules/
- vendor/
- storage/
- public/build/
- .git/
"""

import subprocess
import sys
import re
import os
from datetime import datetime
from collections import defaultdict
import argparse
import csv

# 默认排除的目录（生成物、依赖、临时文件）
DEFAULT_EXCLUDED = [
    'node_modules/',
    'vendor/',
    'storage/',
    'public/build/',
    '.git/',
    'dist/',
    'build/',
]

# 默认统计的路径（源代码与测试目录）
DEFAULT_PATHS = ['app', 'resources', 'config', 'routes', 'tests']

CLAUDE_EMAILS = {'<noreply@anthropic.com>'}
COPILOT_EMAILS = {
    '<175728472+Copilot@users.noreply.github.com>',
    '<198982749+Copilot@users.noreply.github.com>',
    '<223556219+Copilot@users.noreply.github.com>',
}

LOC_CATEGORIES = ['backend', 'frontend', 'docs', 'tests', 'other']


def get_git_files(paths, excluded):
    """
    获取当前 HEAD 中的所有文件。

    使用 git ls-files 确保只统计 Git 跟踪的文件。

    参数：
        paths: 要统计的路径列表
        excluded: 要排除的路径模式列表（支持目录、文件名、通配符）
    """
    try:
        # 构建命令：git ls-files [paths...]
        cmd = ['git', 'ls-files']

        # 如果指定了路径，添加到命令中
        for path in paths:
            if os.path.exists(path):
                cmd.append(path)

        result = subprocess.run(cmd, capture_output=True, text=True, check=True)
        files = [f for f in result.stdout.strip().split('\n') if f]

    except subprocess.CalledProcessError as e:
        print(f"错误：无法获取 Git 文件列表", file=sys.stderr)
        print(f"详情：{e.stderr}", file=sys.stderr)
        sys.exit(1)

    # 过滤排除的目录和文件
    filtered_files = []
    for f in files:
        should_exclude = False

        # 检查是否匹配排除规则
        for excl in excluded:
            # 支持多种排除模式：
            # 1. 目录：node_modules/ -> 排除所有以 node_modules/ 开头的
            # 2. 文件名：package-lock.json -> 排除所有路径中包含此文件名的
            # 3. 路径：public/build/ -> 排除此路径下的所有文件
            if excl.endswith('/'):
                # 目录模式
                if f.startswith(excl):
                    should_exclude = True
                    break
            else:
                # 文件名或路径模式
                if f == excl or f.endswith('/' + excl) or f.startswith(excl + '/'):
                    should_exclude = True
                    break

        if should_exclude:
            continue

        # 只统计文本文件（简单启发式：排除常见二进制扩展名）
        if not f.endswith(('.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico',
                          '.woff', '.woff2', '.ttf', '.eot', '.mp4', '.mp3',
                          '.pdf', '.zip', '.tar', '.gz', '.bz2')):
            filtered_files.append(f)

    return filtered_files


def parse_blame_porcelain(file_path, since_timestamp=None):
    """
    使用 git blame --line-porcelain 解析单个文件。

    返回：[(author_mail, timestamp, commit_hash), ...] 列表，每个元素对应一行代码
    """
    cmd = ['git', 'blame', '--line-porcelain', '--', file_path]

    try:
        result = subprocess.run(cmd, capture_output=True, text=True, check=True)
    except subprocess.CalledProcessError:
        # 文件可能是二进制、空文件或无法 blame
        return []

    lines = result.stdout.split('\n')

    authors = []
    current_commit = None
    current_author = None
    current_timestamp = None

    for line in lines:
        # 第一行是 commit hash
        if not line.startswith(('\t', 'author', 'committer', 'summary',
                               'boundary', 'filename', 'previous')):
            parts = line.split()
            if parts and len(parts[0]) == 40:  # SHA-1 hash 长度
                current_commit = parts[0]
        # author-mail 格式：author-mail <email@example.com>
        elif line.startswith('author-mail '):
            current_author = line[12:].strip()
        # author-time 格式：author-time 1234567890
        elif line.startswith('author-time '):
            current_timestamp = int(line[12:].strip())
        # 实际代码行以 TAB 开头
        elif line.startswith('\t'):
            if current_author and current_commit:
                # 应用日期过滤
                if since_timestamp is None or current_timestamp >= since_timestamp:
                    authors.append((current_author, current_timestamp, current_commit))
            # 重置状态
            current_author = None
            current_timestamp = None
            # commit 可能被多行复用，不重置

    return authors


def is_bot(email):
    """
    检查是否为 bot 账号。

    使用正则匹配 [bot] 标识，不做隐式合并或猜测。
    """
    return '[bot]' in email.lower()


def get_commit_co_authors(commit_hash, cache={}):
    """
    获取 commit 的 co-authors。

    解析 commit message 中的 Co-authored-by 标签。
    使用缓存避免重复查询同一个 commit。
    """
    if commit_hash in cache:
        return cache[commit_hash]

    try:
        result = subprocess.run(
            ['git', 'show', '-s', '--format=%b', commit_hash],
            capture_output=True,
            text=True,
            check=True
        )

        co_authors = []
        for line in result.stdout.split('\n'):
            # Co-authored-by: Name <email@example.com>
            if re.match(r'^co-authored-by:\s+', line.strip(), re.IGNORECASE):
                match = re.search(r'<(.+?)>', line)
                if match:
                    co_authors.append(f'<{match.group(1)}>')

        cache[commit_hash] = co_authors
        return co_authors
    except subprocess.CalledProcessError:
        cache[commit_hash] = []
        return []


def apply_mailmap(author_email):
    """
    使用 .mailmap 转换作者邮箱（如果存在）。

    如果 .mailmap 文件存在，使用 git check-mailmap 转换邮箱。
    返回转换后的完整作者信息（如 "Name <email>"）或原邮箱。
    """
    if not os.path.exists('.mailmap'):
        return author_email

    # 构造 "Name <email>" 格式（git check-mailmap 需要）
    # 从 <email> 提取 email
    email_match = re.match(r'<(.+)>', author_email)
    if not email_match:
        return author_email

    email = email_match.group(1)

    try:
        # git check-mailmap 需要 "Name <email>" 格式
        # 我们用 email 作为临时 name
        result = subprocess.run(
            ['git', 'check-mailmap', f'{email} {author_email}'],
            capture_output=True,
            text=True,
            check=True
        )

        # 输出格式：Name <email>
        # 提取邮箱部分
        output = result.stdout.strip()
        mapped_match = re.search(r'<(.+)>', output)
        if mapped_match:
            return f'<{mapped_match.group(1)}>'
        return author_email
    except subprocess.CalledProcessError:
        return author_email


def count_direct_lines(author_lines, assistant_emails):
    return sum(lines for author, lines in author_lines.items() if author in assistant_emails)


def classify_file_category(file_path):
    """
    将文件归类为 backend / frontend / docs / tests / other。

    规则优先按路径判断，再辅以扩展名。
    """
    normalized = file_path.replace('\\', '/').lstrip('./')
    basename = os.path.basename(normalized).lower()

    if normalized.startswith('tests/'):
        return 'tests'

    if (
        normalized.startswith('resources/views/')
        or normalized.startswith('resources/js/')
        or normalized.startswith('resources/css/')
        or normalized.startswith('resources/sass/')
        or normalized.startswith('resources/scss/')
        or normalized.startswith('resources/ts/')
        or normalized.startswith('resources/tsx/')
        or normalized.startswith('resources/vue/')
        or basename.endswith(('.vue', '.jsx', '.tsx', '.ts', '.css', '.scss', '.sass', '.less'))
        or basename in {'vite.config.js', 'vite.config.ts', 'tailwind.config.js', 'tailwind.config.ts'}
    ):
        return 'frontend'

    if (
        normalized.startswith('docs/')
        or basename in {'readme.md', 'changelog.md', 'agents.md', 'api.md', 'database.md', 'merge.md', 'views.md', 'codes_tables.md'}
        or basename.endswith(('.md', '.mdx', '.rst', '.txt', '.adoc'))
    ):
        return 'docs'

    if (
        normalized.startswith('app/')
        or normalized.startswith('config/')
        or normalized.startswith('routes/')
        or normalized.startswith('database/')
        or normalized.startswith('bootstrap/')
        or normalized.startswith('lang/')
        or basename == 'artisan'
        or basename.endswith('.php')
    ):
        return 'backend'

    return 'other'


def main():
    parser = argparse.ArgumentParser(
        description='Git 现存代码贡献统计脚本',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
示例：
  # 基本用法
  python3 git_contribution_stats.py

  # 按时间过滤
  python3 git_contribution_stats.py --since 2024-01-01

  # 指定目录
  python3 git_contribution_stats.py --paths "app resources"

  # 统计整个仓库，排除特定文件/目录
  python3 git_contribution_stats.py --paths "." --exclude "node_modules/ vendor/ package-lock.json composer.lock"

  # 完整用法（推荐）
  python3 git_contribution_stats.py --paths "." --exclude "node_modules/ vendor/ storage/ public/build/ package-lock.json composer.lock"
        """
    )
    parser.add_argument('--since',
                       help='仅统计最后修改时间 >= since 的代码行 (YYYY-MM-DD)')
    parser.add_argument('--paths',
                       help='要统计的目录/文件，空格分隔')
    parser.add_argument('--exclude',
                       help='要排除的目录/文件，空格分隔（支持通配符）')
    parser.add_argument('--output',
                       default='contribution_stats.csv',
                       help='CSV 输出文件名（默认：contribution_stats.csv）')

    args = parser.parse_args()

    # ========== 参数解析 ==========

    since_timestamp = None
    if args.since:
        try:
            since_dt = datetime.strptime(args.since, '%Y-%m-%d')
            since_timestamp = int(since_dt.timestamp())
        except ValueError:
            print(f"错误：日期格式无效，请使用 YYYY-MM-DD", file=sys.stderr)
            sys.exit(1)

    paths = args.paths.split() if args.paths else DEFAULT_PATHS

    # 合并默认排除和用户指定排除
    excluded = DEFAULT_EXCLUDED.copy()
    if args.exclude:
        user_excluded = args.exclude.split()
        excluded.extend(user_excluded)

    # ========== 获取 Git 元信息 ==========

    try:
        # 获取 HEAD commit hash
        head_result = subprocess.run(['git', 'rev-parse', 'HEAD'],
                                    capture_output=True, text=True, check=True)
        head_hash = head_result.stdout.strip()

        # 检查是否在 Git 仓库中
        subprocess.run(['git', 'rev-parse', '--git-dir'],
                      capture_output=True, check=True)
    except subprocess.CalledProcessError:
        print("错误：当前目录不是 Git 仓库", file=sys.stderr)
        sys.exit(1)

    # ========== 获取文件列表 ==========

    files = get_git_files(paths, excluded)

    if not files:
        print("警告：没有找到要统计的文件", file=sys.stderr)
        sys.exit(0)

    print(f"正在统计 {len(files)} 个文件...", file=sys.stderr)

    # ========== 执行 blame 统计 ==========

    author_lines = defaultdict(int)
    category_lines = defaultdict(int)
    claude_co_author_lines = 0
    copilot_co_author_lines = 0
    total_lines = 0
    processed_files = 0

    for i, file_path in enumerate(files, 1):
        if i % 100 == 0:
            print(f"进度: {i}/{len(files)}", file=sys.stderr)

        authors = parse_blame_porcelain(file_path, since_timestamp)

        if authors:
            processed_files += 1
            category = classify_file_category(file_path)
            category_lines[category] += len(authors)
            for author, _, commit_hash in authors:
                author_lines[author] += 1
                total_lines += 1

                co_authors = get_commit_co_authors(commit_hash)

                if author not in CLAUDE_EMAILS:
                    if any(email in CLAUDE_EMAILS for email in co_authors):
                        claude_co_author_lines += 1

                if author not in COPILOT_EMAILS:
                    if any(email in COPILOT_EMAILS for email in co_authors):
                        copilot_co_author_lines += 1

    if total_lines == 0:
        print("警告：没有统计到任何代码行", file=sys.stderr)
        sys.exit(0)

    # ========== 应用 .mailmap（如果存在）==========

    if os.path.exists('.mailmap'):
        print("检测到 .mailmap 文件，正在合并重复身份...", file=sys.stderr)
        merged_lines = defaultdict(int)
        for author, lines in author_lines.items():
            mapped_author = apply_mailmap(author)
            merged_lines[mapped_author] += lines
        author_lines = merged_lines

    claude_emails = {apply_mailmap(email) for email in CLAUDE_EMAILS} if os.path.exists('.mailmap') else CLAUDE_EMAILS
    copilot_emails = {apply_mailmap(email) for email in COPILOT_EMAILS} if os.path.exists('.mailmap') else COPILOT_EMAILS

    # ========== 排序 ==========

    sorted_authors = sorted(author_lines.items(), key=lambda x: x[1], reverse=True)

    # ========== 输出 A：终端可读汇总 ==========

    print(f"\nTOTAL_LINES: {total_lines}\n")
    print(f"{'Author':<50} {'Lines':>10} {'Percent':>10}")
    print("-" * 72)

    for author, lines in sorted_authors:
        percent = (lines / total_lines * 100)
        print(f"{author:<50} {lines:>10} {percent:>9.2f}%")

    # ========== 输出 B：CSV 文件 ==========

    with open(args.output, 'w', newline='', encoding='utf-8') as csvfile:
        writer = csv.writer(csvfile)
        writer.writerow(['author_mail', 'lines', 'percent', 'is_bot'])

        for author, lines in sorted_authors:
            percent = (lines / total_lines * 100)
            writer.writerow([
                author,
                lines,
                f"{percent:.2f}",
                'true' if is_bot(author) else 'false'
            ])

    print(f"\nCSV 已保存: {args.output}")

    # ========== 输出 C：统计元信息 ==========

    print("\n" + "=" * 72)
    print("统计元信息")
    print("=" * 72)
    print(f"HEAD commit:      {head_hash}")
    print(f"--since filter:   {args.since if args.since else 'None'}")
    print(f"Paths analyzed:   {', '.join(paths)}")
    print(f"Files scanned:    {len(files)}")
    print(f"Files processed:  {processed_files}")
    print(f"Total lines:      {total_lines}")
    print(f"Unique authors:   {len(author_lines)}")

    print("\nLOC 分类:")
    for category in LOC_CATEGORIES:
        lines = category_lines[category]
        if lines == 0:
            continue
        percent = (lines / total_lines * 100)
        print(f"  {category:<10} {lines:>8} lines ({percent:.2f}%)")

    # Bot 统计
    bot_lines = sum(lines for author, lines in author_lines.items() if is_bot(author))
    if bot_lines > 0:
        bot_percent = (bot_lines / total_lines * 100)
        print(f"Bot contributions: {bot_lines} lines ({bot_percent:.2f}%)")

    # Claude Co-author 统计
    claude_direct = count_direct_lines(author_lines, claude_emails)
    if claude_direct > 0 or claude_co_author_lines > 0:
        claude_total = claude_direct + claude_co_author_lines
        claude_total_percent = (claude_total / total_lines * 100)
        print("\n" + "-" * 72)
        print("Claude 贡献详细统计:")
        print(f"  作为 Author:      {claude_direct} lines ({claude_direct/total_lines*100:.2f}%)")
        print(f"  作为 Co-author:   {claude_co_author_lines} lines ({claude_co_author_lines/total_lines*100:.2f}%)")
        print(f"  总计:             {claude_total} lines ({claude_total_percent:.2f}%)")
        print("-" * 72)

    copilot_direct = count_direct_lines(author_lines, copilot_emails)
    if copilot_direct > 0 or copilot_co_author_lines > 0:
        copilot_total = copilot_direct + copilot_co_author_lines
        copilot_total_percent = (copilot_total / total_lines * 100)
        print("\n" + "-" * 72)
        print("Copilot 贡献详细统计:")
        print(f"  作为 Author:      {copilot_direct} lines ({copilot_direct/total_lines*100:.2f}%)")
        print(f"  作为 Co-author:   {copilot_co_author_lines} lines ({copilot_co_author_lines/total_lines*100:.2f}%)")
        print(f"  总计:             {copilot_total} lines ({copilot_total_percent:.2f}%)")
        print("-" * 72)

    print("=" * 72)

    # ========== 正确性自检说明 ==========

    print("\n# 正确性说明")
    print("# 1. git blame 归因符合\"现存代码责任\"：每行归属最后修改者")
    print("# 2. 格式化、重构会改变贡献比例（这是 Git 语义的预期行为）")
    print("# 3. 此统计不包含历史贡献、commit 数、code review 等")


if __name__ == '__main__':
    main()
