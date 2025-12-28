#!/bin/bash

# CBDB Online Desktop Prototype 启动脚本
# 适用于 macOS

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

# 检查 PHP
if ! command -v php &> /dev/null; then
    echo "❌ 错误：未找到 PHP"
    echo "请先安装 PHP 8.1+ ："
    echo "  brew install php@8.4"
    exit 1
fi

PHP_VERSION=$(php -v | head -n1 | cut -d' ' -f2 | cut -d'.' -f1,2)
echo "✅ 找到 PHP $PHP_VERSION"

# 检查 PHP 版本
PHP_MAJOR=$(echo $PHP_VERSION | cut -d'.' -f1)
PHP_MINOR=$(echo $PHP_VERSION | cut -d'.' -f2)

if [ "$PHP_MAJOR" -lt 8 ] || ([ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -lt 1 ]); then
    echo "❌ 错误：PHP 版本过低（需要 8.1+，当前：$PHP_VERSION）"
    echo "请升级 PHP："
    echo "  brew upgrade php"
    exit 1
fi

# 检查 npm 依赖
if [ ! -d "node_modules" ]; then
    echo "📦 安装 Node.js 依赖..."
    npm install
fi

# 检查 Laravel 环境
if [ ! -f "../.env" ]; then
    echo "⚠️  警告：未找到 .env 文件"
    echo "正在创建..."
    cd ..
    cp .env.example .env
    php artisan key:generate
    cd electron-prototype
fi

# 检查 Composer 依赖
if [ ! -d "../vendor" ]; then
    echo "📦 安装 Composer 依赖..."
    cd ..
    composer install
    cd electron-prototype
fi

# 清除 Laravel 缓存
echo "🧹 清除缓存..."
cd ..
php artisan config:clear > /dev/null 2>&1 || true
php artisan cache:clear > /dev/null 2>&1 || true
cd electron-prototype

# 启动应用
echo ""
echo "✨ 启动应用..."
echo ""

npm start
