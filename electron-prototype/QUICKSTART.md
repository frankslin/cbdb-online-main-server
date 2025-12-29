# 快速开始指南

## 5 分钟启动 CBDB Online Desktop

### 前提条件

1. **macOS 系统**（11.0+）
2. **已安装 Node.js**（18.x+）

> ✨ **无需安装 PHP**！应用使用 FrankenPHP（自动下载）

### 一键安装并启动

打开终端，执行以下命令：

```bash
# 1. 安装 Node.js（如果还没有）
brew install node

# 2. 进入项目目录
cd /path/to/cbdb-online-main-server/electron-prototype

# 3. 运行启动脚本
./start.sh
```

### 脚本会自动完成

✅ 检查 Node.js 版本
✅ 下载 FrankenPHP（首次运行，约 80MB）
✅ 安装 npm 依赖
✅ 安装 Composer 依赖（使用 FrankenPHP 内置的 composer）
✅ 配置 Laravel 环境
✅ 清除缓存
✅ 启动 Electron 应用

### 首次启动流程

```
$ ./start.sh

🚀 CBDB Online Desktop Prototype
==================================

✅ Node.js v20.10.0

📦 FrankenPHP 未找到

FrankenPHP 是一个包含 PHP 的独立二进制文件，
无需单独安装 PHP 即可运行应用。

是否现在下载 FrankenPHP？(Y/n) Y  ← 按 Y

📦 FrankenPHP 下载脚本
======================

✓ 检测到 CPU 架构：Apple Silicon (M1/M2/M3)

📥 下载 FrankenPHP 1.11.1 (Apple Silicon)...
███████████████████████████ 100%

✅ 下载成功！

FrankenPHP v1.11.1
...

📦 安装 Node.js 依赖...
📦 安装 Composer 依赖...
🧹 清除缓存...

✨ 启动应用...
```

### 应用启动后

1. 弹出对话框："欢迎使用 CBDB Online 桌面版！"
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
# 使用 FrankenPHP（无需系统 PHP）
electron-prototype/resources/php/frankenphp artisan db:export-to-sqlite \
  --output=database/database.sqlite3 \
  --limit-records=5000

# 或者如果已安装系统 PHP
php artisan db:export-to-sqlite \
  --output=database/database.sqlite3 \
  --limit-records=5000

# 优化数据库（可选）
sqlite3 database/database.sqlite3 "VACUUM;"
```

### 后续启动

```bash
cd /path/to/cbdb-online-main-server/electron-prototype
./start.sh
```

应用会记住您选择的数据库路径，直接启动。

### 调试模式

```bash
npm run dev
```

会自动打开开发者工具，方便调试。

### 更换数据库

菜单栏：**CBDB Online > 更换数据库...**

## 🔧 手动下载 FrankenPHP（可选）

如果您想提前下载 FrankenPHP：

```bash
cd electron-prototype
./download-frankenphp.sh
```

## ⚙️ FrankenPHP 说明

### 什么是 FrankenPHP？

- **单一二进制**：包含 PHP 8.4 + Caddy Web 服务器
- **无需安装**：直接运行，无依赖
- **高性能**：比传统 PHP-FPM 更快
- **体积小**：约 80MB（Apple Silicon）或 90MB（Intel）

### 下载详情

- **Apple Silicon (M1/M2/M3)**：约 80MB
- **Intel**：约 90MB
- **来源**：GitHub官方发布（php/frankenphp）
- **版本**：1.11.1

### 文件位置

```
electron-prototype/
└── resources/
    └── php/
        └── frankenphp  ← 下载到这里
```

## 🐛 故障排查

### 问题 1：下载 FrankenPHP 失败

**错误信息**：
```
curl: Failed to connect to github.com
```

**解决方案**：

1. 检查网络连接
2. 使用代理或 VPN
3. 手动下载：
   ```bash
   # 访问下载页面
   open https://github.com/php/frankenphp/releases/tag/v1.11.1

   # 下载对应架构的文件：
   # - Apple Silicon: frankenphp-mac-arm64
   # - Intel: frankenphp-mac-x86_64

   # 重命名并移动到正确位置
   mv ~/Downloads/frankenphp-mac-arm64 electron-prototype/resources/php/frankenphp
   chmod +x electron-prototype/resources/php/frankenphp
   ```

### 问题 2：FrankenPHP 权限被拒绝

**错误信息**：
```
operation not permitted
```

**解决方案**：

macOS Gatekeeper 阻止了未签名的应用。

```bash
# 方法 1：允许运行（推荐）
xattr -d com.apple.quarantine resources/php/frankenphp

# 方法 2：系统设置
# 1. 打开"系统偏好设置" > "安全性与隐私"
# 2. 点击"通用"标签
# 3. 点击"仍要打开"
```

### 问题 3：端口被占用

应用会自动查找可用端口（8000-8100）。

如果仍然出现问题：

```bash
# 查找占用端口的进程
lsof -i :8000

# 结束进程
kill -9 <PID>
```

### 问题 4：数据库文件无效

确认文件是有效的 SQLite 数据库：

```bash
sqlite3 database/database.sqlite3 "PRAGMA integrity_check;"
```

## 💡 高级用法

### 使用 FrankenPHP 命令行

```bash
# 进入 FrankenPHP 目录
cd electron-prototype/resources/php

# 运行 PHP 命令
./frankenphp php -v

# 运行 Artisan 命令
./frankenphp artisan list

# 运行 Composer
./frankenphp composer --version
```

### 清除所有缓存

```bash
cd /path/to/cbdb-online-main-server
electron-prototype/resources/php/frankenphp artisan config:clear
electron-prototype/resources/php/frankenphp artisan cache:clear
electron-prototype/resources/php/frankenphp artisan view:clear
electron-prototype/resources/php/frankenphp artisan route:clear
```

### 重置应用

```bash
# 1. 删除 FrankenPHP
rm electron-prototype/resources/php/frankenphp

# 2. 删除依赖
rm -rf electron-prototype/node_modules
rm -rf vendor

# 3. 删除配置
rm ~/Library/Application\ Support/cbdb-desktop-prototype/config.json

# 4. 重新开始
cd electron-prototype
./start.sh
```

## 📚 相关资源

- [README.md](README.md) - 完整文档
- [DEMO.md](DEMO.md) - 演示说明
- [FrankenPHP 官网](https://frankenphp.dev/)

---

**需要帮助？** 查看 [README.md](README.md) 获取详细说明。

**快速测试**：
```bash
cd electron-prototype && ./start.sh
```
