<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookmarkController extends Controller
{
    public function store(Request $request, Post $post)
    {
        // すでに付いている場合は何もしない（1 ユーザー 1 ポストにつき 1 つまで）。
        // 確認してから入れる 2 段階だと同時送信で一意制約違反になるため、1 回の INSERT で重複を無視する
        DB::table('bookmarks')->insertOrIgnore([
            'user_id' => $request->user()->id,
            'post_id' => $post->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('posts.index');
    }

    public function destroy(Request $request, Post $post)
    {
        // 外せるのはログイン中のユーザー自身のブックマークだけ
        $post->bookmarkedBy()->detach($request->user()->id);

        return redirect()->route('posts.index');
    }
}
