<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    public function run(): void
    {
        // 名前をキーに入れるので、繰り返し実行しても増えない。この順で入れるので空の DB では id が 1〜5 になる
        foreach (['Laravel', '雑談', '暮らし', 'つまずき', '質問'] as $name) {
            Tag::firstOrCreate(['name' => $name]);
        }
    }
}
