<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 测试数据填充器 - 为 Select API 提供测试数据
 *
 * 用途：
 * 1. Feature 测试（验证 API 响应）
 * 2. E2E 测试（提供可预测的数据集）
 * 3. 本地开发（快速搭建演示环境）
 *
 * 使用方法：
 * php artisan db:seed --class=TestSelectDataSeeder --env=testing
 */
class TestSelectDataSeeder extends Seeder {
    /**
     * Run the database seeds.
     */
    public function run(): void {
        // 只在测试环境运行
        if (!app()->environment('testing', 'local')) {
            $this->command->warn('Skipping TestSelectDataSeeder (not in testing/local environment)');
            return;
        }

        $this->seedTextData();
        $this->seedAddrData();
        $this->seedOfficeData();
        $this->seedPersonData();
        $this->seedCodeTables();

        $this->command->info('✅ Test select data seeded successfully');
    }

    /**
     * 填充文献出处数据（TEXT_DATA）
     */
    private function seedTextData(): void {
        $texts = [
            ['c_text_id' => 1, 'c_text_name_chn' => '史記'],
            ['c_text_id' => 2, 'c_text_name_chn' => '漢書'],
            ['c_text_id' => 3, 'c_text_name_chn' => '後漢書'],
            ['c_text_id' => 4, 'c_text_name_chn' => '三國志'],
            ['c_text_id' => 5, 'c_text_name_chn' => '晉書'],
            ['c_text_id' => 6, 'c_text_name_chn' => '宋書'],
            ['c_text_id' => 7, 'c_text_name_chn' => '南齊書'],
            ['c_text_id' => 8, 'c_text_name_chn' => '梁書'],
            ['c_text_id' => 9, 'c_text_name_chn' => '陳書'],
            ['c_text_id' => 10, 'c_text_name_chn' => '魏書'],
            ['c_text_id' => 123, 'c_text_name_chn' => '四庫全書'],
            ['c_text_id' => 456, 'c_text_name_chn' => '資治通鑑'],
        ];

        DB::table('TEXT_DATA')->insert($texts);
        $this->command->info("  → Seeded " . count($texts) . " text records");
    }

    /**
     * 填充地址数据（ADDRESSES）
     */
    private function seedAddrData(): void {
        $addresses = [
            ['c_addr_id' => 1, 'c_addr_chn' => '北京'],
            ['c_addr_id' => 2, 'c_addr_chn' => '南京'],
            ['c_addr_id' => 3, 'c_addr_chn' => '杭州'],
            ['c_addr_id' => 4, 'c_addr_chn' => '蘇州'],
            ['c_addr_id' => 5, 'c_addr_chn' => '揚州'],
            ['c_addr_id' => 6, 'c_addr_chn' => '廣州'],
            ['c_addr_id' => 7, 'c_addr_chn' => '成都'],
            ['c_addr_id' => 8, 'c_addr_chn' => '西安'],
        ];

        DB::table('ADDRESSES')->insert($addresses);
        $this->command->info("  → Seeded " . count($addresses) . " address records");
    }

    /**
     * 填充官職数据（OFFICES）
     */
    private function seedOfficeData(): void {
        $offices = [
            ['c_office_id' => 1, 'c_office_chn' => '宰相'],
            ['c_office_id' => 2, 'c_office_chn' => '尚書'],
            ['c_office_id' => 3, 'c_office_chn' => '侍郎'],
            ['c_office_id' => 4, 'c_office_chn' => '御史'],
            ['c_office_id' => 5, 'c_office_chn' => '知府'],
        ];

        DB::table('OFFICES')->insert($offices);
        $this->command->info("  → Seeded " . count($offices) . " office records");
    }

    /**
     * 填充人物数据（BIOG_MAIN）
     */
    private function seedPersonData(): void {
        $persons = [
            [
                'c_personid' => 1001,
                'c_name_chn' => '蘇軾',
                'c_dy' => '25',  // 宋
                'c_zi' => '子瞻',
                'c_hao' => '東坡居士',
                'c_choronym_code' => 'MEISHAN',
            ],
            [
                'c_personid' => 1002,
                'c_name_chn' => '王安石',
                'c_dy' => '25',  // 宋
                'c_zi' => '介甫',
                'c_hao' => '半山',
                'c_choronym_code' => 'LINCHUAN',
            ],
            [
                'c_personid' => 1003,
                'c_name_chn' => '歐陽修',
                'c_dy' => '25',  // 宋
                'c_zi' => '永叔',
                'c_hao' => '醉翁',
                'c_choronym_code' => 'JIZHOU',
            ],
        ];

        DB::table('BIOG_MAIN')->insert($persons);
        $this->command->info("  → Seeded " . count($persons) . " person records");
    }

    /**
     * 填充代码表数据
     */
    private function seedCodeTables(): void {
        // 朝代代碼
        DB::table('DYNASTIES')->insert([
            ['c_dy' => '1', 'c_dy_chn' => '夏'],
            ['c_dy' => '2', 'c_dy_chn' => '商'],
            ['c_dy' => '25', 'c_dy_chn' => '宋'],
            ['c_dy' => '32', 'c_dy_chn' => '明'],
            ['c_dy' => '33', 'c_dy_chn' => '清'],
        ]);

        // 親屬關係代碼
        DB::table('KIN_CODES')->insert([
            ['c_kin_code' => 1, 'c_kin_desc' => '父親'],
            ['c_kin_code' => 2, 'c_kin_desc' => '母親'],
            ['c_kin_code' => 3, 'c_kin_desc' => '兄弟'],
            ['c_kin_code' => 4, 'c_kin_desc' => '姊妹'],
        ]);

        // 社會關係代碼
        DB::table('ASSOC_CODES')->insert([
            ['c_assoc_code' => 1, 'c_assoc_name' => '師友'],
            ['c_assoc_code' => 2, 'c_assoc_name' => '同年'],
            ['c_assoc_code' => 3, 'c_assoc_name' => '門生'],
        ]);

        $this->command->info("  → Seeded code table records");
    }

    /**
     * 批量生成测试数据（用于压力测试）
     *
     * @param int $count 数量
     */
    public function seedLargeDataset(int $count = 1000): void {
        $this->command->info("Generating {$count} test records...");

        $texts = [];
        for ($i = 1; $i <= $count; $i++) {
            $texts[] = [
                'c_text_id' => 10000 + $i,
                'c_text_name_chn' => "測試文獻 {$i}",
            ];

            // 批量插入（每 100 条）
            if ($i % 100 === 0) {
                DB::table('TEXT_DATA')->insert($texts);
                $texts = [];
            }
        }

        if (!empty($texts)) {
            DB::table('TEXT_DATA')->insert($texts);
        }

        $this->command->info("  → Generated {$count} test text records");
    }
}
