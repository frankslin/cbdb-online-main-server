#!/bin/bash

###############################################################################
# 测试环境快速安装脚本
#
# 用途：一键配置前端和 E2E 测试环境
# 运行：chmod +x scripts/setup-testing.sh && ./scripts/setup-testing.sh
###############################################################################

set -e  # 遇到错误立即退出

echo "🚀 开始配置测试环境..."
echo ""

# 颜色定义
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# 检查必要工具
echo "📋 检查依赖..."

if ! command -v node &> /dev/null; then
    echo -e "${RED}❌ Node.js 未安装${NC}"
    echo "请安装 Node.js 22.x: https://nodejs.org/"
    exit 1
fi

if ! command -v npm &> /dev/null; then
    echo -e "${RED}❌ npm 未安装${NC}"
    exit 1
fi

if ! command -v php &> /dev/null; then
    echo -e "${RED}❌ PHP 未安装${NC}"
    exit 1
fi

echo -e "${GREEN}✅ 依赖检查通过${NC}"
echo ""

# 安装前端测试依赖
echo "📦 安装前端测试依赖（Vitest + Vue Test Utils）..."
npm install -D vitest@latest \
    @vue/test-utils@latest \
    @vitest/ui@latest \
    jsdom@latest \
    @vitest/coverage-v8@latest

echo -e "${GREEN}✅ 前端测试依赖安装完成${NC}"
echo ""

# 安装 E2E 测试依赖
echo "📦 安装 E2E 测试依赖（Playwright）..."
npm install -D @playwright/test@latest

echo -e "${YELLOW}⏳ 正在下载浏览器（首次需要几分钟）...${NC}"
npx playwright install chromium

# 可选：安装其他浏览器
read -p "是否安装 Firefox 和 Safari（WebKit）？[y/N] " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    npx playwright install firefox webkit
    echo -e "${GREEN}✅ 所有浏览器安装完成${NC}"
else
    echo -e "${YELLOW}⏭️  跳过 Firefox 和 WebKit 安装${NC}"
fi

echo ""

# 验证安装
echo "🧪 验证测试环境..."

# 检查 Vitest
if npm run test -- --version &> /dev/null; then
    echo -e "${GREEN}✅ Vitest 可用${NC}"
else
    echo -e "${RED}❌ Vitest 安装失败${NC}"
    exit 1
fi

# 检查 Playwright
if npx playwright --version &> /dev/null; then
    echo -e "${GREEN}✅ Playwright 可用${NC}"
else
    echo -e "${RED}❌ Playwright 安装失败${NC}"
    exit 1
fi

echo ""

# 运行示例测试
echo "🏃 运行示例测试..."

echo "1️⃣  前端单元测试（Vitest）..."
if npm run test 2>&1 | grep -q "Test Files.*passed"; then
    echo -e "${GREEN}✅ 前端测试通过${NC}"
else
    echo -e "${YELLOW}⚠️  前端测试未通过（可能是因为还没有测试文件）${NC}"
fi

echo ""
echo "2️⃣  后端 Feature 测试（PHPUnit）..."
if ./vendor/bin/phpunit --filter SelectApiTest 2>&1 | grep -q "OK"; then
    echo -e "${GREEN}✅ 后端测试通过${NC}"
else
    echo -e "${YELLOW}⚠️  后端测试未通过（可能需要先运行 Seeder）${NC}"
    echo "提示：运行 php artisan db:seed --class=TestSelectDataSeeder --env=testing"
fi

echo ""

# 生成测试数据
read -p "是否填充测试数据（TestSelectDataSeeder）？[y/N] " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    php artisan db:seed --class=TestSelectDataSeeder --env=testing
    echo -e "${GREEN}✅ 测试数据填充完成${NC}"
fi

echo ""

# 完成
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo -e "${GREEN}🎉 测试环境配置完成！${NC}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "📚 快速命令参考："
echo ""
echo "  前端单元测试（推荐先做）："
echo "    npm run test              # 运行所有测试"
echo "    npm run test:watch        # 监听模式"
echo "    npm run test:ui           # 打开 UI 界面"
echo "    npm run test:coverage     # 查看覆盖率"
echo ""
echo "  后端 Feature 测试："
echo "    ./vendor/bin/phpunit --filter SelectApiTest"
echo ""
echo "  E2E 测试："
echo "    npm run e2e               # 运行所有 E2E 测试"
echo "    npm run e2e:debug         # 调试模式"
echo "    npm run e2e:ui            # 打开 UI 界面"
echo ""
echo "📖 详细文档："
echo "    docs/testing-strategy-summary.md   ⭐ 快速上手"
echo "    TESTING_SELECT_COMPONENTS.md       ⭐ 完整指南"
echo "    docs/testing-files-structure.md    📁 文件导航"
echo ""
echo "🚀 下一步："
echo "    1. 查看示例测试：tests/JavaScript/AsyncSelect.spec.js"
echo "    2. 运行监听模式：npm run test:watch"
echo "    3. 开始编写你的第一个测试！"
echo ""

exit 0
