#!/bin/bash

# CBDB Online Desktop Prototype 启动脚本
# 使用 FrankenPHP（无需安装 PHP）

set -e

echo "🚀 CBDB Online Desktop Prototype"
echo "=================================="
echo ""

# 检查 Node.js
if ! command -v node &> /dev/null; then
    echo "❌ 错误：未找到 Node.js"
    echo "请先安装 Node.js 18.x 或更高版本："
    echo "  brew install node"
    exit 1
fi

NODE_VERSION=$(node -v | cut -d'v' -f2 | cut -d'.' -f1)
if [ "$NODE_VERSION" -lt 18 ]; then
    echo "⚠️  警告：Node.js 版本过低（当前：$(node -v)）"
    echo "推荐升级到 18.x 或更高版本"
fi

echo "✅ Node.js $(node -v)"

# 检查 FrankenPHP
FRANKENPHP_BIN="resources/php/frankenphp"

if [ ! -f "$FRANKENPHP_BIN" ]; then
    echo ""
    echo "📦 FrankenPHP 未找到"
    echo ""
    echo "FrankenPHP 是一个包含 PHP 的独立二进制文件，"
    echo "无需单独安装 PHP 即可运行应用。"
    echo ""
    read -p "是否现在下载 FrankenPHP？(Y/n) " -n 1 -r
    echo

    if [[ ! $REPLY =~ ^[Nn]$ ]]; then
        # 运行下载脚本
        ./download-frankenphp.sh
    else
        echo "❌ 取消启动"
        echo "提示：稍后可以运行 ./download-frankenphp.sh 下载"
        exit 1
    fi
else
    # 显示 FrankenPHP 版本
    FRANKENPHP_VERSION=$("$FRANKENPHP_BIN" version 2>/dev/null | head -n1 || echo "unknown")
    echo "✅ FrankenPHP $FRANKENPHP_VERSION"
fi

# 检查 npm 依赖
if [ ! -d "node_modules" ]; then
    echo ""
    echo "📦 安装 Node.js 依赖..."
    npm install
fi

# 检查 Laravel 环境
if [ ! -f "../.env" ]; then
    echo ""
    echo "⚠️  警告：未找到 .env 文件"
    echo "正在创建..."
    cd ..
    cp .env.example .env

    # 使用 FrankenPHP 生成应用密钥
    "$FRANKENPHP_BIN" artisan key:generate

    # 配置桌面模式
    echo "" >> .env
    echo "# Electron Desktop Mode" >> .env
    echo "APP_MODE=desktop" >> .env
    echo "DESKTOP_READONLY=true" >> .env
    echo "LOG_CHANNEL=null" >> .env

    cd electron-prototype
else
    # 如果 .env 已存在，确保包含桌面模式配置
    cd ..
    if ! grep -q "APP_MODE=" .env; then
        echo "" >> .env
        echo "# Electron Desktop Mode" >> .env
        echo "APP_MODE=desktop" >> .env
        echo "DESKTOP_READONLY=true" >> .env
        echo "LOG_CHANNEL=null" >> .env
        echo "✓ 已添加桌面模式配置到 .env"
    fi
    cd electron-prototype
fi

# 检查 Composer 依赖
if [ ! -d "../vendor" ]; then
    echo ""
    echo "📦 安装 Composer 依赖..."
    cd ..

    # 使用 FrankenPHP 内置的 composer
    "$FRANKENPHP_BIN" composer install

    cd electron-prototype
fi

# 清除 Laravel 缓存
echo ""
echo "🧹 清除缓存..."
cd ..
"$FRANKENPHP_BIN" artisan config:clear > /dev/null 2>&1 || true
"$FRANKENPHP_BIN" artisan cache:clear > /dev/null 2>&1 || true
cd electron-prototype

# 启动应用
echo ""
echo "✨ 启动应用..."
echo ""

npm start
