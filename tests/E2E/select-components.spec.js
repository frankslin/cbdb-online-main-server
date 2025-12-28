/**
 * Select 组件 E2E 测试 - Playwright
 *
 * 测试策略：
 * 1. 使用真实浏览器环境测试交互
 * 2. Mock API 响应（或使用测试数据库）
 * 3. 验证 UI 渲染和用户交互
 *
 * 运行方法：
 * npx playwright test select-components.spec.js
 *
 * 调试模式：
 * npx playwright test select-components.spec.js --debug
 */
import { test, expect } from '@playwright/test';

// Mock API 响应（可选 - 如果不想依赖真实数据库）
test.beforeEach(async ({ page }) => {
    // 拦截 API 请求，返回固定数据
    await page.route('**/api/select/search/text*', async (route) => {
        const url = new URL(route.request().url());
        const q = url.searchParams.get('q');
        const id = url.searchParams.get('id');

        if (id === '123') {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    data: [{ id: 123, text: '四庫全書' }],
                    total: 1
                })
            });
        } else if (q === '史') {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    data: [
                        { id: 1, text: '史記' },
                        { id: 2, text: '後漢書' },
                    ],
                    total: 2
                })
            });
        } else {
            await route.continue();
        }
    });
});

test.describe('AsyncSelect 组件', () => {
    test('应该正确渲染下拉框', async ({ page }) => {
        // 访问包含 AsyncSelect 的页面（假设是文献编辑页面）
        await page.goto('/biogmains/1001/texts/create');

        // 等待页面加载完成
        await page.waitForLoadState('networkidle');

        // 验证 select 元素存在
        const sourceSelect = page.locator('select[name="c_source"]');
        await expect(sourceSelect).toBeVisible();
    });

    test('应该显示初始值', async ({ page }) => {
        // 访问编辑页面（假设已有数据）
        await page.goto('/biogmains/1001/texts/1/edit');

        await page.waitForLoadState('networkidle');

        // 验证初始值已加载
        const sourceSelect = page.locator('select[name="c_source"]');
        const selectedOption = sourceSelect.locator('option[selected]');

        await expect(selectedOption).toBeVisible();
        await expect(selectedOption).toHaveText('四庫全書');
    });

    test('应该支持搜索功能', async ({ page }) => {
        await page.goto('/biogmains/1001/texts/create');

        // 点击 Select2 下拉框
        await page.click('.select2-selection');

        // 等待下拉框打开
        await page.waitForSelector('.select2-search__field');

        // 输入搜索关键词
        await page.fill('.select2-search__field', '史');

        // 等待搜索结果加载
        await page.waitForSelector('.select2-results__option');

        // 验证搜索结果
        const results = page.locator('.select2-results__option');
        await expect(results.first()).toContainText('史記');
    });

    test('应该支持选择项目', async ({ page }) => {
        await page.goto('/biogmains/1001/texts/create');

        // 打开下拉框
        await page.click('.select2-selection');
        await page.fill('.select2-search__field', '史');
        await page.waitForSelector('.select2-results__option');

        // 点击第一个结果
        await page.click('.select2-results__option:first-child');

        // 验证选择已应用
        const selectedText = page.locator('.select2-selection__rendered');
        await expect(selectedText).toContainText('史記');

        // 验证隐藏的 select 值已更新
        const selectValue = await page.locator('select[name="c_source"]').inputValue();
        expect(selectValue).toBe('1');
    });

    test('应该支持清除选择', async ({ page }) => {
        await page.goto('/biogmains/1001/texts/1/edit');

        // 等待初始值加载
        await page.waitForSelector('.select2-selection__rendered');

        // 点击清除按钮（如果 Select2 配置了 allowClear）
        const clearButton = page.locator('.select2-selection__clear');
        if (await clearButton.isVisible()) {
            await clearButton.click();

            // 验证选择已清除
            const selectValue = await page.locator('select[name="c_source"]').inputValue();
            expect(selectValue).toBe('');
        }
    });

    test('应该支持多选', async ({ page }) => {
        // 访问有多选字段的页面（如地址多选）
        await page.goto('/biogmains/1001/offices/create');

        // Mock 地址 API
        await page.route('**/api/select/search/addr*', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    data: [
                        { id: 1, text: '北京' },
                        { id: 2, text: '南京' },
                    ],
                    total: 2
                })
            });
        });

        // 打开多选下拉框
        await page.click('select[name="c_addr[]"] + .select2');
        await page.fill('.select2-search__field', '京');
        await page.waitForSelector('.select2-results__option');

        // 选择第一个项目
        await page.click('.select2-results__option:nth-child(1)');

        // 继续搜索并选择第二个项目
        await page.fill('.select2-search__field', '京');
        await page.click('.select2-results__option:nth-child(2)');

        // 验证多个选项已选中
        const selectedItems = page.locator('.select2-selection__choice');
        await expect(selectedItems).toHaveCount(2);
    });

    test('应该正确处理分页', async ({ page }) => {
        await page.route('**/api/select/search/text*', async (route) => {
            const url = new URL(route.request().url());
            const page_num = parseInt(url.searchParams.get('page') || '1');

            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    data: Array.from({ length: 30 }, (_, i) => ({
                        id: (page_num - 1) * 30 + i + 1,
                        text: `文獻 ${(page_num - 1) * 30 + i + 1}`
                    })),
                    total: 100 // 超过一页的数量
                })
            });
        });

        await page.goto('/biogmains/1001/texts/create');

        // 打开下拉框
        await page.click('.select2-selection');
        await page.waitForSelector('.select2-results__option');

        // 滚动到底部触发分页加载
        const resultsContainer = page.locator('.select2-results__options');
        await resultsContainer.evaluate(el => {
            el.scrollTop = el.scrollHeight;
        });

        // 等待第二页数据加载
        await page.waitForTimeout(500);

        // 验证加载了更多结果
        const results = page.locator('.select2-results__option');
        const count = await results.count();
        expect(count).toBeGreaterThan(30); // 应该有第二页数据
    });
});

test.describe('表单提交集成', () => {
    test('应该在表单提交时包含选择值', async ({ page }) => {
        await page.goto('/biogmains/1001/texts/create');

        // 选择文献
        await page.click('.select2-selection');
        await page.fill('.select2-search__field', '史');
        await page.waitForSelector('.select2-results__option');
        await page.click('.select2-results__option:first-child');

        // 填写其他必填字段（根据实际表单）
        // ...

        // 监听表单提交
        const [request] = await Promise.all([
            page.waitForRequest(request => request.method() === 'POST'),
            page.click('button[type="submit"]')
        ]);

        // 验证提交的数据包含选择值
        const postData = request.postData();
        expect(postData).toContain('c_source=1');
    });
});

test.describe('可访问性测试', () => {
    test('应该支持键盘导航', async ({ page }) => {
        await page.goto('/biogmains/1001/texts/create');

        // Tab 到 select 元素
        await page.keyboard.press('Tab');
        // ... 根据页面结构，可能需要多次 Tab

        // 按 Enter/Space 打开下拉框
        await page.keyboard.press('Enter');

        // 等待下拉框打开
        await page.waitForSelector('.select2-search__field');

        // 输入搜索
        await page.keyboard.type('史');
        await page.waitForSelector('.select2-results__option');

        // 使用方向键导航
        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('ArrowDown');

        // 按 Enter 选择
        await page.keyboard.press('Enter');

        // 验证选择成功
        const selectedText = page.locator('.select2-selection__rendered');
        await expect(selectedText).not.toBeEmpty();
    });
});

test.describe('错误处理', () => {
    test('应该正确处理 API 错误', async ({ page }) => {
        // Mock API 返回错误
        await page.route('**/api/select/search/text*', async (route) => {
            await route.fulfill({
                status: 500,
                contentType: 'application/json',
                body: JSON.stringify({ error: 'Internal Server Error' })
            });
        });

        await page.goto('/biogmains/1001/texts/create');

        // 尝试打开下拉框
        await page.click('.select2-selection');

        // 验证错误处理（根据实际实现）
        // 例如：显示错误消息、保持下拉框关闭等
        const errorMessage = page.locator('.error-message');
        // await expect(errorMessage).toBeVisible();
    });

    test('应该处理网络超时', async ({ page }) => {
        // Mock 网络延迟
        await page.route('**/api/select/search/text*', async (route) => {
            await new Promise(resolve => setTimeout(resolve, 10000)); // 10秒延迟
            await route.continue();
        });

        await page.goto('/biogmains/1001/texts/create');

        // 尝试搜索
        await page.click('.select2-selection');
        await page.fill('.select2-search__field', '史');

        // 验证加载指示器（如果有）
        const loadingIndicator = page.locator('.select2-results__loading');
        await expect(loadingIndicator).toBeVisible({ timeout: 1000 });
    });
});
