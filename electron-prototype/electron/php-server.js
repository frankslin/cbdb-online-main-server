const { spawn } = require('child_process');
const portfinder = require('portfinder');
const path = require('path');

class PHPServer {
  constructor(options) {
    this.frankenphpBinary = options.frankenphpBinary;
    this.laravelPath = options.laravelPath;
    this.databasePath = options.databasePath;
    this.process = null;
    this.port = null;
  }

  async start() {
    // 1. 查找可用端口
    this.port = await portfinder.getPortPromise({ port: 8000 });

    console.log(`[FrankenPHP] Starting on port ${this.port}...`);
    console.log(`[FrankenPHP] Laravel path: ${this.laravelPath}`);
    console.log(`[FrankenPHP] Database path: ${this.databasePath}`);

    // 2. 更新 .env 文件
    await this.updateEnvFile();

    // 3. 启动 FrankenPHP 服务器
    return new Promise((resolve, reject) => {
      // FrankenPHP 使用 php-server 命令（类似 artisan serve）
      const args = [
        'php-server',
        '--listen', `127.0.0.1:${this.port}`,
        '--root', 'public',
      ];

      this.process = spawn(this.frankenphpBinary, args, {
        cwd: this.laravelPath,
        env: {
          ...process.env,
          APP_ENV: 'desktop',
          APP_MODE: 'desktop',
          DESKTOP_READONLY: 'true',
          LOG_CHANNEL: 'null',
        },
      });

      let hasStarted = false;

      // 监听输出
      this.process.stdout.on('data', (data) => {
        const output = data.toString();
        console.log(`[FrankenPHP] ${output}`);

        // FrankenPHP 输出 "listening on..." 表示启动成功
        if (!hasStarted && output.includes('listening on')) {
          hasStarted = true;
          console.log(`[FrankenPHP] Started successfully at http://127.0.0.1:${this.port}`);
          resolve(`http://127.0.0.1:${this.port}`);
        }
      });

      this.process.stderr.on('data', (data) => {
        const output = data.toString();
        // FrankenPHP 的正常日志也会输出到 stderr，所以不全是错误
        console.log(`[FrankenPHP] ${output}`);

        // 如果还没启动，检查是否包含成功信息
        if (!hasStarted && (output.includes('listening on') || output.includes('started'))) {
          hasStarted = true;
          console.log(`[FrankenPHP] Started successfully at http://127.0.0.1:${this.port}`);
          resolve(`http://127.0.0.1:${this.port}`);
        }
      });

      this.process.on('error', (error) => {
        reject(new Error(`Failed to start FrankenPHP: ${error.message}`));
      });

      this.process.on('exit', (code) => {
        console.log(`[FrankenPHP] Exited with code ${code}`);
      });

      // 5秒超时
      setTimeout(() => {
        if (!hasStarted) {
          reject(new Error('FrankenPHP start timeout (5s)'));
        }
      }, 5000);
    });
  }

  async stop() {
    if (this.process) {
      console.log('[FrankenPHP] Stopping...');
      this.process.kill();
      this.process = null;
    }
  }

  async updateEnvFile() {
    const fs = require('fs').promises;
    const envPath = path.join(this.laravelPath, '.env');

    try {
      let envContent = await fs.readFile(envPath, 'utf-8');

      // 替换数据库路径
      envContent = envContent.replace(
        /DB_DATABASE=.*/,
        `DB_DATABASE=${this.databasePath}`
      );

      // 确保桌面模式配置
      if (!envContent.includes('APP_MODE=')) {
        envContent += '\nAPP_MODE=desktop';
      } else {
        envContent = envContent.replace(/APP_MODE=.*/, 'APP_MODE=desktop');
      }

      if (!envContent.includes('DESKTOP_READONLY=')) {
        envContent += '\nDESKTOP_READONLY=true';
      }

      // 禁用日志（避免只读文件系统错误）
      if (!envContent.includes('LOG_CHANNEL=')) {
        envContent += '\nLOG_CHANNEL=null';
      } else {
        envContent = envContent.replace(/LOG_CHANNEL=.*/, 'LOG_CHANNEL=null');
      }

      await fs.writeFile(envPath, envContent);
      console.log('[FrankenPHP] Updated .env file');
    } catch (error) {
      console.error('[FrankenPHP] Failed to update .env:', error.message);
      throw error;
    }
  }
}

module.exports = PHPServer;
