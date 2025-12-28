<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\TestSelectDataSeeder;

/**
 * Select API 功能测试
 *
 * 测试策略：
 * 1. 使用 In-Memory SQLite 数据库（快速、隔离）
 * 2. 使用 TestSelectDataSeeder 提供固定测试数据
 * 3. 验证 API 响应结构和分页逻辑
 *
 * 运行方法：
 * ./vendor/bin/phpunit --filter SelectApiTest
 */
class SelectApiTest extends TestCase {
    use RefreshDatabase;

    protected function setUp(): void {
        parent::setUp();

        // 填充测试数据
        $this->seed(TestSelectDataSeeder::class);
    }

    /**
     * 测试文献搜索 API - 基本功能
     */
    public function test_text_search_returns_correct_structure(): void {
        $response = $this->getJson('/api/select/search/text?q=史&page=1');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'text']
                ],
                'total'
            ]);
    }

    /**
     * 测试搜索结果内容
     */
    public function test_text_search_filters_by_keyword(): void {
        $response = $this->getJson('/api/select/search/text?q=史記');

        $response->assertStatus(200)
            ->assertJsonFragment([
                'text' => '史記'
            ]);

        $data = $response->json('data');
        $this->assertGreaterThan(0, count($data), '应该返回至少一条匹配记录');
    }

    /**
     * 测试分页功能
     */
    public function test_search_returns_paginated_results(): void {
        $response = $this->getJson('/api/select/search/text?page=1');

        $response->assertStatus(200);

        $total = $response->json('total');
        $dataCount = count($response->json('data'));

        $this->assertLessThanOrEqual(30, $dataCount, '每页最多返回 30 条记录');
        $this->assertGreaterThanOrEqual($dataCount, $total, '总数应大于等于当前页数据量');
    }

    /**
     * 测试按 ID 查询（用于初始值加载）
     */
    public function test_search_by_id_returns_single_result(): void {
        $response = $this->getJson('/api/select/search/text?id=123');

        $response->assertStatus(200)
            ->assertJsonFragment([
                'id' => 123,
                'text' => '四庫全書'
            ]);

        $data = $response->json('data');
        $this->assertCount(1, $data, '按 ID 查询应只返回一条记录');
    }

    /**
     * 测试地址搜索 API
     */
    public function test_addr_search_works(): void {
        $response = $this->getJson('/api/select/search/addr?q=京');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertNotEmpty($data, '应该找到包含"京"的地址');

        // 验证结果包含"北京"或"南京"
        $texts = array_column($data, 'text');
        $this->assertTrue(
            in_array('北京', $texts) || in_array('南京', $texts),
            '搜索结果应包含匹配的地址'
        );
    }

    /**
     * 测试官职搜索 API
     */
    public function test_office_search_works(): void {
        $response = $this->getJson('/api/select/search/office?q=尚書');

        $response->assertStatus(200)
            ->assertJsonFragment([
                'text' => '尚書'
            ]);
    }

    /**
     * 测试空搜索关键词
     */
    public function test_empty_query_returns_all_results(): void {
        $response = $this->getJson('/api/select/search/text?q=&page=1');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertGreaterThan(0, count($data), '空搜索应返回所有记录（分页）');
    }

    /**
     * 测试不存在的模型
     */
    public function test_invalid_model_returns_error(): void {
        $response = $this->getJson('/api/select/search/invalid_model?q=test');

        // 根据实际 API 实现，可能是 404 或 400
        $response->assertStatus(404);
    }

    /**
     * 测试节流限制（Throttle）
     */
    public function test_api_has_rate_limiting(): void {
        // 发送 121 次请求（超过 throttle:120,1 的限制）
        for ($i = 0; $i < 121; $i++) {
            $response = $this->getJson('/api/select/search/text?q=test');

            if ($i < 120) {
                $response->assertStatus(200);
            } else {
                // 第 121 次应该被限流
                $response->assertStatus(429); // Too Many Requests
                break;
            }
        }
    }

    /**
     * 测试人物搜索 API（特殊格式化）
     */
    public function test_person_search_returns_formatted_data(): void {
        $response = $this->getJson('/api/name?q=蘇軾');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertNotEmpty($data, '应该找到人物');

        // 验证人物数据结构
        $person = $data[0];
        $this->assertArrayHasKey('id', $person);
        $this->assertArrayHasKey('text', $person);
        $this->assertArrayHasKey('c_name_chn', $person);
        $this->assertArrayHasKey('c_dy', $person);
    }

    /**
     * 性能测试 - 确保搜索在合理时间内完成
     */
    public function test_search_performance_is_acceptable(): void {
        $startTime = microtime(true);

        $response = $this->getJson('/api/select/search/text?q=史');

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000; // 转换为毫秒

        $response->assertStatus(200);

        // 确保查询在 500ms 内完成（In-Memory SQLite 应该很快）
        $this->assertLessThan(500, $duration, "API 响应时间应小于 500ms，实际：{$duration}ms");
    }
}
