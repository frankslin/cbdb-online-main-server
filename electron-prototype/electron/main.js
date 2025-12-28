const { app, BrowserWindow, Menu, dialog } = require('electron');
const path = require('path');
const PHPServer = require('./php-server');
const DatabaseManager = require('./database-manager');

let mainWindow = null;
let phpServer = null;
let dbManager = null;

// 启动应用
app.on('ready', async () => {
  try {
    console.log('[App] Starting CBDB Online Desktop...');

    // 1. 初始化数据库管理器
    dbManager = new DatabaseManager();
    const databasePath = await dbManager.initialize();

    if (!databasePath) {
      console.log('[App] No database selected, exiting...');
      app.quit();
      return;
    }

    const dbInfo = await dbManager.getDatabaseInfo();
    console.log('[App] Database:', dbInfo.path);
    console.log('[App] Size:', dbInfo.sizeFormatted);

    // 2. 启动 PHP 服务器
    phpServer = new PHPServer({
      phpBinary: getPHPBinaryPath(),
      laravelPath: getLaravelPath(),
      databasePath: databasePath,
    });

    console.log('[App] Starting PHP server...');
    const serverUrl = await phpServer.start();
    console.log('[App] PHP server ready:', serverUrl);

    // 3. 创建主窗口
    mainWindow = createMainWindow();

    // 4. 加载应用
    console.log('[App] Loading application...');
    await mainWindow.loadURL(serverUrl);

    // 5. 创建菜单
    createApplicationMenu();

    console.log('[App] Application ready!');

  } catch (error) {
    console.error('[App] Startup failed:', error);

    dialog.showErrorBox(
      'CBDB Online Desktop - 启动失败',
      `应用启动失败：\n\n${error.message}\n\n` +
      '请检查：\n' +
      '1. PHP 是否已安装（需要 PHP 8.1+）\n' +
      '2. Laravel 项目是否完整\n' +
      '3. 数据库文件是否有效\n\n' +
      '详细错误信息已输出到控制台。'
    );

    app.quit();
  }
});

// 创建主窗口
function createMainWindow() {
  const window = new BrowserWindow({
    width: 1400,
    height: 900,
    title: 'CBDB Online Desktop',
    webPreferences: {
      nodeIntegration: false,
      contextIsolation: true,
    },
  });

  // 开发模式下打开 DevTools
  if (process.argv.includes('--dev')) {
    window.webContents.openDevTools();
  }

  return window;
}

// 创建应用菜单
function createApplicationMenu() {
  const template = [
    {
      label: 'CBDB Online',
      submenu: [
        {
          label: '关于',
          click: async () => {
            const dbInfo = await dbManager.getDatabaseInfo();
            dialog.showMessageBox({
              type: 'info',
              title: '关于 CBDB Online Desktop',
              message: 'CBDB Online Desktop (Prototype)',
              detail:
                '版本：0.1.0\n' +
                '中國歷代人物傳記資料庫\n\n' +
                `数据库：${dbInfo.path}\n` +
                `大小：${dbInfo.sizeFormatted}`,
            });
          },
        },
        { type: 'separator' },
        {
          label: '更换数据库...',
          click: async () => {
            const result = await dialog.showMessageBox({
              type: 'question',
              title: '更换数据库',
              message: '更换数据库后需要重启应用',
              detail: '是否继续？',
              buttons: ['继续', '取消'],
              defaultId: 0,
              cancelId: 1,
            });

            if (result.response === 0) {
              await dbManager.resetDatabasePath();
              app.relaunch();
              app.quit();
            }
          },
        },
        { type: 'separator' },
        { role: 'quit', label: '退出' },
      ],
    },
    {
      label: '编辑',
      submenu: [
        { role: 'copy', label: '复制' },
        { role: 'paste', label: '粘贴' },
        { role: 'selectAll', label: '全选' },
      ],
    },
    {
      label: '查看',
      submenu: [
        { role: 'reload', label: '重新加载' },
        { role: 'forceReload', label: '强制重新加载' },
        { type: 'separator' },
        { role: 'toggleDevTools', label: '开发者工具' },
        { type: 'separator' },
        { role: 'resetZoom', label: '重置缩放' },
        { role: 'zoomIn', label: '放大' },
        { role: 'zoomOut', label: '缩小' },
      ],
    },
    {
      label: '窗口',
      submenu: [
        { role: 'minimize', label: '最小化' },
        { role: 'zoom', label: '缩放' },
        { type: 'separator' },
        { role: 'front', label: '前置所有窗口' },
      ],
    },
    {
      label: '帮助',
      submenu: [
        {
          label: '查看 GitHub',
          click: async () => {
            const { shell } = require('electron');
            await shell.openExternal('https://github.com/cbdb-project');
          },
        },
      ],
    },
  ];

  const menu = Menu.buildFromTemplate(template);
  Menu.setApplicationMenu(menu);
}

// 获取 PHP 二进制路径
function getPHPBinaryPath() {
  // macOS: 尝试使用系统 PHP 或 Homebrew PHP
  const possiblePaths = [
    '/opt/homebrew/bin/php',        // Homebrew (Apple Silicon)
    '/usr/local/bin/php',            // Homebrew (Intel)
    '/usr/bin/php',                  // 系统自带（已过时，不推荐）
  ];

  // 检查哪个路径存在
  const fs = require('fs');
  for (const phpPath of possiblePaths) {
    if (fs.existsSync(phpPath)) {
      console.log('[PHP] Using PHP binary:', phpPath);
      return phpPath;
    }
  }

  // 如果都不存在，尝试使用 PATH 中的 php
  console.log('[PHP] Using PHP from PATH');
  return 'php';
}

// 获取 Laravel 项目路径
function getLaravelPath() {
  // 假设 Laravel 项目在 electron-prototype 的父目录
  const laravelPath = path.resolve(__dirname, '../..');
  console.log('[Laravel] Project path:', laravelPath);
  return laravelPath;
}

// 应用退出前清理
app.on('before-quit', async () => {
  console.log('[App] Cleaning up...');
  if (phpServer) {
    await phpServer.stop();
  }
});

// 所有窗口关闭时退出（macOS 除外）
app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') {
    app.quit();
  }
});

// macOS 激活时重新创建窗口
app.on('activate', () => {
  if (mainWindow === null) {
    createMainWindow();
  }
});
