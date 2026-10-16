<?php

namespace Tests\Feature;

use App\Enums\CongestionLevel;
use App\Enums\StockStatus;
use App\Enums\UserRole;
use App\Events\BoothCongestionUpdated;
use App\Events\ItemStockUpdated;
use App\Models\Booth;
use App\Models\Game;
use App\Models\GameBooth;
use App\Models\MenuItem;
use App\Models\User;
use App\Services\BoothService;
use App\Services\BoothStatusService;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * リアルタイム配信（WP-B8）。ゲームごとの公開チャンネル `game.{gameId}` に、混雑・在庫の更新を
 * キュー経由で小さなペイロードで送ることを確かめる。Pusher へは実際には通信しない（ダミーのブロードキャスターで受ける）。
 */
class RealtimeEventsTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    private Booth $booth;

    private MenuItem $item;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-16 19:30:00', 'Asia/Tokyo'));
        $this->game = Game::factory()->today()->create();
        $this->booth = Booth::factory()->create();
        GameBooth::factory()->for($this->game)->for($this->booth)->create();
        $this->item = MenuItem::factory()->create(['booth_id' => $this->booth->id]);
        $this->staff = User::factory()->create();
        $this->staff->forceFill(['role' => UserRole::Staff])->save();
        $this->staff->booths()->attach($this->booth->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function congestionUrl(?Game $game = null, ?Booth $booth = null): string
    {
        return '/api/staff/games/'.($game ?? $this->game)->id.'/booths/'.($booth ?? $this->booth)->id.'/congestion';
    }

    private function stockUrl(): string
    {
        return "/api/staff/games/{$this->game->id}/menu-items/{$this->item->id}/stock";
    }

    // ===== 送られるイベント =====

    public function test_congestion_update_dispatches_the_event_with_the_updated_values(): void
    {
        Event::fake([BoothCongestionUpdated::class, ItemStockUpdated::class]);
        Sanctum::actingAs($this->staff);

        $this->putJson($this->congestionUrl(), ['level' => 3, 'wait_minutes' => 25])->assertOk();

        Event::assertDispatchedTimes(BoothCongestionUpdated::class, 1);
        Event::assertNotDispatched(ItemStockUpdated::class);
        Event::assertDispatched(BoothCongestionUpdated::class, fn (BoothCongestionUpdated $e) => $e->broadcastWith() === [
            'game_id' => $this->game->id,
            'booth_id' => $this->booth->id,
            'game_booth_id' => GameBooth::where('booth_id', $this->booth->id)->value('id'),
            'level' => 3,
            'label' => '混雑',
            'wait_minutes' => 25,
            'updated_at' => '2026-10-16T19:30:00+09:00',
        ]);
    }

    public function test_stock_update_dispatches_the_event_with_the_updated_values(): void
    {
        Event::fake([BoothCongestionUpdated::class, ItemStockUpdated::class]);
        Sanctum::actingAs($this->staff);

        $this->putJson($this->stockUrl(), ['status' => 'low', 'quantity' => 4])->assertOk();

        Event::assertDispatchedTimes(ItemStockUpdated::class, 1);
        Event::assertNotDispatched(BoothCongestionUpdated::class);
        Event::assertDispatched(ItemStockUpdated::class, fn (ItemStockUpdated $e) => $e->broadcastWith() === [
            'game_id' => $this->game->id,
            'booth_id' => $this->booth->id,
            'menu_item_id' => $this->item->id,
            'status' => 'low',
            'label' => '残りわずか',
            'quantity' => 4,
            'updated_at' => '2026-10-16T19:30:00+09:00',
        ]);
    }

    public function test_every_update_is_announced_even_when_the_value_is_the_same(): void
    {
        Event::fake([ItemStockUpdated::class]);
        Sanctum::actingAs($this->staff);

        $this->putJson($this->stockUrl(), ['status' => 'in_stock'])->assertOk();
        $this->putJson($this->stockUrl(), ['status' => 'in_stock'])->assertOk();

        Event::assertDispatchedTimes(ItemStockUpdated::class, 2); // 「いま確認した」ことも最終更新として伝える
    }

    public function test_nothing_is_dispatched_when_the_update_is_rejected(): void
    {
        Event::fake([BoothCongestionUpdated::class, ItemStockUpdated::class]);
        $visitor = User::factory()->create();
        $otherBooth = Booth::factory()->create();
        GameBooth::factory()->for($this->game)->for($otherBooth)->create();

        // 来場者（403）・他のブースのスタッフ（403）・入力不正（422）・出店していない（404）・未ログイン（401）
        Sanctum::actingAs($visitor);
        $this->putJson($this->congestionUrl(), ['level' => 3])->assertForbidden();
        $this->putJson($this->stockUrl(), ['status' => 'low'])->assertForbidden();

        Sanctum::actingAs($this->staff);
        $this->putJson($this->congestionUrl(null, $otherBooth), ['level' => 3])->assertForbidden();
        $this->putJson($this->congestionUrl(), ['level' => 9])->assertUnprocessable();
        $this->putJson($this->stockUrl(), ['status' => 'nope'])->assertUnprocessable();
        $this->putJson($this->congestionUrl(Game::factory()->create()), ['level' => 3])->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->putJson($this->congestionUrl(), ['level' => 3])->assertUnauthorized();

        Event::assertNothingDispatched();
    }

    // ===== チャンネルと形 =====

    public function test_events_use_the_public_channel_of_their_game(): void
    {
        $gameId = $this->game->id;
        $events = [
            new BoothCongestionUpdated($gameId, 1, 2, 3, '混雑', 10, '2026-10-16T19:30:00+09:00'),
            new ItemStockUpdated($gameId, 1, 2, 'low', '残りわずか', null, '2026-10-16T19:30:00+09:00'),
        ];

        foreach ($events as $event) {
            $channels = $event->broadcastOn();
            $this->assertCount(1, $channels);
            $this->assertSame("game.{$gameId}", $channels[0]->name);
            // 公開チャンネル（認証なし）。private- / presence- の接頭辞が付かない
            $this->assertSame(Channel::class, $channels[0]::class);
            $this->assertNotInstanceOf(PrivateChannel::class, $channels[0]);
            $this->assertNotInstanceOf(PresenceChannel::class, $channels[0]);
            $this->assertSame("game.{$gameId}", (string) $channels[0]);
        }
    }

    public function test_event_names_are_fixed_strings_the_frontend_listens_to(): void
    {
        $this->assertSame('booth.congestion.updated', (new BoothCongestionUpdated(1, 1, 1, 1, '空いている', null, 'x'))->broadcastAs());
        $this->assertSame('item.stock.updated', (new ItemStockUpdated(1, 1, 1, 'in_stock', '在庫あり', null, 'x'))->broadcastAs());
    }

    public function test_events_are_queued_not_sent_inside_the_request(): void
    {
        foreach ([BoothCongestionUpdated::class, ItemStockUpdated::class] as $class) {
            $this->assertContains(ShouldBroadcast::class, class_implements($class));
            $this->assertNotContains(ShouldBroadcastNow::class, class_implements($class), 'ShouldBroadcastNow だとリクエストの中で同期的に送ってしまう');
        }
    }

    public function test_payloads_stay_small_far_below_the_pusher_limit(): void
    {
        $longest = [
            (new BoothCongestionUpdated(999999, 999999, 999999, 4, '非常に混雑', 180, '2026-10-16T19:30:00+09:00'))->broadcastWith(),
            (new ItemStockUpdated(999999, 999999, 999999, 'in_stock', '在庫あり', 99999, '2026-10-16T19:30:00+09:00'))->broadcastWith(),
        ];

        foreach ($longest as $payload) {
            $this->assertLessThan(512, strlen(json_encode($payload, JSON_UNESCAPED_UNICODE)), 'Pusher の上限は約 10KB。ペイロードは小さく保つ');
            foreach ($payload as $value) {
                $this->assertTrue(is_int($value) || is_string($value) || $value === null, 'ネストした配列・オブジェクトを入れない');
            }
        }
    }

    public function test_payload_has_no_user_information(): void
    {
        $payload = (new BoothCongestionUpdated(1, 1, 1, 1, '空いている', 0, 'x'))->broadcastWith() + (new ItemStockUpdated(1, 1, 1, 'low', '残りわずか', 1, 'x'))->broadcastWith();

        $this->assertSame([], array_values(array_filter(array_keys($payload), fn ($k) => str_contains($k, 'user') || str_contains($k, 'email'))), '公開チャンネルなので、更新した人の情報は流さない');
    }

    // ===== キュー経由で、実際にチャンネルへ送られる =====

    public function test_update_pushes_a_broadcast_job_onto_the_queue(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->staff);

        $this->putJson($this->congestionUrl(), ['level' => 2, 'wait_minutes' => 5])->assertOk();
        $this->putJson($this->stockUrl(), ['status' => 'sold_out'])->assertOk();

        Queue::assertPushed(BroadcastEvent::class, 2);
        Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof BoothCongestionUpdated);
        Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof ItemStockUpdated);
    }

    public function test_worker_sends_the_event_to_the_game_channel_through_the_broadcaster(): void
    {
        $sent = $this->captureBroadcasts();
        Sanctum::actingAs($this->staff);

        $this->putJson($this->congestionUrl(), ['level' => 4, 'wait_minutes' => 60])->assertOk();
        $this->putJson($this->stockUrl(), ['status' => 'in_stock', 'quantity' => 12])->assertOk();

        $this->assertCount(2, $sent->calls);
        [$congestion, $stock] = $sent->calls;
        $this->assertSame(["game.{$this->game->id}"], $congestion['channels']);
        $this->assertSame('booth.congestion.updated', $congestion['event']);
        $this->assertSame(4, $congestion['payload']['level']);
        $this->assertSame('非常に混雑', $congestion['payload']['label']);
        $this->assertSame(60, $congestion['payload']['wait_minutes']);
        $this->assertSame(["game.{$this->game->id}"], $stock['channels']);
        $this->assertSame('item.stock.updated', $stock['event']);
        $this->assertSame(12, $stock['payload']['quantity']);
        // 送信元の接続を除外する指定（socket）は使わない。更新した本人の画面も、同じ内容を受け取ってよい
        $this->assertNull($congestion['payload']['socket'] ?? null);
        $this->assertNull($stock['payload']['socket'] ?? null);
    }

    public function test_events_of_different_games_go_to_different_channels(): void
    {
        $other = Game::factory()->create();
        GameBooth::factory()->for($other)->for($this->booth)->create();
        $sent = $this->captureBroadcasts();
        Sanctum::actingAs($this->staff);

        $this->putJson($this->congestionUrl(), ['level' => 1])->assertOk();
        $this->putJson($this->congestionUrl($other), ['level' => 2])->assertOk();

        $this->assertSame([["game.{$this->game->id}"], ["game.{$other->id}"]], array_column($sent->calls, 'channels'));
    }

    /** 受け取った側（画面）が一覧を取り直しても最新が返るよう、配信の時点でキャッシュは捨てられている */
    public function test_cache_is_already_cleared_when_the_event_is_dispatched(): void
    {
        $booths = app(BoothService::class);
        $booths->listForGame($this->game); // 古い値をキャッシュに載せる
        $seen = [];
        Event::listen(BoothCongestionUpdated::class, function () use ($booths, &$seen) {
            $seen[] = $booths->listForGame($this->game)[0]['congestion_level'];
        });
        Sanctum::actingAs($this->staff);

        $this->putJson($this->congestionUrl(), ['level' => 4, 'wait_minutes' => 30])->assertOk();

        $this->assertSame([4], $seen);
    }

    // ===== 配信が失敗しても、更新は成功する =====

    public function test_update_succeeds_even_if_the_broadcaster_fails(): void
    {
        Exceptions::fake();
        $this->useBroadcaster(new class extends Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = [])
            {
                throw new RuntimeException('Pusher に接続できません');
            }
        });
        Sanctum::actingAs($this->staff);

        // テストのキューは同期（sync）なので、配信の失敗がリクエストの中で起きる。それでも更新は成功する
        $this->putJson($this->congestionUrl(), ['level' => 3, 'wait_minutes' => 20])->assertOk()->assertJsonPath('data.congestion.level', 3);
        $this->putJson($this->stockUrl(), ['status' => 'low'])->assertOk();

        $this->assertSame(CongestionLevel::Crowded, GameBooth::where('booth_id', $this->booth->id)->sole()->congestion_level);
        $this->assertSame(StockStatus::Low, $this->item->stocks()->sole()->status);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Pusher に接続できません'); // 失敗は記録される
    }

    public function test_keys_not_set_means_events_are_dropped_and_the_app_works(): void
    {
        config(['broadcasting.default' => 'null', 'broadcasting.connections.pusher.key' => null]);
        Sanctum::actingAs($this->staff);

        $this->putJson($this->congestionUrl(), ['level' => 2, 'wait_minutes' => 5])->assertOk();
        $this->putJson($this->stockUrl(), ['status' => 'low'])->assertOk();
        $this->getJson("/api/games/{$this->game->id}/booths")->assertOk()->assertJsonPath('data.0.congestion.level', 2);
    }

    // ===== チャンネル認証 API は無い（公開チャンネルだけを使う） =====

    public function test_there_is_no_channel_authorization_endpoint(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson('/api/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-game.1'])->assertNotFound();
        $this->getJson('/api/broadcasting/auth')->assertNotFound();
        $this->post('/broadcasting/auth')->assertNotFound();
    }

    // ===== ヘルパー =====

    /** 送られた内容を記録するブロードキャスター（Pusher の代わり）に切り替える */
    private function captureBroadcasts(): object
    {
        $broadcaster = new class extends Broadcaster
        {
            /** @var array<int, array{channels: array<int, string>, event: string, payload: array<string, mixed>}> */
            public array $calls = [];

            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = [])
            {
                $this->calls[] = ['channels' => array_map('strval', $channels), 'event' => $event, 'payload' => $payload];
            }
        };
        $this->useBroadcaster($broadcaster);

        return $broadcaster;
    }

    private function useBroadcaster(Broadcaster $broadcaster): void
    {
        config(['broadcasting.default' => 'capture', 'broadcasting.connections.capture' => ['driver' => 'capture']]);
        $manager = $this->app->make(BroadcastManager::class);
        $manager->extend('capture', fn () => $broadcaster);
        $manager->purge('capture');
    }
}
