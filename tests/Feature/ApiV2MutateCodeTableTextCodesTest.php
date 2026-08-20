<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TEXT_CODES 裸表 create/delete（resource=text-codes）已下架的回歸測試。
 *
 * TEXT_CODES 於 2026-08 收斂為文獻實體聚合（resource=text-entity，見
 * ApiV2MutateTextEntityTest／config/entity_aggregates.php）：config/code_table_writes.php
 * 不再登記 TEXT_CODES，裸表 create/delete 對 text-codes 一律回 501（無 handler 認領），
 * 不落庫、不寫審計。這裡固化「舊通道已封閉」的行為，防止 config 被誤加回。
 */
class ApiV2MutateCodeTableTextCodesTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('app.env', 'testing');
        $this->app['env'] = 'testing';
        config()->set('prometheus.enabled', false);
        config()->set('prometheus.storage_adapter', 'memory');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('confirmation_token')->nullable();
            $table->integer('is_active')->default(0);
            $table->integer('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->nullable();
            $table->integer('c_personid')->default(0);
            $table->integer('op_type');
            $table->string('resource');
            $table->string('resource_id')->nullable();
            $table->longText('resource_data')->nullable();
            $table->longText('resource_original')->nullable();
            $table->integer('crowdsourcing_status')->default(0);
            $table->timestamps();
        });
        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->string('table_name', 64);
            $table->string('operation', 16);
            $table->string('actor_type', 32);
            $table->string('actor_id', 128);
            $table->string('operation_id', 64);
            $table->text('row_pk');
            $table->string('row_pk_text', 512)->nullable();
            $table->longText('old_data')->nullable();
            $table->longText('new_data')->nullable();
        });
        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
            $table->string('c_title_chn')->nullable();
            $table->string('c_title')->nullable();
            $table->integer('c_text_dy')->nullable();
            $table->integer('c_source')->nullable();
            $table->longText('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('TEXT_CODES');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function makeUser(string $email = 'tc@example.com'): User {
        return User::forceCreate([
            'name' => 'Code Tester',
            'email' => $email,
            'confirmation_token' => 'tok',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]);
    }

    #[Test]
    public function testBareCreateIsClosed(): void {
        $this->actingAs($this->makeUser(email: 'tc-create@example.com'));

        foreach (['text-codes', 'text_codes', 'textcodes'] as $alias) {
            $this->postJson('/api/v2/create', [
                'resource' => $alias,
                'person_id' => 0,
                'target' => ['pk' => ['c_textid' => 71853]],
                'changes' => ['c_title_chn' => 'TBDB 1.5', 'c_source' => 0],
            ])->assertStatus(501);
        }

        $this->assertSame(0, DB::table('TEXT_CODES')->count());
        $this->assertSame(0, DB::table('operations')->count());
        $this->assertSame(0, DB::table('audit_log')->count());
    }

    #[Test]
    public function testBareDeleteIsClosed(): void {
        $this->actingAs($this->makeUser(email: 'tc-del@example.com'));
        DB::table('TEXT_CODES')->insert(['c_textid' => 71853, 'c_title_chn' => '待刪']);

        $this->postJson('/api/v2/delete', [
            'resource' => 'text-codes',
            'person_id' => 0,
            'target' => ['pk' => ['c_textid' => 71853]],
        ])->assertStatus(501);

        $this->assertSame(1, DB::table('TEXT_CODES')->count());
        $this->assertSame(0, DB::table('audit_log')->where('operation', 'DELETE')->count());
    }

    #[Test]
    public function testBatchCreateIsClosed(): void {
        $this->actingAs($this->makeUser(email: 'tc-batch@example.com'));

        $this->postJson('/api/v2/batch_mutate', [
            'resource' => 'text-codes',
            'operation' => 'create',
            'items' => [
                ['person_id' => 0, 'target' => ['pk' => ['c_textid' => 800]], 'changes' => ['c_title_chn' => '甲']],
            ],
        ])->assertJson(['summary' => ['total' => 1, 'ok' => 0, 'failed' => 1]]);

        $this->assertSame(0, DB::table('TEXT_CODES')->count());
    }
}
