const { spawn } = require('child_process');
const portfinder = require('portfinder');
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

    console.log(`[PHP Server] Starting on port ${this.port}...`);
    console.log(`[PHP Server] Laravel path: ${this.laravelPath}`);
    console.log(`[PHP Server] Database path: ${this.databasePath}`);

    // 2. 更新 .env 文件
    await this.updateEnvFile();

    // 3. 启动 PHP 内置服务器
    return new Promise((resolve, reject) => {
      const args = [
        'artisan',
        'serve',
        `--host=127.0.0.1`,
        `--port=${this.port}`,
      ];

      this.process = spawn(this.phpBinary, args, {
        cwd: this.laravelPath,
        env: {
          ...process.env,
          APP_ENV: 'desktop',
          APP_MODE: 'desktop',
          DESKTOP_READONLY: 'true',
        },
      });

      let hasStarted = false;

      // 监听输出
      this.process.stdout.on('data', (data) => {
        const output = data.toString();
        console.log(`[PHP] ${output}`);

        // 检测服务器启动成功
        if (!hasStarted && (output.includes('started') || output.includes('Development Server'))) {
          hasStarted = true;
          console.log(`[PHP Server] Started successfully at http://127.0.0.1:${this.port}`);
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
        console.log(`[PHP Server] Exited with code ${code}`);
      });

      // 5秒超时
      setTimeout(() => {
        if (!hasStarted) {
          reject(new Error('PHP server start timeout (5s)'));
        }
      }, 5000);
    });
  }

  async stop() {
    if (this.process) {
      console.log('[PHP Server] Stopping...');
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

      await fs.writeFile(envPath, envContent);
      console.log('[PHP Server] Updated .env file');
    } catch (error) {
      console.error('[PHP Server] Failed to update .env:', error.message);
      throw error;
    }
  }
}

module.exports = PHPServer;
