/**
 * Vitest 配置文件 - 前端组件单元测试
 *
 * 文档：https://vitest.dev/config/
 */
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import path from 'path';

export default defineConfig({
    plugins: [vue()],

    test: {
        // 启用全局 API（describe, it, expect 等）
        globals: true,

        // 使用 jsdom 模拟浏览器环境
        environment: 'jsdom',

        // 测试设置文件
        setupFiles: ['./tests/JavaScript/setup.js'],

        // 覆盖率配置
        coverage: {
            provider: 'v8',
            reporter: ['text', 'json', 'html'],
            include: ['resources/js/components/**/*.vue'],
            exclude: [
                'node_modules/',
                'tests/',
                '**/*.spec.js',
                '**/*.test.js',
            ],
            // 覆盖率阈值
            thresholds: {
                lines: 80,
                functions: 80,
                branches: 75,
                statements: 80,
            }
        },

        // 测试匹配模式
        include: ['tests/JavaScript/**/*.{test,spec}.{js,ts}'],

        // 排除模式
        exclude: [
            'node_modules',
            'dist',
            'vendor',
            '.git',
        ],
    },

    // 路径别名（与 vite.config.js 保持一致）
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
            '~': path.resolve(__dirname, './resources'),
        },
    },
});
