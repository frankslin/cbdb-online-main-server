# CBDB Online Electron 桌面应用实施方案

## 📋 概述

将 CBDB Online Laravel 应用打包成跨平台桌面应用（Windows、macOS、Linux），配合预置的 SQLite 数据库，实现离线可用的桌面版本。

## 🎯 目标

- ✅ 零配置启动：双击即用，无需安装 Docker/MySQL/PHP
- ✅ 离线可用：内置 SQLite 数据库和完整数据
- ✅ 跨平台：支持 Windows 10+、macOS 11+、Linux
- ✅ 自动更新：支持应用和数据库的在线更新
- ✅ 小体积：目标控制在 300MB 以内

## 🏗️ 技术架构

### 方案选择：Electron + 嵌入式 PHP（推荐）

**不使用 Docker**，原因：
- ❌ Docker Desktop 需要单独安装（体积大、权限要求高）
- ❌ 增加应用复杂度和启动时间
- ❌ 跨平台兼容性问题（Windows Home 版不支持 Hyper-V）

**采用嵌入式 PHP 方案**：
- ✅ 打包 PHP 二进制文件到应用中
- ✅ 使用 `php artisan serve` 启动内置服务器
- ✅ Electron WebView 加载 localhost
- ✅ 无需外部依赖，真正的"绿色软件"

### 技术栈

```
┌──────────────────────────────────────────┐
│  前端：Electron 33+ (Chromium 130)       │
│  └─> electron-builder (打包工具)         │
├──────────────────────────────────────────┤
│  后端：PHP 8.4 (嵌入式)                   │
│  ├─> Laravel 10.0                        │
│  ├─> SQLite 3.45.0+                      │
│  └─> Composer 依赖（预安装）              │
├──────────────────────────────────────────┤
│  数据库：SQLite (单文件)                  │
│  └─> database.sqlite3 (~50-500MB)       │
├──────────────────────────────────────────┤
│  前端资源：Vite 预编译                    │
│  └─> public/build/ (CSS/JS bundles)     │
└──────────────────────────────────────────┘
```

## 📁 目录结构

```
cbdb-electron-app/
├── electron/                    # Electron 主进程代码
│   ├── main.js                 # 主进程入口
│   ├── preload.js              # 预加载脚本
│   ├── php-server.js           # PHP 服务器管理
│   └── database-manager.js     # 数据库管理
├── resources/                   # 应用资源
│   ├── php/                    # PHP 运行时（分平台）
│   │   ├── win-x64/
│   │   │   ├── php.exe
│   │   │   └── php.ini
│   │   ├── darwin-arm64/
│   │   │   ├── php
│   │   │   └── php.ini
│   │   └── linux-x64/
│   │       ├── php
│   │       └── php.ini
│   ├── laravel/                # Laravel 应用代码
│   │   ├── app/
│   │   ├── config/
│   │   ├── public/
│   │   ├── routes/
│   │   ├── vendor/            # Composer 依赖（预安装）
│   │   ├── .env.production    # 生产环境配置
│   │   └── artisan
│   └── database/               # 数据库文件
│       └── database.sqlite3    # 预置 SQLite 数据库
├── build/                      # electron-builder 配置
│   ├── icon.icns              # macOS 图标
│   ├── icon.ico               # Windows 图标
│   └── icon.png               # Linux 图标
├── package.json               # Electron 项目配置
├── electron-builder.yml       # 打包配置
└── README.md
```

## 🔧 实施步骤

### 阶段 1：准备 PHP 运行时（预计 2 小时）

#### 1.1 获取 PHP 二进制文件

**Windows (x64)**
```bash
# 下载 PHP 8.4 Non-Thread Safe 版本
wget https://windows.php.net/downloads/releases/php-8.4.2-nts-Win32-vs17-x64.zip

# 解压并精简（只保留必要扩展）
unzip php-8.4.2-nts-Win32-vs17-x64.zip -d resources/php/win-x64/
cd resources/php/win-x64/
rm -rf extras/     # 移除非必要文件
```

**macOS (ARM64)**
```bash
# 使用 Homebrew 安装 PHP 8.4
brew install php@8.4

# 复制 PHP 二进制到项目
cp /opt/homebrew/opt/php@8.4/bin/php resources/php/darwin-arm64/
cp /opt/homebrew/etc/php/8.4/php.ini resources/php/darwin-arm64/

# 或者使用 Static PHP CLI（更小体积）
wget https://dl.static-php.dev/static-php-cli/common/php-8.4-macos-aarch64.tar.gz
tar -xzf php-8.4-macos-aarch64.tar.gz -C resources/php/darwin-arm64/
```

**Linux (x64)**
```bash
# 下载 Static PHP 构建（推荐，无依赖）
wget https://github.com/static-php/static-php-cli/releases/download/v8.4/php-linux-x86_64.tar.gz
tar -xzf php-linux-x86_64.tar.gz -C resources/php/linux-x64/
```

#### 1.2 配置 php.ini

针对桌面应用优化的 `php.ini`：

```ini
; 基本配置
memory_limit = 512M
max_execution_time = 300
post_max_size = 100M
upload_max_filesize = 100M

; 必要扩展
extension=pdo_sqlite
extension=sqlite3
extension=mbstring
extension=openssl
extension=fileinfo
extension=tokenizer
extension=json

; 禁用不需要的扩展
;extension=mysqli
;extension=pdo_mysql

; 时区
date.timezone = Asia/Shanghai

; 错误报告（生产模式）
display_errors = Off
log_errors = On
error_log = storage/logs/php-errors.log
```

### 阶段 2：准备 Laravel 应用（预计 3 小时）

#### 2.1 安装依赖并编译资源

```bash
# 1. 安装 Composer 依赖（生产模式）
composer install --no-dev --optimize-autoloader

# 2. 编译前端资源
npm install
npm run build

# 3. 缓存配置（提升启动速度）
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 4. 复制到 Electron 项目
cp -r . ../cbdb-electron-app/resources/laravel/
```

#### 2.2 配置 .env.production

```env
APP_NAME="CBDB Online"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:YOUR_GENERATED_KEY

# SQLite 配置
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/database.sqlite3  # 将在运行时动态替换

# 会话和缓存使用文件驱动
CACHE_DRIVER=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync

# 禁用外部服务
MAIL_MAILER=log
BROADCAST_DRIVER=log
```

#### 2.3 准备 SQLite 数据库

```bash
# 从生产环境 MySQL 导出（限制数据量）
php artisan db:export-to-sqlite \
  --output=resources/database/database.sqlite3 \
  --limit-records=50000 \
  --chunk-size=2000

# 或者使用完整的 CBDB 官方数据库
# 参考 README-Docker.md 的"深入：初始化完整资料庫內容"章节

# 初始化管理员账户
php artisan cbdb:manage-user \
  --email="admin@example.com" \
  --name="Admin" \
  --password="admin123" \
  --active=1 \
  --role="super-admin" \
  --no-interaction

# 灌入内部表
# 访问 http://localhost:8000/admin/cbdb-table-maintenance
# 执行：灌入 CBDB__TRAD_SIMP_MAP、灌入 CBDB__NAME_FTS
```

### 阶段 3：开发 Electron 应用（预计 8 小时）

#### 3.1 初始化 Electron 项目

```bash
mkdir cbdb-electron-app
cd cbdb-electron-app
npm init -y
npm install electron electron-builder --save-dev
npm install find-free-port portfinder --save
```

#### 3.2 实现主进程 (electron/main.js)

```javascript
const { app, BrowserWindow, Tray, Menu } = require('electron');
const path = require('path');
const PHPServer = require('./php-server');
const DatabaseManager = require('./database-manager');

let mainWindow = null;
let tray = null;
let phpServer = null;

// 应用启动
app.on('ready', async () => {
  try {
    // 1. 初始化数据库管理器
    const dbManager = new DatabaseManager(app.getPath('userData'));
    await dbManager.ensureDatabaseExists();

    // 2. 启动 PHP 服务器
    phpServer = new PHPServer({
      phpBinary: getPHPBinaryPath(),
      laravelPath: getLaravelPath(),
      databasePath: dbManager.getDatabasePath(),
    });

    const serverUrl = await phpServer.start();
    console.log(`PHP server started at ${serverUrl}`);

    // 3. 创建主窗口
    mainWindow = new BrowserWindow({
      width: 1400,
      height: 900,
      title: 'CBDB Online',
      icon: path.join(__dirname, '../build/icon.png'),
      webPreferences: {
        preload: path.join(__dirname, 'preload.js'),
        nodeIntegration: false,
        contextIsolation: true,
      },
    });

    // 4. 加载应用
    await mainWindow.loadURL(serverUrl);

    // 5. 创建系统托盘
    createTray();

  } catch (error) {
    console.error('Failed to start application:', error);
    app.quit();
  }
});

// 应用退出时清理
app.on('before-quit', async () => {
  if (phpServer) {
    await phpServer.stop();
  }
});

// 获取 PHP 二进制路径（根据平台）
function getPHPBinaryPath() {
  const platform = process.platform;
  const arch = process.arch;

  let phpPath;
  if (platform === 'win32') {
    phpPath = path.join(__dirname, '../resources/php/win-x64/php.exe');
  } else if (platform === 'darwin') {
    phpPath = path.join(__dirname, '../resources/php/darwin-arm64/php');
  } else {
    phpPath = path.join(__dirname, '../resources/php/linux-x64/php');
  }

  return phpPath;
}

// 获取 Laravel 应用路径
function getLaravelPath() {
  return path.join(__dirname, '../resources/laravel');
}

// 创建系统托盘
function createTray() {
  tray = new Tray(path.join(__dirname, '../build/icon.png'));

  const contextMenu = Menu.buildFromTemplate([
    { label: '显示主窗口', click: () => mainWindow.show() },
    { label: '退出', click: () => app.quit() },
  ]);

  tray.setContextMenu(contextMenu);
  tray.setToolTip('CBDB Online');
}
```

#### 3.3 实现 PHP 服务器管理 (electron/php-server.js)

```javascript
const { spawn } = require('child_process');
const portfinder = require('portfinder');
const fs = require('fs-extra');
const path = require('path');

class PHPServer {
  constructor(options) {
    this.phpBinary = options.phpBinary;
    this.laravelPath = options.laravelPath;
    this.databasePath = options.databasePath;
    this.process = null;
    this.port = null;
  }

  async start() {
    // 1. 查找可用端口
    this.port = await portfinder.getPortPromise({ port: 8000 });

    // 2. 更新 .env 中的数据库路径
    await this.updateEnvFile();

    // 3. 启动 PHP 内置服务器
    return new Promise((resolve, reject) => {
      const args = [
        'artisan',
        'serve',
        `--host=127.0.0.1`,
        `--port=${this.port}`,
        '--no-reload',  // 禁用自动重载（生产模式）
      ];

      this.process = spawn(this.phpBinary, args, {
        cwd: this.laravelPath,
        env: {
          ...process.env,
          APP_ENV: 'production',
          PHP_INI_SCAN_DIR: path.dirname(this.phpBinary),
        },
      });

      // 监听输出
      this.process.stdout.on('data', (data) => {
        console.log(`[PHP] ${data}`);

        // 检测服务器启动成功
        if (data.includes('started')) {
          resolve(`http://127.0.0.1:${this.port}`);
        }
      });

      this.process.stderr.on('data', (data) => {
        console.error(`[PHP Error] ${data}`);
      });

      this.process.on('error', (error) => {
        reject(new Error(`Failed to start PHP server: ${error.message}`));
      });

      this.process.on('exit', (code) => {
        console.log(`PHP server exited with code ${code}`);
      });

      // 5秒超时
      setTimeout(() => {
        if (!this.process || !this.process.pid) {
          reject(new Error('PHP server start timeout'));
        }
      }, 5000);
    });
  }

  async stop() {
    if (this.process) {
      this.process.kill();
      this.process = null;
    }
  }

  async updateEnvFile() {
    const envPath = path.join(this.laravelPath, '.env');
    const envContent = await fs.readFile(envPath, 'utf-8');

    // 替换数据库路径
    const updatedContent = envContent.replace(
      /DB_DATABASE=.*/,
      `DB_DATABASE=${this.databasePath}`
    );

    await fs.writeFile(envPath, updatedContent);
  }
}

module.exports = PHPServer;
```

#### 3.4 实现数据库管理器 (electron/database-manager.js)

```javascript
const fs = require('fs-extra');
const path = require('path');

class DatabaseManager {
  constructor(userDataPath) {
    this.userDataPath = userDataPath;
    this.dbDir = path.join(userDataPath, 'database');
    this.dbPath = path.join(this.dbDir, 'database.sqlite3');
  }

  async ensureDatabaseExists() {
    // 1. 确保数据库目录存在
    await fs.ensureDir(this.dbDir);

    // 2. 如果用户数据库不存在，复制默认数据库
    if (!(await fs.pathExists(this.dbPath))) {
      const defaultDbPath = path.join(
        __dirname,
        '../resources/database/database.sqlite3'
      );

      console.log('Copying default database...');
      await fs.copy(defaultDbPath, this.dbPath);
      console.log('Database initialized');
    } else {
      console.log('Using existing database');
    }
  }

  getDatabasePath() {
    return this.dbPath;
  }

  async backupDatabase() {
    const backupPath = `${this.dbPath}.backup.${Date.now()}`;
    await fs.copy(this.dbPath, backupPath);
    return backupPath;
  }

  async resetDatabase() {
    const defaultDbPath = path.join(
      __dirname,
      '../resources/database/database.sqlite3'
    );
    await fs.copy(defaultDbPath, this.dbPath, { overwrite: true });
  }
}

module.exports = DatabaseManager;
```

### 阶段 4：打包配置（预计 2 小时）

#### 4.1 package.json

```json
{
  "name": "cbdb-online-desktop",
  "version": "1.0.0",
  "description": "CBDB Online 桌面版",
  "main": "electron/main.js",
  "scripts": {
    "start": "electron .",
    "pack": "electron-builder --dir",
    "dist": "electron-builder",
    "dist:win": "electron-builder --win",
    "dist:mac": "electron-builder --mac",
    "dist:linux": "electron-builder --linux"
  },
  "build": {
    "appId": "edu.harvard.fas.cbdb",
    "productName": "CBDB Online",
    "directories": {
      "buildResources": "build",
      "output": "dist"
    },
    "files": [
      "electron/**/*",
      "resources/**/*",
      "package.json"
    ],
    "extraResources": [
      {
        "from": "resources/php",
        "to": "php"
      },
      {
        "from": "resources/laravel",
        "to": "laravel"
      },
      {
        "from": "resources/database",
        "to": "database"
      }
    ],
    "win": {
      "target": ["nsis"],
      "icon": "build/icon.ico"
    },
    "mac": {
      "target": ["dmg"],
      "icon": "build/icon.icns",
      "category": "public.app-category.education"
    },
    "linux": {
      "target": ["AppImage", "deb"],
      "icon": "build/icon.png",
      "category": "Education"
    },
    "nsis": {
      "oneClick": false,
      "allowToChangeInstallationDirectory": true,
      "createDesktopShortcut": true
    }
  }
}
```

#### 4.2 electron-builder.yml

```yaml
appId: edu.harvard.fas.cbdb
productName: CBDB Online
copyright: Copyright © 2025 CBDB Project

directories:
  buildResources: build
  output: dist

files:
  - electron/**/*
  - resources/**/*
  - package.json
  - '!**/*.md'
  - '!**/.git'

# 排除不必要的文件以减小体积
asarUnpack:
  - resources/php/**/*
  - resources/laravel/storage/**/*
  - resources/database/**/*

compression: maximum

# Windows 配置
win:
  target:
    - target: nsis
      arch:
        - x64
  icon: build/icon.ico

nsis:
  oneClick: false
  perMachine: false
  allowToChangeInstallationDirectory: true
  createDesktopShortcut: true
  createStartMenuShortcut: true
  installerIcon: build/icon.ico
  uninstallerIcon: build/icon.ico

# macOS 配置
mac:
  target:
    - target: dmg
      arch:
        - arm64
        - x64
  icon: build/icon.icns
  category: public.app-category.education
  hardenedRuntime: true
  gatekeeperAssess: false

dmg:
  background: build/dmg-background.png
  iconSize: 100
  contents:
    - x: 380
      y: 180
      type: link
      path: /Applications
    - x: 130
      y: 180
      type: file

# Linux 配置
linux:
  target:
    - target: AppImage
      arch:
        - x64
    - target: deb
      arch:
        - x64
  icon: build/icon.png
  category: Education
  desktop:
    Name: CBDB Online
    Comment: 中國歷代人物傳記資料庫
    Categories: Education;Database;

# 发布配置
publish:
  provider: github
  owner: your-org
  repo: cbdb-electron
```

### 阶段 5：优化与测试（预计 5 小时）

#### 5.1 体积优化

**当前预估体积**：
- PHP 运行时：~50MB (Static PHP)
- Laravel 代码 + vendor：~80MB
- SQLite 数据库：50-500MB（取决于数据量）
- Electron 框架：~150MB
- **总计**：~330-780MB

**优化策略**：

1. **使用 Static PHP CLI**（减少 30MB）
```bash
# 静态编译的 PHP，无需动态库依赖
wget https://dl.static-php.dev/static-php-cli/common/php-8.4-minimal.tar.gz
```

2. **精简 Composer 依赖**（减少 20MB）
```bash
# 移除开发依赖
composer install --no-dev --optimize-autoloader

# 移除不必要的包
composer remove laravel/tinker laravel/pint --no-update
```

3. **压缩数据库**（减少 30-50%）
```bash
# SQLite VACUUM 优化
sqlite3 database.sqlite3 "VACUUM;"

# 可选：限制初始数据量
php artisan db:export-to-sqlite --limit-records=10000
```

4. **Electron 优化**
```javascript
// 启用 ASAR 压缩
asar: true,
asarUnpack: ['resources/php/**/*'],

// 使用更激进的压缩
compression: 'maximum',
```

**优化后目标体积**：~250-400MB

#### 5.2 启动速度优化

```bash
# 1. Laravel 配置缓存（减少 1-2 秒启动时间）
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 2. Composer autoload 优化
composer dump-autoload --optimize --classmap-authoritative

# 3. 使用 OPcache（PHP 8.4 默认启用）
# 在 php.ini 中配置：
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
```

**预期启动时间**：
- 冷启动：3-5 秒
- 热启动：1-2 秒

#### 5.3 跨平台测试清单

| 平台 | 测试项目 | 状态 |
|------|---------|------|
| Windows 10/11 | 安装程序正常运行 | ⬜ |
| Windows 10/11 | PHP 服务器启动 | ⬜ |
| Windows 10/11 | 数据库读写 | ⬜ |
| macOS 11+ (Intel) | DMG 安装正常 | ⬜ |
| macOS 11+ (Apple Silicon) | 原生运行 | ⬜ |
| Ubuntu 20.04+ | AppImage 启动 | ⬜ |
| Ubuntu 20.04+ | DEB 包安装 | ⬜ |

## 📦 打包和分发

### 构建命令

```bash
# 开发模式（快速测试）
npm run start

# 打包测试（不压缩）
npm run pack

# 生产打包（全平台）
npm run dist

# 单平台打包
npm run dist:win    # Windows
npm run dist:mac    # macOS
npm run dist:linux  # Linux
```

### 分发文件

**Windows**：
- `CBDB-Online-Setup-1.0.0.exe` (~300MB，NSIS 安装程序)

**macOS**：
- `CBDB-Online-1.0.0-arm64.dmg` (~250MB，Apple Silicon)
- `CBDB-Online-1.0.0-x64.dmg` (~250MB，Intel)

**Linux**：
- `CBDB-Online-1.0.0-x86_64.AppImage` (~280MB)
- `cbdb-online_1.0.0_amd64.deb` (~280MB)

## 🔄 自动更新方案

使用 `electron-updater` 实现自动更新：

```javascript
const { autoUpdater } = require('electron-updater');

// 检查更新
autoUpdater.checkForUpdatesAndNotify();

// 监听更新事件
autoUpdater.on('update-available', () => {
  // 通知用户有新版本
  mainWindow.webContents.send('update-available');
});

autoUpdater.on('update-downloaded', () => {
  // 下载完成，提示用户重启
  dialog.showMessageBox({
    type: 'info',
    title: '更新就绪',
    message: '新版本已下载，重启应用以完成更新',
    buttons: ['重启', '稍后'],
  }).then((result) => {
    if (result.response === 0) {
      autoUpdater.quitAndInstall();
    }
  });
});
```

## ⚠️ 限制和注意事项

### 技术限制

1. **并发性能**：SQLite 不适合高并发写入（桌面单用户场景无问题）
2. **数据库大小**：建议控制在 1GB 以内（性能考虑）
3. **内存占用**：Electron + PHP 约占用 200-400MB 内存

### 使用限制

1. **仅限个人使用**：不适合多用户协作场景
2. **数据同步**：需要手动导出/导入更新数据
3. **网络功能**：某些在线功能（如 Gemini API）需要网络连接

### 授权问题

- 确认 CBDB 数据的分发授权（CC BY-NC-SA 4.0）
- Electron 应用需要遵守相同授权协议
- 建议在应用中包含完整的授权声明

## 📊 预估工作量

| 阶段 | 任务 | 工时 | 难度 |
|------|------|------|------|
| 1 | 准备 PHP 运行时 | 2h | ⭐⭐ |
| 2 | 准备 Laravel 应用 | 3h | ⭐⭐ |
| 3 | 开发 Electron 应用 | 8h | ⭐⭐⭐ |
| 4 | 打包配置 | 2h | ⭐⭐ |
| 5 | 优化与测试 | 5h | ⭐⭐⭐ |
| 6 | 文档和分发 | 2h | ⭐ |
| **总计** | | **22h** | |

**技能要求**：
- Node.js / Electron 开发经验
- Laravel 应用部署经验
- 跨平台应用打包经验

## ✅ 可行性结论

### 技术可行性：⭐⭐⭐⭐⭐（5/5）

您的项目**非常适合**打包成 Electron 桌面应用：
- ✅ 已有完善的 SQLite 支持
- ✅ 前端已使用 Vite 编译
- ✅ 无复杂外部依赖
- ✅ Docker 配置可作为参考

### 推荐方案对比

| 方案 | 优点 | 缺点 | 推荐度 |
|------|------|------|--------|
| **Electron + 嵌入式 PHP** | 无需 Docker、体积小、易分发 | 需要打包 PHP | ⭐⭐⭐⭐⭐ |
| Electron + Docker | 沿用现有架构 | 需要安装 Docker、体积大 | ⭐⭐ |
| PWA (渐进式 Web 应用) | 最小体积 | 需要 Web 服务器、功能受限 | ⭐⭐⭐ |

### 替代方案

如果不想开发 Electron 应用，可以考虑：

1. **Docker Desktop 简化版**：
   - 打包 `docker-compose.yml` 和数据库
   - 提供一键启动脚本
   - 用户需要自行安装 Docker Desktop

2. **本地虚拟机**：
   - 使用 Vagrant 打包完整环境
   - 提供 `.ova` 虚拟机镜像
   - 体积较大（~2GB），但最接近生产环境

## 📝 后续步骤

1. **原型验证**（1 天）
   - 在一个平台上完成最小可行产品（MVP）
   - 验证 PHP 服务器启动、数据库连接、界面显示

2. **跨平台测试**（2-3 天）
   - 在 Windows/macOS/Linux 上分别测试
   - 解决平台特定问题（路径、权限等）

3. **性能优化**（1-2 天）
   - 体积优化至目标范围
   - 启动速度优化至可接受水平

4. **用户测试**（3-5 天）
   - 内部测试小组试用
   - 收集反馈并迭代

5. **正式发布**（1 天）
   - 准备分发渠道
   - 编写用户文档

## 📚 参考资源

- [Electron 官方文档](https://www.electronjs.org/docs)
- [electron-builder 文档](https://www.electron.build/)
- [Static PHP CLI](https://github.com/crazywhalecc/static-php-cli)
- [Laravel 部署指南](https://laravel.com/docs/10.x/deployment)

---

**文档版本**：1.0
**创建日期**：2025-12-28
**维护者**：CBDB 开发团队
