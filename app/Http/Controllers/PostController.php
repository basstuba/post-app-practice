<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ValidatesContent;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class PostController extends Controller
{
    use ValidatesContent;

    public function index(Request $request)
    {
        // ?tag を付けたときだけ、そのタグのポストに絞り込む（空は指定なし）
        $tag = $this->findTagFromQuery($request);

        // 全ポストを投稿者・カテゴリごと新しい順に取得して、タイムラインに表示する
        // いいねの数と、ログイン中のユーザーが押しているかも一緒に取得する
        $posts = Post::with(['user', 'category', 'tags'])
            ->withCount('likedBy')
            ->withExists(['likedBy as liked_by_me' => fn ($query) => $query->where('users.id', auth()->id())])
            ->when($tag, fn ($query) => $query->whereHas('tags', fn ($query) => $query->where('tags.id', $tag->id)))
            ->latest()
            ->get();

        $tags = Tag::orderBy('id')->get();

        return view('posts.index', compact('posts', 'tags', 'tag'));
    }

    public function create()
    {
        $categories = Category::orderBy('id')->get();
        $tags = Tag::orderBy('id')->get();

        return view('posts.create', compact('categories', 'tags'));
    }

    public function store(Request $request)
    {
        $this->normalizeContent($request);

        $validated = $request->validate($this->postRules(), $this->postMessages());

        // ポストの保存とタグの紐付けは一緒に成功・失敗させる
        // 投稿者はリクエストではなくログイン中のユーザーから決める
        DB::transaction(function () use ($request, $validated) {
            $post = $request->user()->posts()->create(Arr::except($validated, 'tags'));
            $post->tags()->sync($validated['tags'] ?? []);
        });

        return redirect()->route('posts.index');
    }

    public function show(Post $post)
    {
        $post->load(['user', 'category', 'replies.user', 'tags']);

        return view('posts.show', compact('post'));
    }

    public function edit(Post $post)
    {
        $this->authorize('update', $post);

        $categories = Category::orderBy('id')->get();
        $tags = Tag::orderBy('id')->get();
        $post->load('tags');

        return view('posts.edit', compact('post', 'categories', 'tags'));
    }

    public function update(Request $request, Post $post)
    {
        $this->authorize('update', $post);

        $this->normalizeContent($request);

        $validated = $request->validate($this->postRules(), $this->postMessages());

        // タグだけの変更では updated_at は動かない（「編集済み」は付かない）
        DB::transaction(function () use ($post, $validated) {
            $post->update(Arr::except($validated, 'tags'));
            $post->tags()->sync($validated['tags'] ?? []);
        });

        return redirect()->route('posts.index');
    }

    public function destroy(Post $post)
    {
        $this->authorize('delete', $post);

        // 自分のポストだけを削除して、タイムラインへ戻る
        $post->delete();

        return redirect()->route('posts.index');
    }

    private function postRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => $this->contentRules(),
            'category_id' => 'required|exists:categories,id',
            // 送られなければ（全部外した場合も）、tags=（空）が送られた場合も 0 件として扱う
            'tags' => 'nullable|array',
            'tags.*' => 'bail|required|integer|distinct|exists:tags,id',
        ];
    }

    /**
     * ?tag の値からタグを取り出す。空は指定なし、存在しない id・数値以外・配列は 404。
     */
    private function findTagFromQuery(Request $request): ?Tag
    {
        $value = $request->query('tag');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            abort(404);
        }

        return Tag::findOrFail($value);
    }

    private function postMessages(): array
    {
        return $this->contentMessages() + [
            'category_id.required' => 'トピックを選択してください。',
        ];
    }
}
