# 檢測簡體中文姓名工具

## 功能說明

`cbdb:detect-simplified-chinese-names` 命令用於檢測 `BIOG_MAIN` 表中可能誤用簡體中文的姓名。

## 檢測邏輯

該工具會檢查每個姓名中的字符，判斷是否使用了簡體字。判斷標準為：

- ✅ **會被檢測**：出現了某個漢字，它有且僅有唯一的繁體字對應，但使用了簡體
- ❌ **不會被檢測**：繁簡一致的字（如「李」、「王」等本身就是繁簡通用的字）

## 使用方法

### 基本用法

檢查所有記錄：

```bash
php artisan cbdb:detect-simplified-chinese-names
```

### 選項說明

#### 1. `--limit`：限制檢查的記錄數量

用於測試或部分檢查：

```bash
php artisan cbdb:detect-simplified-chinese-names --limit=100
```

#### 2. `--personid`：檢查特定的人物 ID

檢查指定的人物記錄：

```bash
php artisan cbdb:detect-simplified-chinese-names --personid=12345
```

#### 3. `--export`：匯出結果到 CSV 檔案

將檢測結果匯出到 CSV 檔案以便進一步分析：

```bash
php artisan cbdb:detect-simplified-chinese-names --export=/path/to/output.csv
```

匯出的 CSV 檔案包含以下欄位：
- Person ID：人物 ID
- 姓名：包含簡體字的姓名
- 簡體字：檢測到的簡體字列表
- 建議繁體字：對應的繁體字列表
- 所有簡體字位置：詳細的簡體→繁體映射

### 組合使用

可以組合多個選項：

```bash
# 檢查前 1000 條記錄並匯出結果
php artisan cbdb:detect-simplified-chinese-names --limit=1000 --export=results.csv
```

## 輸出示例

### 終端輸出

當發現簡體字誤用時，命令會以表格形式顯示：

```
發現 3 條記錄可能存在簡體字誤用：

┌───────────┬────────┬──────────┬────────────┐
│ Person ID │ 姓名   │ 簡體字   │ 建議繁體字 │
├───────────┼────────┼──────────┼────────────┤
│ 12345     │ 张三   │ 张       │ 張         │
│ 23456     │ 刘德华 │ 刘, 华   │ 劉, 華     │
│ 34567     │ 陈冠希 │ 陈       │ 陳         │
└───────────┴────────┴──────────┴────────────┘

檢查完成！共檢查 10000 條記錄，發現 3 條記錄可能存在簡體字誤用
```

當未發現問題時：

```
✓ 未發現任何簡體字誤用！

檢查完成！共檢查 10000 條記錄，發現 0 條記錄可能存在簡體字誤用
```

## 前置條件

在使用此命令之前，請確保：

1. **繁簡映射表已匯入**：

   ```bash
   php artisan cbdb:import-trad-simp-map --truncate
   ```

   如果映射表為空，命令會返回錯誤並提示您執行上述命令。

2. **資料庫連線正常**：確保 `.env` 中的資料庫設定正確。

## 技術細節

- **資料來源**：使用 `CBDB__TRAD_SIMP_MAP` 表作為繁簡映射參考
- **處理方式**：分批處理記錄（每批 1000 條），避免記憶體溢出
- **字符處理**：使用 `mb_str_split` 正確處理多字節 UTF-8 字符
- **CSV 編碼**：匯出的 CSV 包含 BOM，確保 Excel 正確識別 UTF-8

## 常見問題

### Q: 為什麼有些字沒有被檢測出來？

A: 如果某個字是繁簡通用的（即在 `CBDB__TRAD_SIMP_MAP` 中 `trad_char = simp_char`），則不會被視為簡體字誤用。

### Q: 檢測結果可以自動修正嗎？

A: 目前此工具僅提供檢測功能，不會自動修改資料。建議將結果匯出為 CSV，人工審核後再進行批量修正。

### Q: 命令執行很慢怎麼辦？

A: 可以使用 `--limit` 選項分批檢查，或者針對特定的 `--personid` 進行檢查。

## 相關命令

- `php artisan cbdb:import-trad-simp-map`：匯入繁簡映射表
- `php artisan cbdb:rebuild-name-search`：重建姓名搜尋索引

## 貢獻與反饋

如發現檢測邏輯有誤或有改進建議，請提交 Issue 或 Pull Request。
