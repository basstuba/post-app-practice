<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ValidatesContent;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PostController extends Controller
{
    use ValidatesContent;

    private const KEYWORD_MAX = 100;

    public function index(Request $request)
    {
        // ?tag を付けたときだけ、そのタグのポストに絞り込む（空は指定なし）
        $tag = $this->findTagFromQuery($request);

        // ?category も同じ。存在しないトピックは入力エラーではなく 404 にする（q・bookmarked の確認より先）
        $category = $this->findCategoryFromQuery($request);

        $this->normalizeKeyword($request);

        try {
            $validated = $request->validate([
                'q' => [
                    'bail', 'nullable', 'string',
                    // 不正な UTF-8（?q=%FF など）は、絞り込みも文字数の確認もできないのでエラーにする
                    fn ($attribute, $value, $fail) => mb_check_encoding($value, 'UTF-8') ? null : $fail('validation.utf8')->translate(),
                    'max:'.self::KEYWORD_MAX,
                ],
                'bookmarked' => ['nullable', Rule::in(['1'])],
            ]);
        } catch (ValidationException $e) {
            // エラーは、検索欄のある一覧に戻して見せる（直前のページには戻さない）。選んでいるタグは引き継ぐ
            throw $e->redirectTo(route('posts.index', array_filter(['tag' => $tag?->id])));
        }

        // 空（空白だけだった場合を含む）は指定なし
        $keyword = ($validated['q'] ?? '') === '' ? null : $validated['q'];
        $bookmarkedOnly = ($validated['bookmarked'] ?? null) === '1';

        // 全ポストを投稿者・カテゴリごと新しい順に取得して、タイムラインに表示する
        // いいねの数と、ログイン中のユーザーが押しているかも一緒に取得する
        // ブックマークは、ログイン中のユーザーが付けているかだけを取得する（他人の分・数は取得しない）
        // 絞り込みは q・category・bookmarked・tag をすべて AND で重ねる
        $posts = Post::with(['user', 'category', 'tags'])
            ->withCount('likedBy')
            ->withExists(['likedBy as liked_by_me' => fn ($query) => $query->where('users.id', auth()->id())])
            ->withExists(['bookmarkedBy as bookmarked_by_me' => fn ($query) => $query->where('users.id', auth()->id())])
            ->when($tag, fn ($query) => $query->whereHas('tags', fn ($query) => $query->where('tags.id', $tag->id)))
            ->when($keyword !== null, function ($query) use ($keyword) {
                $like = '%'.$this->escapeLike($keyword).'%';

                $query->where(fn ($query) => $query->where('title', 'like', $like)->orWhere('content', 'like', $like));
            })
            ->when($category, fn ($query) => $query->where('category_id', $category->id))
            ->when($bookmarkedOnly, fn ($query) => $query->whereHas('bookmarkedBy', fn ($query) => $query->where('users.id', auth()->id())))
            ->latest()
            ->get();

        $tags = Tag::orderBy('id')->get();
        $categories = Category::orderBy('id')->get();

        // 検索欄に残す値。エラーで戻ったときは入力した内容（old）を使う。文字列以外（配列など）は残さない
        $oldKeyword = old('q', $keyword);
        $keywordInput = is_string($oldKeyword) ? $oldKeyword : '';
        $oldCategory = old('category', $category?->id);
        $selectedCategoryId = is_scalar($oldCategory) ? (string) $oldCategory : '';
        // エラーで戻ったとき（old がある）は、チェックを外して送った場合も送信値の通りにする。外すと bookmarked は送られない
        $bookmarkedChecked = $request->old() === []
            ? $bookmarkedOnly
            : $request->old('bookmarked') === '1';

        // 条件が付いているか（「条件をクリア」の表示と、0 件のときの文言の出し分けに使う）
        $hasSearch = $keyword !== null || $category !== null || $bookmarkedOnly;

        return view('posts.index', compact(
            'posts', 'tags', 'tag', 'categories', 'keywordInput', 'selectedCategoryId', 'bookmarkedChecked', 'hasSearch',
        ));
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

    /**
     * ?category の値からトピックを取り出す。空は指定なし、存在しない id・数値以外・配列は 404。
     */
    private function findCategoryFromQuery(Request $request): ?Category
    {
        $value = $request->query('category');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            abort(404);
        }

        return Category::findOrFail($value);
    }

    /**
     * キーワードの前後の空白（半角・全角）を取り除く。100 字の数え方も取り除いたあとの文字数。
     * 文字列以外（配列など）と不正な UTF-8 はそのまま残し、バリデーションで弾く。
     */
    private function normalizeKeyword(Request $request): void
    {
        $keyword = $request->input('q');

        // 不正な UTF-8 は正規表現で null になってしまうので触らず、バリデーションで弾く
        if (is_string($keyword) && mb_check_encoding($keyword, 'UTF-8')) {
            $request->merge(['q' => preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $keyword)]);
        }
    }

    /**
     * LIKE で特別な意味を持つ \ % _ を、ふつうの文字として探せるようにエスケープする。
     */
    private function escapeLike(string $keyword): string
    {
        return addcslashes($keyword, '\%_');
    }

    private function postMessages(): array
    {
        return $this->contentMessages() + [
            'category_id.required' => 'トピックを選択してください。',
        ];
    }
}
