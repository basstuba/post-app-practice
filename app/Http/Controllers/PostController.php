<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ValidatesContent;
use App\Models\Category;
use App\Models\Post;
use Illuminate\Http\Request;

class PostController extends Controller
{
    use ValidatesContent;

    public function index()
    {
        $posts = Post::with(['user', 'category'])->latest()->get();

        return view('posts.index', compact('posts'));
    }

    public function create()
    {
        $categories = Category::orderBy('id')->get();

        return view('posts.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $this->normalizeContent($request);

        $validated = $request->validate($this->postRules(), $this->postMessages());

        // 投稿者はリクエストではなくログイン中のユーザーから決める
        $request->user()->posts()->create($validated);

        return redirect()->route('posts.index');
    }

    public function show(Post $post)
    {
        $post->load(['user', 'category', 'replies.user']);

        return view('posts.show', compact('post'));
    }

    public function edit(Post $post)
    {
        $this->authorize('update', $post);

        $categories = Category::orderBy('id')->get();

        return view('posts.edit', compact('post', 'categories'));
    }

    public function update(Request $request, Post $post)
    {
        $this->authorize('update', $post);

        $this->normalizeContent($request);

        $validated = $request->validate($this->postRules(), $this->postMessages());

        $post->update($validated);

        return redirect()->route('posts.index');
    }

    public function destroy(Post $post)
    {
        $this->authorize('delete', $post);

        $post->delete();

        return redirect()->route('posts.index');
    }

    private function postRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => $this->contentRules(),
            'category_id' => 'required|exists:categories,id',
        ];
    }

    private function postMessages(): array
    {
        return $this->contentMessages() + [
            'category_id.required' => 'トピックを選択してください。',
        ];
    }
}
