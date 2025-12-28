const { app, dialog } = require('electron');
const fs = require('fs').promises;
const path = require('path');

class DatabaseManager {
  constructor() {
    this.configPath = path.join(app.getPath('userData'), 'config.json');
    this.databasePath = null;
  }

  async initialize() {
    // 1. 尝试从配置文件读取数据库路径
    const savedPath = await this.getSavedDatabasePath();

    if (savedPath && await this.verifyDatabasePath(savedPath)) {
      this.databasePath = savedPath;
      console.log('[Database] Using saved path:', savedPath);
      return this.databasePath;
    }

    // 2. 如果没有保存的路径或路径无效，提示用户选择
    const selectedPath = await this.promptUserToSelectDatabase();

    if (!selectedPath) {
      throw new Error('未选择数据库文件');
    }

    // 3. 保存用户选择的路径
    await this.saveDatabasePath(selectedPath);
    this.databasePath = selectedPath;

    return this.databasePath;
  }

  async getSavedDatabasePath() {
    try {
      const configData = await fs.readFile(this.configPath, 'utf-8');
      const config = JSON.parse(configData);
      return config.databasePath || null;
    } catch (error) {
      // 配置文件不存在或无效
      return null;
    }
  }

  async verifyDatabasePath(dbPath) {
    try {
      await fs.access(dbPath);
      const stats = await fs.stat(dbPath);
      return stats.isFile() && dbPath.endsWith('.sqlite3');
    } catch (error) {
      return false;
    }
  }

  async promptUserToSelectDatabase() {
    const result = await dialog.showMessageBox({
      type: 'info',
      title: 'CBDB Online Desktop',
      message: '欢迎使用 CBDB Online 桌面版！',
      detail: '请选择 SQLite 数据库文件（database.sqlite3）以继续。\n\n' +
              '如果您还没有数据库文件，请先运行以下命令导出：\n' +
              'php artisan db:export-to-sqlite',
      buttons: ['选择数据库文件', '退出'],
      defaultId: 0,
      cancelId: 1,
    });

    if (result.response === 1) {
      // 用户点击"退出"
      return null;
    }

    // 显示文件选择对话框
    const fileResult = await dialog.showOpenDialog({
      title: '选择 CBDB SQLite 数据库文件',
      properties: ['openFile'],
      filters: [
        { name: 'SQLite 数据库', extensions: ['sqlite3', 'sqlite', 'db'] },
        { name: '所有文件', extensions: ['*'] },
      ],
      message: '请选择 database.sqlite3 文件',
    });

    if (fileResult.canceled || fileResult.filePaths.length === 0) {
      return null;
    }

    const selectedPath = fileResult.filePaths[0];

    // 验证选择的文件
    if (!(await this.verifyDatabasePath(selectedPath))) {
      await dialog.showErrorBox(
        '文件无效',
        '选择的文件不是有效的 SQLite 数据库文件。'
      );
      return null;
    }

    return selectedPath;
  }

  async saveDatabasePath(dbPath) {
    try {
      // 确保 userData 目录存在
      await fs.mkdir(app.getPath('userData'), { recursive: true });

      // 保存配置
      const config = {
        databasePath: dbPath,
        lastUpdated: new Date().toISOString(),
      };

      await fs.writeFile(this.configPath, JSON.stringify(config, null, 2));
      console.log('[Database] Saved path to config:', dbPath);
    } catch (error) {
      console.error('[Database] Failed to save config:', error);
    }
  }

  getDatabasePath() {
    return this.databasePath;
  }

  async resetDatabasePath() {
    // 删除配置，下次启动时重新选择
    try {
      await fs.unlink(this.configPath);
      console.log('[Database] Config removed');
    } catch (error) {
      // 配置文件可能不存在
    }
  }

  async getDatabaseInfo() {
    if (!this.databasePath) {
      return null;
    }

    try {
      const stats = await fs.stat(this.databasePath);
      return {
        path: this.databasePath,
        size: stats.size,
        sizeFormatted: this.formatBytes(stats.size),
        modified: stats.mtime,
      };
    } catch (error) {
      return null;
    }
  }

  formatBytes(bytes) {
    const units = ['B', 'KB', 'MB', 'GB'];
    let size = bytes;
    let unitIndex = 0;

    while (size >= 1024 && unitIndex < units.length - 1) {
      size /= 1024;
      unitIndex++;
    }

    return `${size.toFixed(2)} ${units[unitIndex]}`;
  }
}

module.exports = DatabaseManager;
