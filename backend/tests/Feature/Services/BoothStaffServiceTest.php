<?php

namespace Tests\Feature\Services;

use App\Enums\UserRole;
use App\Exceptions\ServiceException;
use App\Models\Booth;
use App\Models\Game;
use App\Models\GameBooth;
use App\Models\User;
use App\Services\BoothStaffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BoothStaffServiceTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    public function test_staff_sees_only_own_booths_and_admin_sees_all(): void
    {
        $mine = Booth::factory()->create(['name' => 'A']);
        Booth::factory()->create(['name' => 'B']);
        $staff = $this->userWithRole(UserRole::Staff);
        $staff->booths()->attach($mine->id);

        $service = app(BoothStaffService::class);

        $this->assertSame(['A'], $service->manageableBooths($staff)->pluck('name')->all());
        $this->assertSame(['A', 'B'], $service->manageableBooths($this->userWithRole(UserRole::Admin))->pluck('name')->all());
        $this->assertCount(0, $service->manageableBooths($this->userWithRole(UserRole::Staff)), '担当なしの staff は 0 件');
    }

    public function test_upcoming_games_are_today_or_later_soonest_first_up_to_three(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00', 'Asia/Tokyo'));
        $booth = Booth::factory()->create();
        $staff = $this->userWithRole(UserRole::Staff);
        $staff->booths()->attach($booth->id);
        foreach (['2026-10-10 19:05', '2026-10-30 19:05', '2026-10-16 19:05', '2026-10-23 14:05', '2026-11-06 19:05'] as $at) {
            GameBooth::factory()->for(Game::factory()->create(['tipoff_at' => Carbon::parse($at, 'Asia/Tokyo')]))->for($booth)->create();
        }

        $games = app(BoothStaffService::class)->manageableBooths($staff)->first()->upcomingGameBooths;

        $this->assertSame(['2026-10-16', '2026-10-23', '2026-10-30'], $games->map(fn ($gb) => $gb->game->tipoff_at->toDateString())->all());
        Carbon::setTestNow();
    }

    public function test_removing_the_last_booth_demotes_staff_to_user(): void
    {
        $booth = Booth::factory()->create();
        $staff = $this->userWithRole(UserRole::Staff);
        $staff->booths()->attach($booth->id);

        app(BoothStaffService::class)->remove($booth, $staff);

        $this->assertSame(0, $staff->booths()->count());
        $this->assertSame(UserRole::User, $staff->refresh()->role);
    }

    public function test_removing_one_of_several_booths_keeps_the_staff_role(): void
    {
        [$a, $b] = Booth::factory()->count(2)->create();
        $staff = $this->userWithRole(UserRole::Staff);
        $staff->booths()->attach([$a->id, $b->id]);

        app(BoothStaffService::class)->remove($a, $staff);

        $this->assertSame([$b->id], $staff->booths()->pluck('booths.id')->all());
        $this->assertSame(UserRole::Staff, $staff->refresh()->role);
    }

    public function test_removing_an_admin_keeps_the_admin_role(): void
    {
        $booth = Booth::factory()->create();
        $admin = $this->userWithRole(UserRole::Admin);
        $admin->booths()->attach($booth->id);

        app(BoothStaffService::class)->remove($booth, $admin);

        $this->assertSame(UserRole::Admin, $admin->refresh()->role);
    }

    public function test_removing_a_user_who_is_not_staff_of_the_booth_is_404(): void
    {
        $booth = Booth::factory()->create();
        $other = Booth::factory()->create();
        $staff = $this->userWithRole(UserRole::Staff);
        $staff->booths()->attach($other->id);

        try {
            app(BoothStaffService::class)->remove($booth, $staff);
            $this->fail('ServiceException expected');
        } catch (ServiceException $e) {
            $this->assertSame(404, $e->status);
        }

        $this->assertSame(UserRole::Staff, $staff->refresh()->role, '別のブースの担当は変わらない');
        $this->assertSame(1, $staff->booths()->count());
    }

    public function test_staff_list_of_a_booth(): void
    {
        $booth = Booth::factory()->create();
        $s = User::factory()->create(['name' => 'B さん']);
        $t = User::factory()->create(['name' => 'A さん']);
        $booth->staff()->attach([$s->id, $t->id]);

        $this->assertSame(['A さん', 'B さん'], app(BoothStaffService::class)->staffOf($booth)->pluck('name')->all());
    }
}
