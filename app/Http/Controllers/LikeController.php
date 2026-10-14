<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;

class LikeController extends Controller
{
    public function store(Request $request, Post $post)
    {
        // すでに押している場合は何もしない（1 ユーザー 1 ポストにつき 1 つまで）
        $post->likedBy()->syncWithoutDetaching([$request->user()->id]);

        return redirect()->route('posts.index');
    }

    public function destroy(Request $request, Post $post)
    {
        $post->likedBy()->detach($request->user()->id);

        return redirect()->route('posts.index');
    }
}
