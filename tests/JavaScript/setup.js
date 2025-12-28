/**
 * Vitest 测试环境设置
 *
 * 在所有测试运行前执行，配置全局环境和 Mock
 */
import { vi } from 'vitest';

// Mock jQuery
global.$ = vi.fn(() => ({
    select2: vi.fn(),
    on: vi.fn(),
    off: vi.fn(),
    find: vi.fn(() => global.$()),
    val: vi.fn(),
    addClass: vi.fn(),
    removeClass: vi.fn(),
    toggleClass: vi.fn(),
}));

global.jQuery = global.$;

// Mock axios（如果需要全局 Mock）
// 注意：通常在每个测试中单独 Mock axios 更灵活
vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
}));

// Mock Select2 插件
global.$.fn = {
    select2: vi.fn(),
};

// 抑制 console 输出（可选，减少测试噪音）
// global.console = {
//     ...console,
//     log: vi.fn(),
//     warn: vi.fn(),
//     error: vi.fn(),
// };

// 配置 jsdom 环境
if (typeof window !== 'undefined') {
    // 设置 window.location（如果测试需要）
    Object.defineProperty(window, 'location', {
        value: {
            href: 'http://localhost:3000',
            origin: 'http://localhost:3000',
            pathname: '/',
        },
        writable: true,
    });

    // Mock localStorage
    Object.defineProperty(window, 'localStorage', {
        value: {
            getItem: vi.fn(),
            setItem: vi.fn(),
            removeItem: vi.fn(),
            clear: vi.fn(),
        },
        writable: true,
    });

    // Mock sessionStorage
    Object.defineProperty(window, 'sessionStorage', {
        value: {
            getItem: vi.fn(),
            setItem: vi.fn(),
            removeItem: vi.fn(),
            clear: vi.fn(),
        },
        writable: true,
    });
}

// 全局测试超时时间
vi.setConfig({ testTimeout: 10000 });
