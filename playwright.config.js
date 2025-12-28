/**
 * Playwright E2E 测试配置
 *
 * 文档：https://playwright.dev/docs/test-configuration
 */
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/E2E',

    // 测试超时时间
    timeout: 30 * 1000,
    expect: {
        timeout: 5000
    },

    // 完全并行运行测试
    fullyParallel: true,

    // 失败重试次数
    retries: process.env.CI ? 2 : 0,

    // 并发工作器数量
    workers: process.env.CI ? 1 : undefined,

    // 报告器配置
    reporter: [
        ['html'],
        ['list'],
        ['json', { outputFile: 'playwright-report/results.json' }]
    ],

    // 共享配置
    use: {
        // 基础 URL（根据环境调整）
        baseURL: process.env.APP_URL || 'http://localhost:8000',

        // 截图配置
        screenshot: 'only-on-failure',

        // 视频录制
        video: 'retain-on-failure',

        // 追踪配置
        trace: 'retain-on-failure',

        // 浏览器上下文选项
        viewport: { width: 1280, height: 720 },
        ignoreHTTPSErrors: true,
    },

    // 测试项目配置
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'firefox',
            use: { ...devices['Desktop Firefox'] },
        },
        {
            name: 'webkit',
            use: { ...devices['Desktop Safari'] },
        },
        // 移动端测试
        {
            name: 'Mobile Chrome',
            use: { ...devices['Pixel 5'] },
        },
        {
            name: 'Mobile Safari',
            use: { ...devices['iPhone 12'] },
        },
    ],

    // Web Server 配置（如果需要自动启动开发服务器）
    webServer: {
        command: 'php artisan serve',
        url: 'http://localhost:8000',
        reuseExistingServer: !process.env.CI,
        timeout: 120 * 1000,
    },
});
