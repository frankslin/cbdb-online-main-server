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
                            {--export= : 將結果匯出到 CSV 檔案}';

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
     * Execute the console command.
     */
    public function handle(): int {
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
}
