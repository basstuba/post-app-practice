<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ValidatesContent;
use App\Models\Post;
use App\Models\Reply;
use Illuminate\Http\Request;

class ReplyController extends Controller
{
    use ValidatesContent;

    public function store(Request $request, Post $post)
    {
        $this->normalizeContent($request);

        $validated = $request->validate([
            'content' => $this->contentRules(),
        ], $this->contentMessages());

        $post->replies()->create([
            'user_id' => $request->user()->id,
            'content' => $validated['content'],
        ]);

        return redirect()->route('posts.show', $post);
    }

    public function destroy(Reply $reply)
    {
        $this->authorize('delete', $reply);

        $reply->delete();

        return redirect()->route('posts.show', $reply->post_id);
    }
}
