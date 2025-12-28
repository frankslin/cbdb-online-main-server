# 快速开始指南

## 5 分钟启动 CBDB Online Desktop

### 前提条件

1. **macOS 系统**（11.0+）
2. **已安装 Homebrew**

### 一键安装并启动

打开终端，执行以下命令：

```bash
# 1. 安装 PHP 和 Node.js（如果还没有）
brew install php@8.4 node

# 2. 进入项目目录
cd /path/to/cbdb-online-main-server/electron-prototype

# 3. 运行启动脚本
./start.sh
```

### 脚本会自动完成

✅ 检查 PHP 和 Node.js 版本
✅ 安装 npm 依赖
✅ 安装 Composer 依赖
✅ 配置 Laravel 环境
✅ 清除缓存
✅ 启动 Electron 应用

### 首次启动

1. 应用启动后会弹出对话框
2. 点击 **"选择数据库文件"**
3. 浏览到项目的 `database/database.sqlite3`
4. 点击 **"打开"**
5. 应用自动加载 CBDB Online 界面

### 准备数据库

如果还没有数据库文件：

```bash
# 返回项目根目录
cd /path/to/cbdb-online-main-server

# 导出数据库（示例数据，5000 条记录）
php artisan db:export-to-sqlite \
  --output=database/database.sqlite3 \
  --limit-records=5000

# 优化数据库（可选）
sqlite3 database/database.sqlite3 "VACUUM;"
```

### 故障排查

**问题：找不到 PHP**
```bash
# 检查 PHP 是否安装
which php

# 安装 PHP
brew install php@8.4
```

**问题：PHP 版本过低**
```bash
# 升级 PHP
brew upgrade php

# 切换到 PHP 8.4
brew link php@8.4 --force --overwrite
```

**问题：端口被占用**
```bash
# 查找占用 8000 端口的进程
lsof -i :8000

# 结束进程
kill -9 <PID>
```

### 后续启动

```bash
cd /path/to/cbdb-online-main-server/electron-prototype
npm start
```

应用会记住您选择的数据库路径，直接启动。

### 调试模式

```bash
npm run dev
```

会自动打开开发者工具，方便调试。

### 更换数据库

菜单栏：**CBDB Online > 更换数据库...**

---

**需要帮助？** 查看 [README.md](README.md) 获取详细说明。
