<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CharVariantMapService;
use App\Services\Import\TextImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 「文獻實體」create／update／delete mutation（resource=text-entity）回歸測試。
 *
 * 驗證 TextAggregateDefinition / TextImportService 的聚合語義：實體識別＝c_textid 單鍵、
 * 書名標準化＋拼音派生、TEXT_INSTANCE_DATA 版本列集合對賬（collection→instance 層級）、
 * c_source 自引用成環護欄（著錄來源樹層級）、刪除護欄（入邊引用計數，含子文獻）、
 * 稽核欄由系統蓋章（c_created_* 只在 create 蓋、c_modified_* 每次寫入蓋）。
 */
class ApiV2MutateTextEntityTest extends TestCase {
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
            $table->string('c_title_trans')->nullable();
            $table->string('c_text_type_id')->nullable();
            $table->integer('c_text_year')->nullable();
            $table->integer('c_text_nh_code')->nullable();
            $table->integer('c_text_nh_year')->nullable();
            $table->integer('c_text_range_code')->nullable();
            $table->integer('c_bibl_cat_code')->nullable();
            $table->integer('c_extant')->nullable();
            $table->integer('c_text_country')->nullable();
            $table->integer('c_text_dy')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->string('c_url_api')->nullable();
            $table->string('c_url_api_coda')->nullable();
            $table->string('c_url_homepage')->nullable();
            $table->text('c_notes')->nullable();
            $table->string('c_title_alt_chn')->nullable();
            $table->string('c_created_by')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });
        Schema::create('TEXT_INSTANCE_DATA', function (Blueprint $table) {
            $table->integer('c_textid');
            $table->integer('c_text_edition_id');
            $table->integer('c_text_instance_id');
            $table->string('c_instance_title_chn')->nullable();
            $table->string('c_instance_title')->nullable();
            $table->string('c_publisher')->nullable();
            $table->string('c_pub_loc')->nullable();
            $table->integer('c_pub_year')->nullable();
            $table->integer('c_pub_dy')->nullable();
            $table->integer('c_pub_nh_code')->nullable();
            $table->integer('c_pub_nh_year')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->integer('c_extant')->nullable();
            $table->text('c_notes')->nullable();
            $table->primary(['c_textid', 'c_text_edition_id', 'c_text_instance_id']);
        });
        Schema::create('DYNASTIES', function (Blueprint $table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty_chn')->nullable();
        });
        Schema::create('NIAN_HAO', function (Blueprint $table) {
            $table->integer('c_nianhao_id')->primary();
        });
        Schema::create('YEAR_RANGE_CODES', function (Blueprint $table) {
            $table->integer('c_range_code')->primary();
        });
        Schema::create('TEXT_BIBLCAT_CODES', function (Blueprint $table) {
            $table->integer('c_text_cat_code')->primary();
        });
        Schema::create('EXTANT_CODES', function (Blueprint $table) {
            $table->integer('c_extant_code')->primary();
        });
        Schema::create('COUNTRY_CODES', function (Blueprint $table) {
            $table->integer('c_country_code')->primary();
        });
        Schema::create('TEXT_TYPE', function (Blueprint $table) {
            $table->string('c_text_type_code')->primary();
        });
        Schema::create('pinyin', function (Blueprint $table) {
            $table->increments('id');
            $table->string('c_chn')->nullable();
            $table->string('c_pinyin')->nullable();
            $table->integer('c_lastname')->default(0);
        });
        Schema::create('char_variant_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('c_variant_char', 10);
            $table->string('c_reference_char', 10);
            $table->tinyInteger('c_strict_excluded')->default(1);
            $table->string('c_notes', 255)->nullable();
            $table->timestamps();
        });
        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1],
        ]);
        CharVariantMapService::reset();

        // 刪除護欄：referenceCount() 逐一計數的入邊引用表（皮表即可）。
        foreach (TextImportService::REFERENCE_COLUMNS as [$refTable, $refColumn]) {
            if (Schema::hasTable($refTable)) {
                continue;
            }
            Schema::create($refTable, function (Blueprint $table) use ($refColumn) {
                $table->increments('id');
                $table->integer($refColumn)->nullable();
                if ($refColumn !== 'c_textid') {
                    $table->integer('c_textid')->nullable();
                }
            });
        }

        DB::table('DYNASTIES')->insert([
            ['c_dy' => 15, 'c_dynasty_chn' => '宋'],
            ['c_dy' => 19, 'c_dynasty_chn' => '明'],
        ]);
        DB::table('NIAN_HAO')->insert([['c_nianhao_id' => 540]]);
        DB::table('YEAR_RANGE_CODES')->insert([['c_range_code' => 1]]);
        DB::table('TEXT_BIBLCAT_CODES')->insert([['c_text_cat_code' => 3]]);
        DB::table('EXTANT_CODES')->insert([['c_extant_code' => 1]]);
        DB::table('COUNTRY_CODES')->insert([['c_country_code' => 1]]);
        DB::table('TEXT_TYPE')->insert([['c_text_type_code' => '01']]);

        // 既有文獻：7596（來源根）→ 10（受測文獻，來源=7596），10 有一列版本。
        DB::table('TEXT_CODES')->insert(['c_textid' => 7596, 'c_title_chn' => '來源總目', 'c_title' => 'laiyuan zongmu']);
        DB::table('TEXT_CODES')->insert([
            'c_textid' => 10, 'c_title_chn' => '測試文集', 'c_title' => 'ceshi wenji', 'c_source' => 7596, 'c_text_dy' => 15,
            'c_created_by' => 'Original Creator', 'c_created_date' => '2020-01-01 00:00:00',
        ]);
        DB::table('TEXT_INSTANCE_DATA')->insert([
            'c_textid' => 10, 'c_text_edition_id' => 1, 'c_text_instance_id' => 1,
            'c_instance_title_chn' => '初刻本', 'c_publisher' => '某書坊',
        ]);
    }

    protected function tearDown(): void {
        $tables = array_values(array_unique(array_map(fn ($r) => $r[0], TextImportService::REFERENCE_COLUMNS)));
        foreach (array_merge($tables, [
            'char_variant_map', 'pinyin', 'TEXT_TYPE', 'COUNTRY_CODES', 'EXTANT_CODES',
            'TEXT_BIBLCAT_CODES', 'YEAR_RANGE_CODES', 'NIAN_HAO', 'DYNASTIES',
            'TEXT_INSTANCE_DATA', 'TEXT_CODES', 'audit_log', 'operations', 'users',
        ]) as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    protected function makeUser(string $email = 'te@example.com'): User {
        return User::forceCreate([
            'name' => 'Text Tester',
            'email' => $email,
            'confirmation_token' => 'tok',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]);
    }

    protected function updatePayload(array $changes = [], int $textId = 10): array {
        return [
            'resource' => 'text-entity',
            'operation' => 'update',
            'person_id' => 0,
            'target' => ['pk' => ['c_textid' => $textId]],
            'changes' => array_merge([
                'title' => '測試文集',
                'title_pinyin' => 'ceshi wenji',
                'dynasty_code' => 15,
                'source_id' => 7596,
                'instances' => [['edition_id' => 1, 'instance_id' => 1, 'title_chn' => '初刻本', 'publisher' => '某書坊']],
            ], $changes),
        ];
    }

    // ── create ──────────────────────────────

    #[Test]
    public function testCreateAssignsIdDerivesAndStampsAudit(): void {
        $this->actingAs($this->makeUser(email: 'te-create@example.com'));

        $res = $this->postJson('/api/v2/create', [
            'resource' => 'text-entity',
            'person_id' => 0,
            'target' => ['pk' => []],
            'changes' => [
                'title' => ' 玉峯詩集 ：十卷',
                'type_id' => '01',
                'dynasty_code' => 15,
                'source_id' => 7596,
            ],
        ]);

        $res->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'text-entity',
            'operation' => 'create',
            'result' => ['pk' => ['c_textid' => 7597], 'status' => 'created', 'instances_added' => 0],
        ]);
        $row = DB::table('TEXT_CODES')->where('c_textid', 7597)->first();
        // 書名：去空白、字形標準化（峯→峰）、冒號後統一「: 」。
        $this->assertSame('玉峰詩集: 十卷', $row->c_title_chn);
        // 拼音自動派生（去卷冊註記後逐字轉），至少非空且不含中文。
        $this->assertNotSame('', (string) $row->c_title);
        $this->assertDoesNotMatchRegularExpression('/\p{Han}/u', (string) $row->c_title);
        $this->assertSame(15, (int) $row->c_text_dy);
        $this->assertSame(7596, (int) $row->c_source);
        // 稽核欄由系統蓋章。
        $this->assertSame('Text Tester', $row->c_created_by);
        $this->assertNotNull($row->c_created_date);
        // operations resource_id 沿用純數字慣例；audit_log INSERT 一筆。
        $op = DB::table('operations')->where('resource', 'TEXT_CODES')->orderByDesc('id')->first();
        $this->assertSame('7597', $op->resource_id);
        $this->assertSame(1, DB::table('audit_log')->where('table_name', 'TEXT_CODES')->where('operation', 'INSERT')->count());
    }

    #[Test]
    public function testCreateWithInstancesAndAliasBook(): void {
        $this->actingAs($this->makeUser(email: 'te-create-inst@example.com'));

        $res = $this->postJson('/api/v2/create', [
            'resource' => 'book',
            'person_id' => 0,
            'target' => ['pk' => []],
            'changes' => [
                'title' => '叢書總集',
                'instances' => [
                    ['edition_id' => 1, 'instance_id' => 1, 'title_chn' => '甲本', 'pub_dy' => 19],
                    ['edition_id' => 1, 'instance_id' => 2, 'title_chn' => '乙本', 'pub_nh_code' => 540],
                ],
            ],
        ]);

        $res->assertOk()->assertJson(['resource' => 'text-entity', 'result' => ['instances_added' => 2]]);
        $textId = $res->json('result.pk.c_textid');
        $this->assertSame(2, DB::table('TEXT_INSTANCE_DATA')->where('c_textid', $textId)->count());
        $this->assertDatabaseHas('TEXT_INSTANCE_DATA', ['c_textid' => $textId, 'c_text_edition_id' => 1, 'c_text_instance_id' => 2, 'c_instance_title_chn' => '乙本']);
    }

    #[Test]
    public function testCreateValidation(): void {
        $this->actingAs($this->makeUser(email: 'te-create-422@example.com'));

        $base = fn (array $changes) => [
            'resource' => 'text-entity', 'person_id' => 0, 'target' => ['pk' => []], 'changes' => $changes,
        ];

        // 缺書名
        $this->postJson('/api/v2/create', $base(['dynasty_code' => 15]))->assertStatus(422);
        // 朝代不存在
        $this->postJson('/api/v2/create', $base(['title' => 'x', 'dynasty_code' => 999]))->assertStatus(422);
        // 來源不存在
        $this->postJson('/api/v2/create', $base(['title' => 'x', 'source_id' => 424242]))->assertStatus(422);
        // 文獻類型碼不存在
        $this->postJson('/api/v2/create', $base(['title' => 'x', 'type_id' => '99zz']))->assertStatus(422);
        // 版本列鍵重複
        $this->postJson('/api/v2/create', $base(['title' => 'x', 'instances' => [
            ['edition_id' => 1, 'instance_id' => 1],
            ['edition_id' => 1, 'instance_id' => 1],
        ]]))->assertStatus(422);
        // 版本列缺鍵
        $this->postJson('/api/v2/create', $base(['title' => 'x', 'instances' => [['title_chn' => '本']]]))->assertStatus(422);

        $this->assertSame(2, DB::table('TEXT_CODES')->count());
    }

    // ── update ──────────────────────────────

    #[Test]
    public function testUpdateOverwritesColumnsPreservesCreatedStamps(): void {
        $this->actingAs($this->makeUser(email: 'te-upd@example.com'));

        $res = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'year' => 1234,
            'nh_code' => 540,
            'bibl_cat_code' => 3,
            'extant' => 1,
            'notes' => '重編',
        ]));

        $res->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'text-entity',
            'operation' => 'update',
            'result' => ['pk' => ['c_textid' => 10], 'status' => 'updated'],
        ]);
        $row = DB::table('TEXT_CODES')->where('c_textid', 10)->first();
        $this->assertSame(1234, (int) $row->c_text_year);
        $this->assertSame(540, (int) $row->c_text_nh_code);
        $this->assertSame('重編', $row->c_notes);
        // c_created_* 沿用、c_modified_* 蓋當下（AGENTS §1.2）。
        $this->assertSame('Original Creator', $row->c_created_by);
        $this->assertSame('Text Tester', $row->c_modified_by);
        $this->assertNotNull($row->c_modified_date);
    }

    #[Test]
    public function testUpdateReconcilesInstanceRows(): void {
        $this->actingAs($this->makeUser(email: 'te-upd-inst@example.com'));

        // (1,1) 同鍵改值（換出版者）、新增 (2,1)、無其他列 → added 1 / updated 1 / removed 0。
        $res = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'instances' => [
                ['edition_id' => 1, 'instance_id' => 1, 'title_chn' => '初刻本', 'publisher' => '另一書坊'],
                ['edition_id' => 2, 'instance_id' => 1, 'title_chn' => '重刻本'],
            ],
        ]));

        $res->assertOk()->assertJson(['result' => ['instances_added' => 1, 'instances_removed' => 0, 'instances_updated' => 1]]);
        $this->assertSame(2, DB::table('TEXT_INSTANCE_DATA')->where('c_textid', 10)->count());
        $this->assertDatabaseHas('TEXT_INSTANCE_DATA', ['c_textid' => 10, 'c_text_edition_id' => 1, 'c_text_instance_id' => 1, 'c_publisher' => '另一書坊']);
        $this->assertDatabaseHas('TEXT_INSTANCE_DATA', ['c_textid' => 10, 'c_text_edition_id' => 2, 'c_text_instance_id' => 1, 'c_instance_title_chn' => '重刻本']);

        // 清空版本列 → 全部移除。
        $this->postJson('/api/v2/mutate', $this->updatePayload(['instances' => []]))
            ->assertOk()
            ->assertJson(['result' => ['instances_removed' => 2]]);
        $this->assertSame(0, DB::table('TEXT_INSTANCE_DATA')->where('c_textid', 10)->count());
    }

    #[Test]
    public function testUpdateSourceCycleBlocked(): void {
        $this->actingAs($this->makeUser(email: 'te-cycle@example.com'));

        // 自引用：10 的來源設為 10 自己。
        $this->postJson('/api/v2/mutate', $this->updatePayload(['source_id' => 10]))
            ->assertStatus(422)
            ->assertJsonPath('errors.source_id.0', 'source_cycle');

        // 成環：7596 的來源改成其後代 10（10.c_source=7596）。
        $payload = $this->updatePayload(['title' => '來源總目', 'title_pinyin' => 'laiyuan zongmu', 'source_id' => 10, 'instances' => []], 7596);
        unset($payload['changes']['dynasty_code']);
        $this->postJson('/api/v2/mutate', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.source_id.0', 'source_cycle');

        $this->assertSame(7596, (int) DB::table('TEXT_CODES')->where('c_textid', 10)->value('c_source'));
    }

    #[Test]
    public function testUpdateNotFound(): void {
        $this->actingAs($this->makeUser(email: 'te-404@example.com'));

        $this->postJson('/api/v2/mutate', $this->updatePayload([], 424242))->assertStatus(404);
    }

    // ── delete ──────────────────────────────

    #[Test]
    public function testDeleteRemovesInstancesAndRow(): void {
        $this->actingAs($this->makeUser(email: 'te-del@example.com'));

        $res = $this->postJson('/api/v2/delete', [
            'resource' => 'text-entity',
            'person_id' => 0,
            'target' => ['pk' => ['c_textid' => 10]],
        ]);

        $res->assertOk()->assertJson([
            'ok' => true,
            'result' => ['pk' => ['c_textid' => 10], 'status' => 'deleted', 'instances_deleted' => 1],
        ]);
        $this->assertDatabaseMissing('TEXT_CODES', ['c_textid' => 10]);
        $this->assertSame(0, DB::table('TEXT_INSTANCE_DATA')->where('c_textid', 10)->count());
        $this->assertSame(1, DB::table('audit_log')->where('table_name', 'TEXT_CODES')->where('operation', 'DELETE')->count());
    }

    #[Test]
    public function testDeleteBlockedWhileReferencedByPersonData(): void {
        DB::table('BIOG_SOURCE_DATA')->insert(['c_textid' => 10]);
        $this->actingAs($this->makeUser(email: 'te-del-ref@example.com'));

        $this->postJson('/api/v2/delete', [
            'resource' => 'text-entity',
            'person_id' => 0,
            'target' => ['pk' => ['c_textid' => 10]],
        ])->assertStatus(409);
        $this->assertDatabaseHas('TEXT_CODES', ['c_textid' => 10]);
    }

    #[Test]
    public function testDeleteBlockedWhileHasChildTexts(): void {
        // 層級護欄：7596 是 10 的來源（樹的中間節點），不可刪。
        $this->actingAs($this->makeUser(email: 'te-del-child@example.com'));

        $this->postJson('/api/v2/delete', [
            'resource' => 'text-entity',
            'person_id' => 0,
            'target' => ['pk' => ['c_textid' => 7596]],
        ])->assertStatus(409);
        $this->assertDatabaseHas('TEXT_CODES', ['c_textid' => 7596]);
    }
}
