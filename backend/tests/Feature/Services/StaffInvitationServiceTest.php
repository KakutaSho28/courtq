<?php

namespace Tests\Feature\Services;

use App\Enums\UserRole;
use App\Exceptions\ServiceException;
use App\Models\Booth;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Services\StaffInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StaffInvitationServiceTest extends TestCase
{
    use RefreshDatabase;

    private StaffInvitationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(StaffInvitationService::class);
    }

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    public function test_issued_code_is_8_unambiguous_characters_and_expires_in_7_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00'));
        $admin = $this->userWithRole(UserRole::Admin);

        $invitation = $this->service->issue(Booth::factory()->create(), $admin);

        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $invitation->code);
        $this->assertTrue($invitation->expires_at->eq(now()->addDays(7)));
        $this->assertSame($admin->id, $invitation->created_by);
        $this->assertNull($invitation->used_at);
        Carbon::setTestNow();
    }

    public function test_codes_never_contain_ambiguous_characters(): void
    {
        $admin = $this->userWithRole(UserRole::Admin);
        $booth = Booth::factory()->create();

        $codes = collect(range(1, 200))->map(fn () => $this->service->issue($booth, $admin)->code);

        $this->assertSame(200, $codes->unique()->count());
        $this->assertFalse($codes->contains(fn ($code) => preg_match('/[01OIL]/', $code) === 1));
    }

    public function test_user_accepting_becomes_staff_of_the_booth(): void
    {
        $booth = Booth::factory()->create();
        $invitation = $this->service->issue($booth, $this->userWithRole(UserRole::Admin));
        $user = $this->userWithRole(UserRole::User);

        $result = $this->service->accept($user, $invitation->code);

        $this->assertSame($booth->id, $result->id);
        $this->assertSame(UserRole::Staff, $user->refresh()->role);
        $this->assertSame([$booth->id], $user->booths()->pluck('booths.id')->all());
        $invitation->refresh();
        $this->assertNotNull($invitation->used_at);
        $this->assertSame($user->id, $invitation->used_by);
    }

    public function test_code_is_accepted_with_hyphen_spaces_lowercase_and_full_width(): void
    {
        $invitation = StaffInvitation::factory()->create(['code' => 'K7Q2MX9P']);
        $user = $this->userWithRole(UserRole::User);

        $this->service->accept($user, ' ｋ７ｑ２－mx9p ');

        $this->assertNotNull($invitation->refresh()->used_at);
    }

    public function test_staff_accepting_gets_an_additional_booth(): void
    {
        $first = Booth::factory()->create();
        $second = Booth::factory()->create();
        $staff = $this->userWithRole(UserRole::Staff);
        $staff->booths()->attach($first->id);

        $this->service->accept($staff, $this->service->issue($second, $this->userWithRole(UserRole::Admin))->code);

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $staff->booths()->pluck('booths.id')->all());
        $this->assertSame(UserRole::Staff, $staff->refresh()->role);
    }

    public function test_admin_accepting_keeps_the_admin_role(): void
    {
        $admin = $this->userWithRole(UserRole::Admin);

        $this->service->accept($admin, $this->service->issue(Booth::factory()->create(), $admin)->code);

        $this->assertSame(UserRole::Admin, $admin->refresh()->role);
    }

    /** @return array<string, array{0: callable(): string}> */
    public static function invalidCodes(): array
    {
        return [
            'unknown' => [fn () => 'ZZZZZZZZ'],
            'expired' => [fn () => StaffInvitation::factory()->create(['code' => 'EXPIRED2', 'expires_at' => now()->subSecond()])->code],
            'used' => [fn () => StaffInvitation::factory()->create(['code' => 'USEDCODE', 'used_at' => now()->subMinute(), 'used_by' => User::factory()->create()->id])->code],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCodes')]
    public function test_invalid_expired_and_used_codes_fail_with_the_same_message_and_change_nothing(callable $code): void
    {
        $user = $this->userWithRole(UserRole::User);

        try {
            $this->service->accept($user, $code());
            $this->fail('ServiceException expected');
        } catch (ServiceException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame(StaffInvitationService::INVALID_MESSAGE, $e->getMessage());
        }

        $this->assertSame(UserRole::User, $user->refresh()->role);
        $this->assertSame(0, $user->booths()->count());
    }

    public function test_a_code_can_be_used_only_once(): void
    {
        $code = $this->service->issue(Booth::factory()->create(), $this->userWithRole(UserRole::Admin))->code;
        $this->service->accept($this->userWithRole(UserRole::User), $code);
        $second = $this->userWithRole(UserRole::User);

        $this->expectException(ServiceException::class);
        try {
            $this->service->accept($second, $code);
        } finally {
            $this->assertSame(UserRole::User, $second->refresh()->role);
        }
    }

    public function test_format_splits_into_groups_of_four(): void
    {
        $this->assertSame('K7Q2-MX9P', StaffInvitationService::format('K7Q2MX9P'));
    }
}
