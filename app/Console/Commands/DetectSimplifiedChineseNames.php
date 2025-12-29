<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DetectSimplifiedChineseNames extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cbdb:detect-simplified-chinese-names
                            {--limit= : 限制檢查的記錄數量}
                            {--personid= : 檢查特定的 person ID}
                            {--export= : 將結果匯出到 CSV 檔案}
                            {--show-exceptions : 顯示姓名專用例外字清單}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '檢測 BIOG_MAIN 表中可能誤用簡體中文的姓名';

    /**
     * 簡體字到繁體字的映射表（只包含有差異的字）
     *
     * @var array
     */
    private array $simpToTradMap = [];

    /**
     * 姓名專用例外字：這些字在姓名中應使用簡化形式，而非分化字
     *
     * 這些字符在古代並不存在分化形式，分化字僅用於近代區分特定義項：
     * - 胡（姓氏）vs 鬍（胡須）
     * - 沈（姓氏）vs 瀋（沉澱義）
     * - 朱（姓氏）vs 硃（朱砂）
     * - 周（姓氏）vs 週（周期/星期）
     * - 家（家族）vs 傢（家具）
     * - 面（面容）vs 麵（麵條）
     * - 里（姓氏/裡面）vs 裡（方位）
     * - 后（姓氏）vs 後（時間/空間）
     * - 余（姓氏）vs 餘（剩餘）
     * - 于（姓氏）vs 於（介詞）
     * - 钟（姓氏）vs 鍾（鐘錶）vs 鍾（鍾愛）
     * - 范（姓氏）vs 範（範圍）
     * - 丑（地支/姓氏）vs 醜（醜陋）
     * - 干（天干/姓氏）vs 乾（乾燥）vs 幹（幹部）
     * - 谷（姓氏）vs 穀（穀物）
     * - 党（姓氏）vs 黨（政黨）
     * - 郁（姓氏）vs 鬱（鬱悶）
     * - 叶（姓氏）vs 葉（葉子）
     * - 万（姓氏）vs 萬（數字）
     *
     * @var array
     */
    private array $nameExceptions = [
        '胡', '沈', '朱', '周', '家', '面', '里', '后', '余', '于',
        '钟', '范', '丑', '干', '谷', '党', '郁', '叶', '万',
        // 可根據實際情況繼續補充
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int {
        // 如果要求顯示例外字清單
        if ($this->option('show-exceptions')) {
            $this->showExceptions();

            return 0;
        }

        $this->info('開始載入繁簡映射表...');

        // 從資料庫載入簡體字映射表（只取繁簡不同的字）
        $this->loadSimpToTradMap();

        if (empty($this->simpToTradMap)) {
            $this->error('繁簡映射表為空，請先執行 php artisan cbdb:import-trad-simp-map');

            return 1;
        }

        $this->info(sprintf('已載入 %d 個簡體字映射', count($this->simpToTradMap)));

        // 建立查詢
        $query = DB::table('BIOG_MAIN')
            ->whereNotNull('c_name_chn')
            ->where('c_name_chn', '!=', '');

        // 如果指定了 personid
        if ($personId = $this->option('personid')) {
            $query->where('c_personid', $personId);
        }

        // 如果指定了 limit
        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $totalRecords = $query->count();
        $this->info(sprintf('準備檢查 %d 條記錄...', $totalRecords));

        // 使用進度條
        $progressBar = $this->output->createProgressBar($totalRecords);
        $progressBar->start();

        $issues = [];
        $checkedCount = 0;
        $issueCount = 0;

        // 分批處理記錄
        $query->orderBy('c_personid')->chunk(1000, function ($records) use (&$issues, &$checkedCount, &$issueCount, $progressBar) {
            foreach ($records as $record) {
                $checkedCount++;
                $progressBar->advance();

                $name = $record->c_name_chn;
                if (empty($name)) {
                    continue;
                }

                // 檢查姓名中的每個字
                $simplifiedChars = $this->findSimplifiedChars($name);

                if (!empty($simplifiedChars)) {
                    $issueCount++;
                    $issues[] = [
                        'personid' => $record->c_personid,
                        'name' => $name,
                        'simplified_chars' => $simplifiedChars,
                    ];
                }
            }
        });

        $progressBar->finish();
        $this->newLine(2);

        // 顯示結果
        $this->displayResults($issues);

        // 如果指定了匯出選項
        if ($exportPath = $this->option('export')) {
            $this->exportToCSV($issues, $exportPath);
        }

        // 統計信息
        $this->newLine();
        $this->info(sprintf('檢查完成！共檢查 %d 條記錄，發現 %d 條記錄可能存在簡體字誤用', $checkedCount, $issueCount));

        return 0;
    }

    /**
     * 從資料庫載入簡體字到繁體字的映射表
     */
    private function loadSimpToTradMap(): void {
        $mappings = DB::table('CBDB__TRAD_SIMP_MAP')
            ->whereRaw('trad_char != simp_char') // 只取繁簡不同的字
            ->get(['trad_char', 'simp_char']);

        foreach ($mappings as $mapping) {
            $this->simpToTradMap[$mapping->simp_char] = $mapping->trad_char;
        }
    }

    /**
     * 在字串中尋找簡體字
     *
     * @param string $text
     * @return array 返回找到的簡體字及其對應的繁體字
     */
    private function findSimplifiedChars(string $text): array {
        $found = [];

        // 使用 mb_str_split 來正確處理多字節字符
        $chars = mb_str_split($text, 1, 'UTF-8');

        foreach ($chars as $char) {
            // 跳過姓名專用例外字（這些字在姓名中使用簡化形式是正確的）
            if (in_array($char, $this->nameExceptions, true)) {
                continue;
            }

            if (isset($this->simpToTradMap[$char])) {
                $found[] = [
                    'simp' => $char,
                    'trad' => $this->simpToTradMap[$char],
                ];
            }
        }

        return $found;
    }

    /**
     * 顯示檢測結果
     *
     * @param array $issues
     */
    private function displayResults(array $issues): void {
        if (empty($issues)) {
            $this->info('✓ 未發現任何簡體字誤用！');

            return;
        }

        $this->warn(sprintf('發現 %d 條記錄可能存在簡體字誤用：', count($issues)));
        $this->newLine();

        // 準備表格數據
        $tableData = [];
        foreach ($issues as $issue) {
            $simpChars = [];
            $tradChars = [];

            foreach ($issue['simplified_chars'] as $charInfo) {
                $simpChars[] = $charInfo['simp'];
                $tradChars[] = $charInfo['trad'];
            }

            $tableData[] = [
                $issue['personid'],
                $issue['name'],
                implode(', ', $simpChars),
                implode(', ', $tradChars),
            ];
        }

        $this->table(
            ['Person ID', '姓名', '簡體字', '建議繁體字'],
            $tableData
        );
    }

    /**
     * 將結果匯出到 CSV 檔案
     *
     * @param array $issues
     * @param string $path
     */
    private function exportToCSV(array $issues, string $path): void {
        if (empty($issues)) {
            $this->info('沒有需要匯出的資料。');

            return;
        }

        $fp = fopen($path, 'w');
        if ($fp === false) {
            $this->error("無法寫入檔案：{$path}");

            return;
        }

        // 寫入 BOM 以確保 Excel 正確識別 UTF-8
        fwrite($fp, "\xEF\xBB\xBF");

        // 寫入標題列
        fputcsv($fp, ['Person ID', '姓名', '簡體字', '建議繁體字', '所有簡體字位置']);

        // 寫入資料
        foreach ($issues as $issue) {
            $simpChars = [];
            $tradChars = [];
            $details = [];

            foreach ($issue['simplified_chars'] as $charInfo) {
                $simpChars[] = $charInfo['simp'];
                $tradChars[] = $charInfo['trad'];
                $details[] = sprintf('%s→%s', $charInfo['simp'], $charInfo['trad']);
            }

            fputcsv($fp, [
                $issue['personid'],
                $issue['name'],
                implode(', ', $simpChars),
                implode(', ', $tradChars),
                implode('; ', $details),
            ]);
        }

        fclose($fp);

        $this->info("結果已匯出到：{$path}");
    }

    /**
     * 顯示姓名專用例外字清單
     */
    private function showExceptions(): void {
        $this->info('姓名專用例外字清單');
        $this->info('這些字在姓名中使用簡化形式是正確的，不會被標記為錯誤：');
        $this->newLine();

        $this->line('常見姓氏用字：');
        $this->line('  胡（非鬍）、沈（非瀋）、朱（非硃）、周（非週）');
        $this->line('  余（非餘）、于（非於）、钟（非鍾）、范（非範）');
        $this->line('  丑（非醜）、干（非乾/幹）、谷（非穀）、党（非黨）');
        $this->line('  郁（非鬱）、叶（非葉）、万（非萬）');
        $this->newLine();

        $this->line('常見名字用字：');
        $this->line('  家（家族，非傢具）、面（面容，非麵條）');
        $this->line('  里（裡面，非專用方位詞裡）、后（姓氏，非後面）');
        $this->newLine();

        $this->info('完整例外字清單：');
        $this->line('  ' . implode('、', $this->nameExceptions));
        $this->newLine();

        $this->comment('這些字在古代並不存在分化形式，分化字僅用於近代區分特定義項。');
        $this->comment('如需添加更多例外字，請編輯 DetectSimplifiedChineseNames.php 的 $nameExceptions 屬性。');
    }
}
