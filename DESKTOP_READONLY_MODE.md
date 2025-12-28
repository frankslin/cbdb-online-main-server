# 桌面只读模式设计方案

## 📋 需求分析

### 在线版 vs 桌面版对比

| 功能 | 在线版 | 桌面版（只读） |
|------|--------|---------------|
| 用户登录 | ✅ 必需 | ❌ 无需登录 |
| 数据查看 | ✅ 登录后 | ✅ 默认开放 |
| 数据编辑 | ✅ 根据权限 | ❌ 完全禁用 |
| 操作记录 | ✅ 记录所有操作 | ❌ 无编辑操作 |
| API Token | ✅ 支持 | ❌ 不需要 |
| 用户管理 | ✅ 管理员功能 | ❌ 隐藏 |
| 自然语言查询 | ✅ 需要 API Key | ⚠️ 可选（用户自行配置）|

## 🎯 设计目标

1. **零复杂度切换**：通过单个环境变量控制模式
2. **最小化代码修改**：不破坏现有逻辑
3. **优雅降级**：桌面版功能是在线版的子集
4. **易于维护**：集中管理模式差异

## 🏗️ 实施方案

### 核心思路：环境驱动 + 中间件 + Blade 指令

```
环境变量 (APP_MODE)
    ↓
中间件自动处理权限
    ↓
Blade 指令控制UI显示
    ↓
统一的代码库，不同的运行模式
```

## 📝 实施步骤

### 步骤 1：配置环境变量

#### config/app.php

```php
<?php

return [
    // ... 现有配置

    /*
    |--------------------------------------------------------------------------
    | Application Mode
    |--------------------------------------------------------------------------
    |
    | 应用运行模式：
    | - 'online': 在线多用户模式（默认）
    | - 'desktop': 桌面单机只读模式
    |
    */
    'mode' => env('APP_MODE', 'online'),

    /*
    |--------------------------------------------------------------------------
    | Desktop Mode Settings
    |--------------------------------------------------------------------------
    |
    | 桌面模式相关配置
    |
    */
    'desktop' => [
        // 是否启用只读模式
        'readonly' => env('DESKTOP_READONLY', true),

        // 是否隐藏登录相关功能
        'hide_auth' => env('DESKTOP_HIDE_AUTH', true),

        // 是否允许使用 Gemini API（用户可自行配置）
        'allow_gemini' => env('DESKTOP_ALLOW_GEMINI', true),
    ],
];
```

#### .env.desktop.example

```env
# 桌面版配置模板
APP_NAME="CBDB Online Desktop"
APP_ENV=production
APP_DEBUG=false
APP_MODE=desktop

# 桌面模式配置
DESKTOP_READONLY=true
DESKTOP_HIDE_AUTH=true
DESKTOP_ALLOW_GEMINI=true

# SQLite 配置
DB_CONNECTION=sqlite
DB_DATABASE=/path/to/database.sqlite3

# 可选：用户自行配置 Gemini API
GEMINI_API_KEY=
GEMINI_API_ENDPOINT=https://generativelanguage.googleapis.com/v1beta/openai/chat/completions
GEMINI_MODEL=gemini-2.0-flash-exp
```

### 步骤 2：创建辅助函数

#### app/helpers.php

```php
<?php

if (!function_exists('is_desktop_mode')) {
    /**
     * 检查是否为桌面模式
     *
     * @return bool
     */
    function is_desktop_mode(): bool {
        return config('app.mode') === 'desktop';
    }
}

if (!function_exists('is_online_mode')) {
    /**
     * 检查是否为在线模式
     *
     * @return bool
     */
    function is_online_mode(): bool {
        return config('app.mode') === 'online';
    }
}

if (!function_exists('is_readonly_mode')) {
    /**
     * 检查是否为只读模式
     *
     * @return bool
     */
    function is_readonly_mode(): bool {
        return is_desktop_mode() && config('app.desktop.readonly', true);
    }
}

if (!function_calls('desktop_guest_user')) {
    /**
     * 获取桌面模式的虚拟访客用户
     *
     * @return \App\Models\User|null
     */
    function desktop_guest_user(): ?\App\Models\User {
        if (!is_desktop_mode()) {
            return null;
        }

        // 返回一个虚拟的只读用户对象（不持久化到数据库）
        static $guestUser = null;

        if ($guestUser === null) {
            $guestUser = new \App\Models\User([
                'id' => 0,
                'name' => 'Desktop Guest',
                'email' => 'guest@desktop.local',
                'is_active' => 1,
                'is_admin' => 0,
                'is_expert' => 0,
            ]);
            $guestUser->exists = false; // 标记为非持久化对象
        }

        return $guestUser;
    }
}
```

### 步骤 3：创建桌面模式中间件

#### app/Http/Middleware/DesktopModeMiddleware.php

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DesktopModeMiddleware {
    /**
     * 处理桌面模式的访问控制
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next) {
        // 只在桌面模式下生效
        if (!is_desktop_mode()) {
            return $next($request);
        }

        // 1. 自动"登录"虚拟访客用户（用于通过 auth 中间件检查）
        if (!Auth::check()) {
            Auth::setUser(desktop_guest_user());
        }

        // 2. 阻止所有写操作（POST/PUT/PATCH/DELETE）
        if (is_readonly_mode() && $this->isWriteRequest($request)) {
            return $this->handleReadOnlyViolation($request);
        }

        return $next($request);
    }

    /**
     * 检查是否为写操作请求
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function isWriteRequest(Request $request): bool {
        // 排除特定的安全路由（如 CSRF token 获取）
        $allowedRoutes = [
            'sanctum/csrf-cookie',
        ];

        if (in_array($request->path(), $allowedRoutes)) {
            return false;
        }

        return in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE']);
    }

    /**
     * 处理只读模式违规
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    protected function handleReadOnlyViolation(Request $request) {
        // AJAX 请求返回 JSON
        if ($request->expectsJson()) {
            return response()->json([
                'message' => '桌面版为只读模式，不支持编辑操作。',
                'error' => 'DESKTOP_READONLY_MODE',
            ], 403);
        }

        // 普通请求返回错误页面
        abort(403, '桌面版为只读模式，不支持编辑操作。');
    }
}
```

#### 注册中间件（app/Http/Kernel.php）

```php
protected $middlewareGroups = [
    'web' => [
        // ... 现有中间件
        \App\Http\Middleware\DesktopModeMiddleware::class,
    ],
];
```

### 步骤 4：创建 Blade 指令

#### app/Providers/AppServiceProvider.php

```php
<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider {
    public function boot() {
        // 桌面模式条件指令
        Blade::if('desktop', function () {
            return is_desktop_mode();
        });

        Blade::if('online', function () {
            return is_online_mode();
        });

        Blade::if('readonly', function () {
            return is_readonly_mode();
        });

        Blade::if('writable', function () {
            return !is_readonly_mode();
        });

        // 组合指令：桌面模式下隐藏
        Blade::if('hideOnDesktop', function () {
            return !is_desktop_mode();
        });

        // 组合指令：只在在线版显示
        Blade::if('onlineOnly', function () {
            return is_online_mode();
        });
    }
}
```

### 步骤 5：修改现有 Blade 模板

#### 示例：隐藏编辑按钮

**修改前**：
```blade
<!-- resources/views/basicinformation/show.blade.php -->
<a href="{{ route('basicinformation.edit', $person->c_personid) }}"
   class="btn btn-primary">
    编辑
</a>
```

**修改后**：
```blade
@writable
<a href="{{ route('basicinformation.edit', $person->c_personid) }}"
   class="btn btn-primary">
    编辑
</a>
@endwritable
```

#### 示例：隐藏用户相关功能

**修改前**：
```blade
<!-- resources/views/layouts/dashboard-v3.blade.php -->
<li class="nav-item">
    <a href="{{ route('profile.edit') }}" class="nav-link">
        <i class="nav-icon fas fa-user"></i>
        <p>个人资料</p>
    </a>
</li>
```

**修改后**：
```blade
@onlineOnly
<li class="nav-item">
    <a href="{{ route('profile.edit') }}" class="nav-link">
        <i class="nav-icon fas fa-user"></i>
        <p>个人资料</p>
    </a>
</li>
@endonlineOnly
```

#### 示例：条件显示欢迎信息

```blade
@desktop
<div class="alert alert-info">
    <i class="fas fa-desktop"></i>
    欢迎使用 CBDB Online 桌面版！您正在浏览只读模式。
</div>
@enddesktop

@online
<div class="alert alert-success">
    欢迎，{{ auth()->user()->name }}！
</div>
@endonline
```

### 步骤 6：修改路由配置

#### routes/web.php

使用路由组和条件注册：

```php
<?php

use Illuminate\Support\Facades\Route;

// 公共只读路由（桌面版和在线版都可访问）
Route::group([], function () {
    // 查看人物信息
    Route::get('/basicinformation/{personid}', [BasicInformationController::class, 'show'])
        ->name('basicinformation.show');

    // 查看代码表
    Route::get('/codes/{table}', [CodesController::class, 'index'])
        ->name('codes.index');

    // 查看 View Table
    Route::get('/view/{key}', [ViewTableController::class, 'show'])
        ->name('view.show');

    // 搜索功能
    Route::get('/search', [SearchController::class, 'index'])
        ->name('search.index');

    // 自然语言查询（如果启用）
    Route::get('/query-playground', [QueryPlaygroundController::class, 'index'])
        ->name('query-playground.index');
});

// 仅在线版可用的路由（需要登录和编辑权限）
if (is_online_mode()) {
    Route::middleware(['auth'])->group(function () {
        // 编辑功能
        Route::resource('basicinformation', BasicInformationController::class)
            ->except(['index', 'show']);

        // 操作记录
        Route::get('/operations', [OperationsController::class, 'index'])
            ->name('operations.index');

        // 用户管理
        Route::middleware(['admin'])->group(function () {
            Route::resource('users', UserController::class);
        });

        // API Token 管理
        Route::post('/user/api-tokens', [ApiTokenController::class, 'store'])
            ->name('api-tokens.store');
    });

    // 认证路由
    Auth::routes();
}
```

**或者使用更优雅的方式**（推荐）：

```php
// 所有路由正常注册，由中间件处理权限
// 这样避免修改现有路由结构

// 在 app/Http/Kernel.php 中添加路由中间件别名
protected $routeMiddleware = [
    // ... 现有中间件
    'online_only' => \App\Http\Middleware\OnlineOnlyMiddleware::class,
];
```

#### app/Http/Middleware/OnlineOnlyMiddleware.php

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class OnlineOnlyMiddleware {
    public function handle(Request $request, Closure $next) {
        if (is_desktop_mode()) {
            abort(404, '此功能在桌面版中不可用');
        }

        return $next($request);
    }
}
```

然后在路由中使用：

```php
// 仅在线版功能
Route::middleware(['auth', 'online_only'])->group(function () {
    Route::resource('basicinformation', BasicInformationController::class)
        ->except(['index', 'show']);
});
```

### 步骤 7：修改认证逻辑

#### 修改登录相关视图

```blade
<!-- resources/views/layouts/dashboard-v3.blade.php -->
<nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <ul class="navbar-nav ml-auto">
        @desktop
        <!-- 桌面版显示模式标识 -->
        <li class="nav-item">
            <span class="nav-link">
                <i class="fas fa-desktop"></i> 桌面版（只读）
            </span>
        </li>
        @enddesktop

        @online
        <!-- 在线版显示用户菜单 -->
        @auth
        <li class="nav-item dropdown">
            <a class="nav-link" data-toggle="dropdown" href="#">
                <i class="far fa-user"></i>
                {{ auth()->user()->name }}
            </a>
            <div class="dropdown-menu dropdown-menu-right">
                <a href="{{ route('profile.edit') }}" class="dropdown-item">
                    个人资料
                </a>
                <div class="dropdown-divider"></div>
                <a href="{{ route('logout') }}" class="dropdown-item"
                   onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                    退出
                </a>
            </div>
        </li>
        @else
        <li class="nav-item">
            <a href="{{ route('login') }}" class="nav-link">登录</a>
        </li>
        @endauth
        @endonline
    </ul>
</nav>
```

### 步骤 8：处理侧边栏菜单

#### resources/views/layouts/dashboard-v3.blade.php

```blade
<aside class="main-sidebar sidebar-dark-primary elevation-4">
    <!-- 侧边栏菜单 -->
    <nav class="mt-2">
        <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview">
            <!-- 首页（所有模式） -->
            <li class="nav-item">
                <a href="{{ route('home') }}" class="nav-link">
                    <i class="nav-icon fas fa-home"></i>
                    <p>首页</p>
                </a>
            </li>

            <!-- 搜索（所有模式） -->
            <li class="nav-item">
                <a href="{{ route('search.index') }}" class="nav-link">
                    <i class="nav-icon fas fa-search"></i>
                    <p>人物搜索</p>
                </a>
            </li>

            <!-- 代码表（所有模式） -->
            <li class="nav-item">
                <a href="{{ route('codes.index', 'BIOG_MAIN') }}" class="nav-link">
                    <i class="nav-icon fas fa-table"></i>
                    <p>代码表</p>
                </a>
            </li>

            <!-- View Tables（所有模式） -->
            <li class="nav-item">
                <a href="{{ route('view.index') }}" class="nav-link">
                    <i class="nav-icon fas fa-chart-bar"></i>
                    <p>统计视图</p>
                </a>
            </li>

            @desktop
            <!-- 桌面版特有：帮助 -->
            <li class="nav-item">
                <a href="{{ route('desktop.help') }}" class="nav-link">
                    <i class="nav-icon fas fa-question-circle"></i>
                    <p>使用帮助</p>
                </a>
            </li>
            @enddesktop

            @onlineOnly
            <!-- 仅在线版：编辑功能 -->
            <li class="nav-item">
                <a href="{{ route('basicinformation.create') }}" class="nav-link">
                    <i class="nav-icon fas fa-plus"></i>
                    <p>新增人物</p>
                </a>
            </li>

            <!-- 仅在线版：操作记录 -->
            <li class="nav-item">
                <a href="{{ route('operations.index') }}" class="nav-link">
                    <i class="nav-icon fas fa-history"></i>
                    <p>操作记录</p>
                </a>
            </li>

            @admin
            <!-- 管理员功能 -->
            <li class="nav-item has-treeview">
                <a href="#" class="nav-link">
                    <i class="nav-icon fas fa-cogs"></i>
                    <p>
                        系统管理
                        <i class="right fas fa-angle-left"></i>
                    </p>
                </a>
                <ul class="nav nav-treeview">
                    <li class="nav-item">
                        <a href="{{ route('users.index') }}" class="nav-link">
                            <i class="far fa-circle nav-icon"></i>
                            <p>用户管理</p>
                        </a>
                    </li>
                </ul>
            </li>
            @endadmin
            @endonlineOnly
        </ul>
    </nav>
</aside>
```

## 🎨 UI/UX 改进建议

### 桌面版专属欢迎页

创建一个桌面版专属的欢迎页面，解释功能和限制：

#### resources/views/desktop/welcome.blade.php

```blade
@extends('layouts.dashboard-v3')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary">
                    <h3 class="card-title">
                        <i class="fas fa-desktop"></i>
                        欢迎使用 CBDB Online 桌面版
                    </h3>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <h5><i class="icon fas fa-info"></i> 关于桌面版</h5>
                        <p>
                            CBDB Online 桌面版是一个<strong>离线可用</strong>的只读版本，
                            包含完整的 CBDB 数据库内容，无需网络连接即可查询和浏览。
                        </p>
                    </div>

                    <h4>功能说明</h4>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="callout callout-success">
                                <h5><i class="fas fa-check-circle"></i> 可用功能</h5>
                                <ul>
                                    <li>人物信息查询</li>
                                    <li>全文搜索</li>
                                    <li>代码表浏览</li>
                                    <li>统计视图查看</li>
                                    <li>数据导出（CSV/JSON）</li>
                                    @if(config('app.desktop.allow_gemini'))
                                    <li>自然语言查询（需配置 API）</li>
                                    @endif
                                </ul>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="callout callout-warning">
                                <h5><i class="fas fa-ban"></i> 不可用功能</h5>
                                <ul>
                                    <li>用户登录/注册</li>
                                    <li>数据编辑/新增/删除</li>
                                    <li>操作记录</li>
                                    <li>多用户协作</li>
                                    <li>数据同步</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <h4 class="mt-4">快速开始</h4>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="small-box bg-info">
                                <div class="inner">
                                    <h3><i class="fas fa-search"></i></h3>
                                    <p>人物搜索</p>
                                </div>
                                <a href="{{ route('search.index') }}" class="small-box-footer">
                                    开始搜索 <i class="fas fa-arrow-circle-right"></i>
                                </a>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="small-box bg-success">
                                <div class="inner">
                                    <h3><i class="fas fa-table"></i></h3>
                                    <p>代码表</p>
                                </div>
                                <a href="{{ route('codes.index', 'BIOG_MAIN') }}" class="small-box-footer">
                                    浏览代码表 <i class="fas fa-arrow-circle-right"></i>
                                </a>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="small-box bg-warning">
                                <div class="inner">
                                    <h3><i class="fas fa-chart-bar"></i></h3>
                                    <p>统计视图</p>
                                </div>
                                <a href="{{ route('view.index') }}" class="small-box-footer">
                                    查看统计 <i class="fas fa-arrow-circle-right"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
```

## 📊 需要修改的文件清单

### 新增文件（6 个）

1. ✅ `app/Http/Middleware/DesktopModeMiddleware.php` - 桌面模式中间件
2. ✅ `app/Http/Middleware/OnlineOnlyMiddleware.php` - 仅在线版中间件
3. ✅ `.env.desktop.example` - 桌面版环境配置模板
4. ✅ `resources/views/desktop/welcome.blade.php` - 桌面版欢迎页
5. ✅ `resources/views/desktop/help.blade.php` - 桌面版帮助页
6. ✅ `tests/Feature/DesktopModeTest.php` - 桌面模式测试

### 修改文件（预估 15-20 个）

#### 核心配置文件（必须修改）

1. ✅ `config/app.php` - 添加 app.mode 和 app.desktop 配置
2. ✅ `app/helpers.php` - 添加辅助函数
3. ✅ `app/Providers/AppServiceProvider.php` - 注册 Blade 指令
4. ✅ `app/Http/Kernel.php` - 注册中间件

#### 布局和通用视图（必须修改）

5. ✅ `resources/views/layouts/dashboard-v3.blade.php` - 主布局
6. ✅ `resources/views/home.blade.php` - 首页（条件显示内容）

#### 具体功能视图（按需修改）

7. ⚠️ `resources/views/basicinformation/show.blade.php` - 隐藏编辑按钮
8. ⚠️ `resources/views/codes/index.blade.php` - 隐藏编辑功能
9. ⚠️ `resources/views/view/show.blade.php` - 可能需要隐藏某些操作
10. ⚠️ `resources/views/search/index.blade.php` - 可能需要调整
11. ⚠️ 其他包含编辑功能的视图

#### 路由文件（可选修改）

12. ⚠️ `routes/web.php` - 可选：使用条件注册路由

## 🧪 测试策略

### 自动化测试

#### tests/Feature/DesktopModeTest.php

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DesktopModeTest extends TestCase {
    use RefreshDatabase;

    protected function setUp(): void {
        parent::setUp();

        // 切换到桌面模式
        config(['app.mode' => 'desktop']);
        config(['app.desktop.readonly' => true]);
    }

    /** @test */
    public function it_allows_guest_access_to_read_routes() {
        $response = $this->get(route('basicinformation.show', 1));

        $response->assertOk();
    }

    /** @test */
    public function it_blocks_write_operations() {
        $response = $this->post(route('basicinformation.store'), [
            'c_name_chn' => 'Test',
        ]);

        $response->assertStatus(403);
        $response->assertSee('只读模式');
    }

    /** @test */
    public function it_auto_authenticates_guest_user() {
        $this->get(route('home'));

        $this->assertTrue(auth()->check());
        $this->assertEquals('Desktop Guest', auth()->user()->name);
    }

    /** @test */
    public function helper_functions_work_correctly() {
        $this->assertTrue(is_desktop_mode());
        $this->assertFalse(is_online_mode());
        $this->assertTrue(is_readonly_mode());
    }
}
```

### 手动测试清单

| 测试场景 | 在线版预期 | 桌面版预期 | 状态 |
|---------|----------|-----------|------|
| 访问首页 | 需要登录 | 直接访问 | ⬜ |
| 查看人物 | 登录后可见 | 直接可见 | ⬜ |
| 编辑人物 | 显示编辑按钮 | 隐藏编辑按钮 | ⬜ |
| POST 请求 | 根据权限处理 | 返回 403 | ⬜ |
| 用户菜单 | 显示 | 隐藏 | ⬜ |
| 侧边栏 | 完整菜单 | 精简菜单 | ⬜ |

## 📈 实施优先级

### 第一阶段：核心功能（2-3 小时）

1. ✅ 创建辅助函数和配置
2. ✅ 创建 DesktopModeMiddleware
3. ✅ 注册 Blade 指令
4. ✅ 修改主布局文件

### 第二阶段：UI 调整（3-4 小时）

5. ✅ 修改侧边栏菜单
6. ✅ 隐藏编辑按钮（各个视图）
7. ✅ 创建桌面版欢迎页
8. ✅ 调整首页内容

### 第三阶段：测试和优化（2-3 小时）

9. ✅ 编写自动化测试
10. ✅ 手动测试所有页面
11. ✅ 修复发现的问题
12. ✅ 文档完善

**总计：7-10 小时**

## 🎯 维护建议

### 开发新功能时的检查清单

1. **新增路由**：
   - ✅ 思考是否需要在桌面版禁用
   - ✅ 添加 `online_only` 中间件（如果需要）

2. **新增视图**：
   - ✅ 使用 `@writable` 包裹编辑按钮
   - ✅ 使用 `@onlineOnly` 包裹在线版专属功能

3. **新增 API**：
   - ✅ 考虑桌面版是否需要此 API
   - ✅ 添加模式检查

### 代码审查要点

- ❓ 是否使用了辅助函数而不是硬编码条件？
- ❓ 编辑功能是否用 `@writable` 包裹？
- ❓ 新路由是否考虑了桌面模式？

## 💡 高级优化（可选）

### 1. 动态菜单配置

创建一个配置文件定义菜单项的可见性：

#### config/navigation.php

```php
<?php

return [
    'sidebar' => [
        [
            'name' => '首页',
            'route' => 'home',
            'icon' => 'fas fa-home',
            'modes' => ['online', 'desktop'], // 两种模式都显示
        ],
        [
            'name' => '新增人物',
            'route' => 'basicinformation.create',
            'icon' => 'fas fa-plus',
            'modes' => ['online'], // 仅在线版
        ],
        // ... 更多菜单项
    ],
];
```

然后在 Blade 中循环渲染：

```blade
@foreach(config('navigation.sidebar') as $item)
    @if(in_array(config('app.mode'), $item['modes']))
    <li class="nav-item">
        <a href="{{ route($item['route']) }}" class="nav-link">
            <i class="nav-icon {{ $item['icon'] }}"></i>
            <p>{{ $item['name'] }}</p>
        </a>
    </li>
    @endif
@endforeach
```

### 2. 使用 Gate 统一权限

定义自定义 Gate：

#### app/Providers/AuthServiceProvider.php

```php
public function boot() {
    Gate::define('edit-content', function ($user = null) {
        return is_online_mode() && $user && $user->is_active;
    });

    Gate::define('view-operations', function ($user = null) {
        return is_online_mode();
    });
}
```

在 Blade 中使用：

```blade
@can('edit-content')
<a href="{{ route('basicinformation.edit', $person->c_personid) }}"
   class="btn btn-primary">
    编辑
</a>
@endcan
```

## 📝 总结

### 优势

1. ✅ **零学习曲线**：开发者只需理解几个辅助函数和 Blade 指令
2. ✅ **最小化修改**：现有代码基本不变，只需添加条件包裹
3. ✅ **集中管理**：模式差异集中在中间件和配置中
4. ✅ **易于测试**：清晰的环境变量控制，易于编写测试
5. ✅ **向后兼容**：不影响现有在线版功能

### 复杂度评估

- **代码复杂度**：⭐⭐ (很低)
  - 主要是条件判断，逻辑清晰

- **维护复杂度**：⭐⭐ (很低)
  - 新功能只需添加 `@writable` 包裹

- **测试复杂度**：⭐⭐ (很低)
  - 切换环境变量即可测试

### 风险评估

- ⚠️ **遗漏编辑按钮**：可能某些页面忘记添加 `@writable`
  - 缓解：编写检查脚本 + Code Review

- ⚠️ **客户端绕过限制**：用户可能通过浏览器开发工具提交请求
  - 缓解：中间件在服务端阻止所有写操作

- ⚠️ **模式混淆**：开发者可能不清楚当前测试的模式
  - 缓解：在 UI 显著位置标识当前模式

---

**文档版本**：1.0
**创建日期**：2025-12-28
**维护者**：CBDB 开发团队
