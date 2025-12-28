# CBDB Online Desktop Prototype (macOS)

CBDB Online 桌面版原型 - 专为 macOS 设计的独立桌面应用。

## 📋 功能特点

- ✅ 完全离线运行
- ✅ 用户选择数据库路径
- ✅ 只读模式（无需登录）
- ✅ 原生 macOS 菜单
- ✅ 自动端口检测

## 🔧 系统要求

### 必需

- **macOS**: 11.0 (Big Sur) 或更高版本
- **PHP**: 8.1 或更高版本（推荐 8.4）
- **Node.js**: 18.x 或更高版本
- **SQLite 数据库**: CBDB 导出的 database.sqlite3 文件

### 安装 PHP（如果未安装）

推荐使用 Homebrew 安装：

```bash
# 安装 Homebrew（如果未安装）
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# 安装 PHP 8.4
brew install php@8.4

# 验证安装
php --version
# 应该显示：PHP 8.4.x
```

## 🚀 快速开始

### 步骤 1：准备数据库

如果还没有 SQLite 数据库，请先导出：

```bash
# 在项目根目录运行
cd ..
php artisan db:export-to-sqlite --output=database/database.sqlite3 --limit-records=5000

# 优化数据库（可选，减小体积）
sqlite3 database/database.sqlite3 "VACUUM;"
```

### 步骤 2：安装依赖

```bash
# 进入 electron-prototype 目录
cd electron-prototype

# 安装 Node.js 依赖
npm install
```

### 步骤 3：准备 Laravel 环境

```bash
# 返回项目根目录
cd ..

# 安装 Composer 依赖（如果还没有）
composer install

# 复制并配置 .env 文件
cp .env.example .env

# 编辑 .env，设置基本配置
# APP_MODE=desktop
# DESKTOP_READONLY=true
# DB_CONNECTION=sqlite

# 生成应用密钥
php artisan key:generate

# 清除缓存
php artisan config:clear
php artisan cache:clear
```

### 步骤 4：启动应用

```bash
# 进入 electron-prototype 目录
cd electron-prototype

# 启动应用
npm start
```

第一次启动时，应用会提示您选择 SQLite 数据库文件。

**选择路径示例**：
```
/path/to/cbdb-online-main-server/database/database.sqlite3
```

## 📖 使用说明

### 首次启动

1. 运行 `npm start`
2. 会弹出欢迎对话框
3. 点击"选择数据库文件"
4. 浏览到 `database/database.sqlite3` 并选择
5. 应用自动启动 PHP 服务器
6. 主窗口打开，显示 CBDB Online 界面

### 后续启动

应用会记住您选择的数据库路径，直接启动。

### 更换数据库

菜单栏：**CBDB Online > 更换数据库...**

### 调试模式

```bash
# 启动时自动打开开发者工具
npm run dev
```

## 🔍 故障排查

### 问题 1：找不到 PHP

**错误信息**：
```
Error: spawn php ENOENT
```

**解决方案**：

```bash
# 检查 PHP 是否安装
which php

# 如果未安装，使用 Homebrew 安装
brew install php@8.4

# 如果已安装但路径不对，编辑 electron/main.js
# 修改 getPHPBinaryPath() 函数中的路径
```

### 问题 2：PHP 版本过低

**错误信息**：
```
PHP Fatal error: ...requires PHP 8.1
```

**解决方案**：

```bash
# 升级 PHP
brew upgrade php

# 或安装指定版本
brew install php@8.4

# 切换到新版本
brew link php@8.4 --force --overwrite
```

### 问题 3：数据库文件无效

**错误信息**：
```
选择的文件不是有效的 SQLite 数据库文件
```

**解决方案**：

1. 确认文件扩展名是 `.sqlite3`、`.sqlite` 或 `.db`
2. 检查文件是否损坏：
   ```bash
   sqlite3 database.sqlite3 "PRAGMA integrity_check;"
   ```
3. 重新导出数据库

### 问题 4：端口被占用

**错误信息**：
```
Error: listen EADDRINUSE: address already in use :::8000
```

**解决方案**：

应用会自动查找可用端口（8000-8100），通常不会出现此问题。

如果仍然出现，检查是否有其他 CBDB 实例在运行：

```bash
# 查找占用端口的进程
lsof -i :8000

# 结束进程
kill -9 <PID>
```

### 问题 5：应用启动后白屏

**可能原因**：
- PHP 服务器未正常启动
- Laravel 配置错误

**解决方案**：

1. 使用调试模式启动：
   ```bash
   npm run dev
   ```

2. 查看开发者工具的 Console 和 Network 标签

3. 检查 Laravel 日志：
   ```bash
   tail -f ../storage/logs/laravel.log
   ```

## 📁 项目结构

```
electron-prototype/
├── electron/                   # Electron 主进程代码
│   ├── main.js                # 应用入口
│   ├── php-server.js          # PHP 服务器管理
│   └── database-manager.js    # 数据库路径管理
├── package.json               # 项目配置
├── README.md                  # 本文件
└── node_modules/              # 依赖（npm install 后生成）
```

## 🎯 开发说明

### 修改 PHP 路径检测

编辑 `electron/main.js` 中的 `getPHPBinaryPath()` 函数：

```javascript
function getPHPBinaryPath() {
  const possiblePaths = [
    '/opt/homebrew/bin/php',        // Homebrew (Apple Silicon)
    '/usr/local/bin/php',            // Homebrew (Intel)
    '/your/custom/path/to/php',     // 添加自定义路径
  ];
  // ...
}
```

### 修改 Laravel 路径

编辑 `electron/main.js` 中的 `getLaravelPath()` 函数：

```javascript
function getLaravelPath() {
  // 默认：electron-prototype 的父目录
  const laravelPath = path.resolve(__dirname, '../..');

  // 或指定绝对路径
  // const laravelPath = '/absolute/path/to/cbdb-online-main-server';

  return laravelPath;
}
```

### 添加自定义菜单项

编辑 `electron/main.js` 中的 `createApplicationMenu()` 函数。

## 🔐 安全说明

### 只读模式

应用默认启用只读模式（`DESKTOP_READONLY=true`），所有编辑操作将被阻止：

- 无需用户登录
- 所有 POST/PUT/PATCH/DELETE 请求返回 403
- 编辑按钮自动隐藏（需配合 DESKTOP_READONLY_MODE.md 实施）

### 数据库权限

应用对数据库文件只需**读权限**，不会修改数据。

## 🚧 已知限制

### 当前版本（0.1.0 Prototype）

- ❌ 仅支持 macOS（未测试 Windows/Linux）
- ❌ 未包含 PHP 二进制（需系统已安装）
- ❌ 未实现数据库压缩/解压
- ❌ 未实现自动更新
- ❌ 未实现 Splash 加载画面

### 未来计划

- ⏳ 打包 PHP 二进制到应用中
- ⏳ 支持压缩的 SQLite 数据库（.zst）
- ⏳ 美化的启动画面
- ⏳ 应用图标
- ⏳ 代码签名和公证（macOS 分发）
- ⏳ Windows 和 Linux 支持

## 📚 相关文档

- [ELECTRON_APP_PLAN.md](../ELECTRON_APP_PLAN.md) - 完整的 Electron 应用实施方案
- [DESKTOP_READONLY_MODE.md](../DESKTOP_READONLY_MODE.md) - 只读模式实现指南
- [SQLITE_COMPRESSION_GUIDE.md](../SQLITE_COMPRESSION_GUIDE.md) - SQLite 压缩方案

## 🤝 反馈与贡献

如有问题或建议，请：

1. 查看本 README 的故障排查章节
2. 查看控制台日志（`npm run dev` 模式）
3. 提交 Issue 到 GitHub

## 📄 授权

本项目遵循 CC BY-NC-SA 4.0 授权协议。

---

**版本**：0.1.0 (Prototype)
**创建日期**：2025-12-28
**维护者**：CBDB 开发团队
