<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\ServiceException;
use App\Models\Booth;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * スタッフ招待コードの発行（ADMIN-05）と受け付け（STAFF-05）。
 * コードはランダム・1 回限り・期限付き。使うとそのブースの担当になり、一般ユーザーはスタッフになる。
 */
class StaffInvitationService
{
    /** スマホで手入力しやすいよう、見間違えやすい 0 / O / 1 / I / L を除いた文字だけを使う */
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** 無効・期限切れ・使用済みを区別しない（どれに当たったかを推測させないため） */
    public const INVALID_MESSAGE = '招待コードが正しくないか、有効期限が切れています';

    public function issue(Booth $booth, User $admin): StaffInvitation
    {
        // 31 文字 × 8 桁で約 8.5 × 10^11 通り。重なることはまず無いが、念のため数回やり直す
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = $this->generateCode();
            if (! StaffInvitation::query()->where('code', $code)->exists()) {
                return StaffInvitation::create([
                    'booth_id' => $booth->id,
                    'code' => $code,
                    'expires_at' => now()->addDays((int) config('courtq.invitation_ttl_days')),
                    'created_by' => $admin->id,
                ])->setRelation('booth', $booth);
            }
        }

        throw new RuntimeException('招待コードを生成できませんでした');
    }

    /** @return Booth 担当になったブース */
    public function accept(User $user, string $rawCode): Booth
    {
        $code = self::normalize($rawCode);

        return DB::transaction(function () use ($user, $code) {
            // 同じコードが同時に使われても 1 人だけが成功するよう、行をロックして確認する
            $invitation = StaffInvitation::query()->where('code', $code)->lockForUpdate()->first();
            if ($invitation === null || ! $invitation->isUsable()) {
                throw new ServiceException(self::INVALID_MESSAGE, 422);
            }

            $invitation->update(['used_at' => now(), 'used_by' => $user->id]);
            $user->booths()->syncWithoutDetaching([$invitation->booth_id]);
            // 役割は上げるだけ（admin を staff に下げない）。role は fillable ではないので forceFill
            if ($user->role === UserRole::User) {
                $user->forceFill(['role' => UserRole::Staff])->save();
            }

            return $invitation->booth;
        });
    }

    /** 入力のゆれを吸収する: 全角英数字 → 半角、小文字 → 大文字、ハイフン・空白を無視 */
    public static function normalize(string $code): string
    {
        $halfWidth = mb_convert_kana($code, 'as');

        return strtoupper((string) preg_replace('/[\s\-‐−ー]/u', '', $halfWidth));
    }

    /** 表示用: K7Q2MX9P → K7Q2-MX9P */
    public static function format(string $code): string
    {
        return implode('-', str_split($code, 4));
    }

    private function generateCode(): string
    {
        $length = (int) config('courtq.invitation_code_length');
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
