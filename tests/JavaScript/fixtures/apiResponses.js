/**
 * API Mock 数据 - 模拟真实 API 响应
 *
 * 用于前端测试和 E2E 测试，确保数据结构一致性
 */

export const mockTextData = {
    search: {
        page1: {
            data: [
                { id: 1, text: '史記' },
                { id: 2, text: '漢書' },
                { id: 3, text: '後漢書' },
                { id: 4, text: '三國志' },
                { id: 5, text: '晉書' },
                { id: 6, text: '宋書' },
                { id: 7, text: '南齊書' },
                { id: 8, text: '梁書' },
                { id: 9, text: '陳書' },
                { id: 10, text: '魏書' },
            ],
            total: 156 // 模拟大量数据
        },
        page2: {
            data: [
                { id: 11, text: '北齊書' },
                { id: 12, text: '周書' },
                { id: 13, text: '隋書' },
                { id: 14, text: '南史' },
                { id: 15, text: '北史' },
            ],
            total: 156
        }
    },
    byId: {
        1: { id: 1, text: '史記' },
        123: { id: 123, text: '四庫全書' },
        456: { id: 456, text: '資治通鑑' },
    }
};

export const mockAddrData = {
    search: {
        page1: {
            data: [
                { id: 1, text: '北京' },
                { id: 2, text: '南京' },
                { id: 3, text: '杭州' },
                { id: 4, text: '蘇州' },
                { id: 5, text: '揚州' },
            ],
            total: 1234
        }
    },
    byId: {
        1: { id: 1, text: '北京' },
        2: { id: 2, text: '南京' },
    }
};

export const mockPersonData = {
    search: {
        page1: {
            data: [
                {
                    id: 1001,
                    text: '[1001] 蘇軾 (宋) 字: 子瞻 號: 東坡居士 籍貫: 眉州眉山',
                    c_name_chn: '蘇軾',
                    c_dy: '宋',
                    c_zi: '子瞻',
                    c_hao: '東坡居士',
                    c_choronym: '眉州眉山'
                },
                {
                    id: 1002,
                    text: '[1002] 王安石 (宋) 字: 介甫 號: 半山 籍貫: 撫州臨川',
                    c_name_chn: '王安石',
                    c_dy: '宋',
                    c_zi: '介甫',
                    c_hao: '半山',
                    c_choronym: '撫州臨川'
                },
            ],
            total: 50
        }
    },
    byId: {
        1001: {
            id: 1001,
            c_name_chn: '蘇軾',
            c_dy: '宋',
            c_zi: '子瞻',
            c_hao: '東坡居士',
            c_choronym: '眉州眉山'
        }
    }
};

export const mockOfficeData = {
    search: {
        page1: {
            data: [
                { id: 1, text: '宰相' },
                { id: 2, text: '尚書' },
                { id: 3, text: '侍郎' },
            ],
            total: 500
        }
    }
};

/**
 * 创建统一的 API Mock 响应生成器
 */
export function createMockApiResponse(model, params) {
    const mockDataMap = {
        text: mockTextData,
        addr: mockAddrData,
        person: mockPersonData,
        office: mockOfficeData,
    };

    const modelData = mockDataMap[model];
    if (!modelData) {
        return { data: [], total: 0 };
    }

    // 按 ID 查询
    if (params.id) {
        const item = modelData.byId[params.id];
        return item ? { data: [item], total: 1 } : { data: [], total: 0 };
    }

    // 搜索查询
    const page = params.page || 1;
    const pageKey = `page${page}`;
    return modelData.search[pageKey] || { data: [], total: 0 };
}

/**
 * MSW (Mock Service Worker) 集成
 * 可用于 Storybook、单元测试、E2E 测试
 */
export const handlers = [
    // 示例：如果使用 MSW
    // rest.get('/api/select/search/:model', (req, res, ctx) => {
    //     const { model } = req.params;
    //     const q = req.url.searchParams.get('q');
    //     const page = req.url.searchParams.get('page');
    //     const id = req.url.searchParams.get('id');
    //
    //     return res(
    //         ctx.json(createMockApiResponse(model, { q, page, id }))
    //     );
    // }),
];
