<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 公開デモ用のデータ（冪等: 何度実行しても重複しない）
        $this->call(DemoSeeder::class);
    }
}
