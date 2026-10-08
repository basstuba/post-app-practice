<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * ポストとリプライで共通の本文ルール（140字以内・改行は1字として数える）。
 */
trait ValidatesContent
{
    private const CONTENT_MAX = 140;

    /**
     * ブラウザは改行を \r\n で送るので、1 字として数えるために \n にそろえる。
     * 文字列以外（配列など）はそのまま残し、バリデーションの string ルールで弾く。
     */
    protected function normalizeContent(Request $request): void
    {
        $content = $request->input('content');

        if (is_string($content)) {
            $request->merge(['content' => str_replace("\r\n", "\n", $content)]);
        }
    }

    protected function contentRules(): string
    {
        return 'required|string|max:'.self::CONTENT_MAX;
    }

    protected function contentMessages(): array
    {
        return [
            'content.max' => '本文は'.self::CONTENT_MAX.'字以内で入力してください。',
        ];
    }
}
