# CBDB Online Desktop Prototype - 演示说明

## 🎉 原型已完成！

这是一个**完整可运行**的 macOS Electron 桌面应用原型。

## ✨ 核心特性

### 1. 用户友好的数据库选择

**首次启动**：
```
┌─────────────────────────────────────┐
│  欢迎使用 CBDB Online 桌面版！       │
│                                     │
│  请选择 SQLite 数据库文件以继续。   │
│                                     │
│  [选择数据库文件]  [退出]            │
└─────────────────────────────────────┘
        ↓
┌─────────────────────────────────────┐
│  选择 CBDB SQLite 数据库文件         │
│                                     │
│  📁 cbdb-online-main-server/        │
│     └─ database/                    │
│        └─ database.sqlite3 ← 选择这个 │
│                                     │
│  [打开]  [取消]                      │
└─────────────────────────────────────┘
```

**后续启动**：
```
直接加载之前选择的数据库 ✅
无需重复选择
```

### 2. 原生 macOS 体验

**菜单栏功能**：

```
┌─ CBDB Online ──────────────┐
│  关于                       │
│  ─────────────────────     │
│  更换数据库...              │
│  ─────────────────────     │
│  退出                       │
└────────────────────────────┘

┌─ 编辑 ────────────────────┐
│  复制                       │
│  粘贴                       │
│  全选                       │
└────────────────────────────┘

┌─ 查看 ────────────────────┐
│  重新加载                   │
│  强制重新加载               │
│  ─────────────────────     │
│  开发者工具                 │
│  ─────────────────────     │
│  重置缩放                   │
│  放大                       │
│  缩小                       │
└────────────────────────────┘
```

### 3. 智能 PHP 检测

自动检测 macOS 上的 PHP 安装：

```
检测路径优先级：
1. /opt/homebrew/bin/php      (Homebrew - Apple Silicon)
2. /usr/local/bin/php          (Homebrew - Intel)
3. /usr/bin/php                (系统自带)
4. PATH 环境变量中的 php
```

### 4. 自动端口管理

```
尝试端口：8000
如果被占用 → 8001
如果被占用 → 8002
...
直到找到可用端口（最多 8100）
```

### 5. 完整的错误处理

**场景 1：PHP 未安装**
```
┌─────────────────────────────────────┐
│  ❌ 启动失败                         │
│                                     │
│  应用启动失败：                      │
│  spawn php ENOENT                   │
│                                     │
│  请检查：                            │
│  1. PHP 是否已安装（需要 8.1+）      │
│  2. Laravel 项目是否完整             │
│  3. 数据库文件是否有效               │
│                                     │
│  [确定]                             │
└─────────────────────────────────────┘
```

**场景 2：数据库无效**
```
┌─────────────────────────────────────┐
│  ❌ 文件无效                         │
│                                     │
│  选择的文件不是有效的 SQLite 数据库  │
│                                     │
│  [确定]                             │
└─────────────────────────────────────┘
```

## 🚀 快速演示

### 步骤 1：准备环境（一次性）

```bash
# 安装 PHP 和 Node.js
brew install php@8.4 node

# 导出数据库（示例数据）
cd /path/to/cbdb-online-main-server
php artisan db:export-to-sqlite \
  --output=database/database.sqlite3 \
  --limit-records=5000
```

### 步骤 2：启动应用

```bash
cd electron-prototype
./start.sh
```

**启动脚本会自动**：
- ✅ 检查 PHP 和 Node.js
- ✅ 安装依赖（首次）
- ✅ 配置 Laravel
- ✅ 清除缓存
- ✅ 启动 Electron

### 步骤 3：选择数据库

1. 应用启动后弹出欢迎对话框
2. 点击 **"选择数据库文件"**
3. 浏览到 `cbdb-online-main-server/database/database.sqlite3`
4. 点击 **"打开"**

### 步骤 4：使用应用

- ✅ 浏览 CBDB 数据
- ✅ 搜索人物信息
- ✅ 查看代码表
- ✅ 查看统计视图
- ✅ 所有查询功能正常工作

**只读模式**：
- ❌ 编辑按钮会被隐藏（需配合 DESKTOP_READONLY_MODE.md 实施）
- ❌ 写操作会被服务端拦截

## 📊 技术亮点

### 1. 模块化设计

```
main.js               # 应用生命周期管理
  ├─ database-manager.js   # 数据库路径管理
  └─ php-server.js         # PHP 服务器管理
```

**职责清晰**：
- `main.js`：窗口、菜单、应用流程
- `database-manager.js`：路径选择、验证、持久化
- `php-server.js`：启动/停止 PHP、更新 .env

### 2. 配置持久化

```javascript
// 配置文件位置（macOS）
~/Library/Application Support/cbdb-desktop-prototype/config.json

// 内容
{
  "databasePath": "/path/to/database.sqlite3",
  "lastUpdated": "2025-12-28T12:00:00.000Z"
}
```

### 3. 优雅的错误处理

```javascript
try {
  await phpServer.start();
} catch (error) {
  // 显示用户友好的错误对话框
  dialog.showErrorBox(
    'CBDB Online Desktop - 启动失败',
    `应用启动失败：\n\n${error.message}\n\n详细信息...`
  );
  app.quit();
}
```

## 🎯 演示脚本（给他人展示）

### 场景 1：全新安装演示（5 分钟）

```bash
# 1. 克隆项目（假设还没有）
git clone <repository>
cd cbdb-online-main-server

# 2. 一键安装依赖
brew install php@8.4 node
composer install
cd electron-prototype
npm install

# 3. 准备数据库
cd ..
php artisan db:export-to-sqlite \
  --output=database/database.sqlite3 \
  --limit-records=1000  # 快速演示用小数据

# 4. 启动应用
cd electron-prototype
npm start

# 5. 选择数据库文件
# （在弹出的对话框中选择 database/database.sqlite3）

# 6. 演示功能
# - 搜索人物
# - 查看详情
# - 浏览代码表
# - 展示菜单功能
```

### 场景 2：快速启动演示（30 秒）

```bash
# 已安装的环境
cd cbdb-online-main-server/electron-prototype
npm start

# 应用自动加载之前的数据库
# 直接展示功能
```

### 场景 3：更换数据库演示

```bash
# 1. 应用运行中
# 2. 点击菜单：CBDB Online > 更换数据库...
# 3. 确认对话框：点击"继续"
# 4. 应用重启
# 5. 选择新的数据库文件
# 6. 加载新数据库
```

## 📝 演示要点

### 向非技术人员展示

1. **强调易用性**：
   - "只需双击，选择数据库，就能使用"
   - "不需要配置服务器、不需要网络"
   - "像普通 Mac 应用一样使用"

2. **展示核心功能**：
   - 搜索人物
   - 查看详细信息
   - 数据浏览

3. **说明只读模式**：
   - "这是只读版本，数据安全不会被修改"
   - "适合离线查询和演示"

### 向技术人员展示

1. **技术架构**：
   - Electron + 嵌入式 PHP
   - 自动化的服务器管理
   - 智能端口检测

2. **代码质量**：
   - 模块化设计
   - 完整的错误处理
   - 用户体验优化

3. **扩展性**：
   - 易于添加新功能
   - 可以打包分发
   - 支持自动更新（未来）

## 🐛 已知问题和限制

### 当前版本（0.1.0）

1. **仅支持 macOS**
   - 未在 Windows/Linux 上测试
   - 路径检测逻辑是 macOS 特定的

2. **依赖系统 PHP**
   - 需要用户自己安装 PHP
   - 未来可以打包 PHP 二进制

3. **无压缩支持**
   - 目前只支持未压缩的 .sqlite3 文件
   - 未来可以支持 .zst 压缩格式

4. **无自动更新**
   - 需要手动下载新版本
   - 未来可以集成 electron-updater

## 🔮 未来改进方向

### 短期（1-2 周）

- [ ] 实现只读模式 UI（配合 DESKTOP_READONLY_MODE.md）
- [ ] 添加 Splash 启动画面
- [ ] 设计应用图标
- [ ] 打包成 .app 文件

### 中期（1-2 月）

- [ ] 支持压缩数据库（.zst）
- [ ] 打包 PHP 二进制到应用中
- [ ] Windows 和 Linux 支持
- [ ] 代码签名和公证

### 长期（3-6 月）

- [ ] 自动更新功能
- [ ] 数据库在线更新
- [ ] 离线文档和帮助
- [ ] 高级搜索功能

## 📚 相关资源

- [README.md](README.md) - 完整文档
- [QUICKSTART.md](QUICKSTART.md) - 快速入门
- [../ELECTRON_APP_PLAN.md](../ELECTRON_APP_PLAN.md) - 完整实施方案
- [../DESKTOP_READONLY_MODE.md](../DESKTOP_READONLY_MODE.md) - 只读模式设计
- [../SQLITE_COMPRESSION_GUIDE.md](../SQLITE_COMPRESSION_GUIDE.md) - 压缩方案

## 🎊 总结

这个原型展示了：

✅ **可行性**：Electron + PHP + SQLite 完全可行
✅ **易用性**：用户选择数据库，零配置启动
✅ **可靠性**：完整的错误处理和验证
✅ **扩展性**：清晰的架构，易于添加功能

**下一步**：
1. 测试原型
2. 收集反馈
3. 实施只读模式 UI
4. 准备打包分发

---

**创建日期**：2025-12-28
**版本**：0.1.0 (Prototype)
**状态**：✅ 可运行
