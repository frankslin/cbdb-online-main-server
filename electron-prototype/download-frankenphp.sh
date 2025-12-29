#!/bin/bash

# FrankenPHP 下载脚本 for macOS
# 自动检测 CPU 架构并下载对应版本

set -e

echo "📦 FrankenPHP 下载脚本"
echo "======================"
echo ""

# 检测 CPU 架构
ARCH=$(uname -m)
if [ "$ARCH" = "arm64" ]; then
    FRANKENPHP_ARCH="aarch64"
    ARCH_NAME="Apple Silicon (M1/M2/M3)"
elif [ "$ARCH" = "x86_64" ]; then
    FRANKENPHP_ARCH="x86_64"
    ARCH_NAME="Intel"
else
    echo "❌ 错误：不支持的 CPU 架构：$ARCH"
    exit 1
fi

echo "✓ 检测到 CPU 架构：$ARCH_NAME"

# FrankenPHP 版本和下载地址
FRANKENPHP_VERSION="1.11.1"
# 修正文件名：arm64 而不是 aarch64
if [ "$FRANKENPHP_ARCH" = "aarch64" ]; then
    FRANKENPHP_FILE="frankenphp-mac-arm64"
else
    FRANKENPHP_FILE="frankenphp-mac-x86_64"
fi
FRANKENPHP_URL="https://github.com/php/frankenphp/releases/download/v${FRANKENPHP_VERSION}/${FRANKENPHP_FILE}"
FRANKENPHP_DIR="resources/php"
FRANKENPHP_BIN="$FRANKENPHP_DIR/frankenphp"

# 创建目录
mkdir -p "$FRANKENPHP_DIR"

# 检查是否已下载
if [ -f "$FRANKENPHP_BIN" ]; then
    EXISTING_VERSION=$("$FRANKENPHP_BIN" version 2>/dev/null | head -n1 || echo "unknown")
    echo "✓ FrankenPHP 已存在：$EXISTING_VERSION"

    read -p "是否重新下载最新版本？(y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "✓ 使用现有版本"
        exit 0
    fi

    # 备份现有版本
    mv "$FRANKENPHP_BIN" "$FRANKENPHP_BIN.backup"
fi

# 下载 FrankenPHP
echo ""
echo "📥 下载 FrankenPHP ${FRANKENPHP_VERSION} (${ARCH_NAME})..."
echo "URL: $FRANKENPHP_URL"
echo ""

if command -v curl &> /dev/null; then
    curl -L -o "$FRANKENPHP_BIN" --progress-bar "$FRANKENPHP_URL"
elif command -v wget &> /dev/null; then
    wget -O "$FRANKENPHP_BIN" --show-progress "$FRANKENPHP_URL"
else
    echo "❌ 错误：未找到 curl 或 wget"
    exit 1
fi

# 添加执行权限
chmod +x "$FRANKENPHP_BIN"

# 移除 macOS 隔离属性（避免 Gatekeeper 阻止）
echo "🔓 移除 macOS 隔离属性..."
xattr -d com.apple.quarantine "$FRANKENPHP_BIN" 2>/dev/null || true

# 验证下载
if [ ! -f "$FRANKENPHP_BIN" ]; then
    echo "❌ 错误：下载失败"

    # 恢复备份
    if [ -f "$FRANKENPHP_BIN.backup" ]; then
        mv "$FRANKENPHP_BIN.backup" "$FRANKENPHP_BIN"
    fi

    exit 1
fi

# 显示版本信息
echo ""
echo "✅ 下载成功！"
echo ""
"$FRANKENPHP_BIN" version

# 删除备份
rm -f "$FRANKENPHP_BIN.backup"

# 显示文件信息
FILE_SIZE=$(du -h "$FRANKENPHP_BIN" | cut -f1)
echo ""
echo "📊 文件信息："
echo "  路径：$FRANKENPHP_BIN"
echo "  大小：$FILE_SIZE"
echo "  架构：$ARCH_NAME"

echo ""
echo "✨ 完成！现在可以运行 ./start.sh 启动应用"
