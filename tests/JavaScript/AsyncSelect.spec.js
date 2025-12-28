/**
 * AsyncSelect.vue 组件测试
 *
 * 测试策略：Mock axios 响应，验证组件行为
 */
import { mount, flushPromises } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import AsyncSelect from '@/components/AsyncSelect.vue';
import axios from 'axios';

// Mock axios
vi.mock('axios');

// Mock jQuery 和 Select2
const mockSelect2 = vi.fn();
global.$ = vi.fn(() => ({
    select2: mockSelect2,
}));

describe('AsyncSelect.vue', () => {
    let wrapper;

    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        if (wrapper) {
            wrapper.unmount();
        }
    });

    describe('初始化', () => {
        it('应该渲染基本的 select 元素', () => {
            wrapper = mount(AsyncSelect, {
                props: {
                    name: 'test_field',
                    model: 'text',
                }
            });

            const select = wrapper.find('select');
            expect(select.exists()).toBe(true);
            expect(select.attributes('name')).toBe('test_field');
        });

        it('应该支持多选模式', () => {
            wrapper = mount(AsyncSelect, {
                props: {
                    name: 'test_field',
                    model: 'addr',
                    multiple: true,
                }
            });

            const select = wrapper.find('select');
            expect(select.attributes('multiple')).toBeDefined();
        });
    });

    describe('初始值加载', () => {
        it('应该显示预填充的初始值', () => {
            wrapper = mount(AsyncSelect, {
                props: {
                    name: 'c_source',
                    model: 'text',
                    initialId: 123,
                    initialText: '四庫全書',
                }
            });

            const option = wrapper.find('option');
            expect(option.attributes('value')).toBe('123');
            expect(option.text()).toBe('四庫全書');
        });

        it('应该异步加载初始值（仅提供 ID）', async () => {
            // Mock API 响应
            axios.get.mockResolvedValueOnce({
                data: {
                    data: [{
                        id: 456,
                        text: '資治通鑑'
                    }],
                    total: 1
                }
            });

            wrapper = mount(AsyncSelect, {
                props: {
                    name: 'c_source',
                    model: 'text',
                    initialId: 456,
                }
            });

            await flushPromises();

            // 验证 API 调用
            expect(axios.get).toHaveBeenCalledWith(
                '/api/select/search/text',
                { params: { id: 456 } }
            );

            // 验证初始值已设置
            expect(wrapper.vm.initialValue).toEqual({
                id: 456,
                text: '資治通鑑'
            });
        });
    });

    describe('Select2 初始化', () => {
        it('应该正确配置 Select2 AJAX 参数', async () => {
            wrapper = mount(AsyncSelect, {
                props: {
                    name: 'c_source',
                    model: 'text',
                    placeholder: '請選擇文獻',
                    minimumInputLength: 2,
                }
            });

            await wrapper.vm.$nextTick();

            // 验证 Select2 被调用
            expect(mockSelect2).toHaveBeenCalled();

            const config = mockSelect2.mock.calls[0][0];
            expect(config.ajax.url).toBe('/api/select/search/text');
            expect(config.placeholder).toBe('請選擇文獻');
            expect(config.minimumInputLength).toBe(2);
            expect(config.ajax.delay).toBe(250);
        });

        it('应该正确处理分页参数', async () => {
            wrapper = mount(AsyncSelect, {
                props: {
                    name: 'c_addr',
                    model: 'addr',
                }
            });

            await wrapper.vm.$nextTick();

            const config = mockSelect2.mock.calls[0][0];
            const dataFn = config.ajax.data;

            // 模拟 Select2 的 params
            const result = dataFn({ term: '北京', page: 2 });
            expect(result).toEqual({
                q: '北京',
                page: 2,
            });
        });

        it('应该正确处理搜索结果', async () => {
            wrapper = mount(AsyncSelect, {
                props: {
                    name: 'c_source',
                    model: 'text',
                }
            });

            await wrapper.vm.$nextTick();

            const config = mockSelect2.mock.calls[0][0];
            const processResults = config.ajax.processResults;

            const mockData = {
                data: [
                    { id: 1, text: '史記' },
                    { id: 2, text: '漢書' },
                ],
                total: 150,
            };

            const result = processResults(mockData, { page: 1 });

            expect(result.results).toEqual(mockData.data);
            expect(result.pagination.more).toBe(true); // 30 < 150
        });
    });

    describe('组件销毁', () => {
        it('应该清理 Select2 实例', async () => {
            const mockDestroy = vi.fn();
            global.$ = vi.fn(() => ({
                select2: vi.fn().mockReturnValue({
                    select2: mockDestroy,
                }),
            }));

            wrapper = mount(AsyncSelect, {
                props: {
                    name: 'test',
                    model: 'text',
                }
            });

            await wrapper.unmount();

            expect(mockDestroy).toHaveBeenCalledWith('destroy');
        });
    });
});
