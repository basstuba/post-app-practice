<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookmarkTest extends TestCase
{
    use RefreshDatabase;

    private const ADD_LABEL = 'aria-label="ブックマークする"';

    private const REMOVE_LABEL = 'aria-label="ブックマークを外す"';

    // 付いている状態のボタンにだけ付くクラス（CSS の定義とは一致しない書き方）
    private const ON_CLASS = 'class="bookmark-btn bookmarked"';

    private User $user;

    private User $other;

    private Category $category;

    // 他人（$other）のポスト
    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->other = User::factory()->create();
        $this->category = Category::factory()->create();

        // 編集済みと見なされないよう、created_at と updated_at は同じ値にする
        $createdAt = now()->subHour();
        $this->post = Post::factory()->for($this->other)->for($this->category)->create([
            'title' => '他人のポスト',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function bookmark(?User $as = null, ?Post $post = null)
    {
        return $this->actingAs($as ?? $this->user)->post(route('bookmarks.store', $post ?? $this->post));
    }

    private function unbookmark(?User $as = null, ?Post $post = null)
    {
        return $this->actingAs($as ?? $this->user)->delete(route('bookmarks.destroy', $post ?? $this->post));
    }

    private function timeline(?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->get(route('posts.index'));
    }

    // --- 受け入れ条件 ---

    #[Test]
    public function ブックマークを押すと付いた状態の見た目に変わる(): void
    {
        // 押す前は枠線だけの「ブックマークする」
        $this->timeline()
            ->assertOk()
            ->assertSee(self::ADD_LABEL, false)
            ->assertSee('title="ブックマークする"', false)
            ->assertSee('fill="none"', false)
            ->assertDontSee(self::REMOVE_LABEL, false)
            ->assertDontSee(self::ON_CLASS, false);

        $this->bookmark()->assertRedirect(route('posts.index'));

        $this->assertDatabaseHas('bookmarks', ['user_id' => $this->user->id, 'post_id' => $this->post->id]);
        $this->assertDatabaseCount('bookmarks', 1);

        $this->timeline()
            ->assertOk()
            ->assertSee(self::REMOVE_LABEL, false)
            ->assertSee('title="ブックマークを外す"', false)
            ->assertSee(self::ON_CLASS, false)
            ->assertSee('fill="#2F5AA8"', false)
            ->assertDontSee(self::ADD_LABEL, false);
    }

    #[Test]
    public function 付いているブックマークのボタンを押すと外れて付いていない見た目に戻る(): void
    {
        $this->post->bookmarkedBy()->attach($this->user);

        $this->unbookmark()->assertRedirect(route('posts.index'));

        $this->assertDatabaseMissing('bookmarks', ['user_id' => $this->user->id, 'post_id' => $this->post->id]);
        $this->assertDatabaseCount('bookmarks', 0);

        $this->timeline()
            ->assertOk()
            ->assertSee(self::ADD_LABEL, false)
            ->assertDontSee(self::REMOVE_LABEL, false)
            ->assertDontSee(self::ON_CLASS, false);
    }

    #[Test]
    public function 他のユーザーが付けたブックマークは自分の画面には出ない(): void
    {
        $this->post->bookmarkedBy()->attach($this->other);

        $this->timeline()
            ->assertOk()
            ->assertSee(self::ADD_LABEL, false)
            ->assertDontSee(self::REMOVE_LABEL, false)
            ->assertDontSee(self::ON_CLASS, false);
    }

    #[Test]
    public function 自分が付けたブックマークは別のユーザーでログインし直した画面には出ない(): void
    {
        $this->bookmark();

        $this->timeline($this->other)
            ->assertOk()
            ->assertSee(self::ADD_LABEL, false)
            ->assertDontSee(self::REMOVE_LABEL, false)
            ->assertDontSee(self::ON_CLASS, false);

        // さらに別のアカウントでも同じ
        $this->timeline(User::factory()->create())
            ->assertSee(self::ADD_LABEL, false)
            ->assertDontSee(self::ON_CLASS, false);
    }

    #[Test]
    public function 複数のポストのうち付けたポストだけが付いた状態で表示される(): void
    {
        $second = Post::factory()->for($this->other)->for($this->category)->create(['title' => '2つ目のポスト']);
        $third = Post::factory()->for($this->other)->for($this->category)->create(['title' => '3つ目のポスト']);

        $this->bookmark(post: $this->post);
        $this->bookmark(post: $third);

        $html = $this->timeline()->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, self::ON_CLASS));
        $this->assertSame(2, substr_count($html, self::REMOVE_LABEL));
        $this->assertSame(1, substr_count($html, self::ADD_LABEL));
        $this->assertModelExists($second);
    }

    // --- 境界・認可・その他 ---

    #[Test]
    public function 続けて2回付けてもエラーにならずブックマークは1つのまま(): void
    {
        $this->bookmark();
        $this->bookmark()->assertRedirect(route('posts.index'));

        $this->assertDatabaseCount('bookmarks', 1);
    }

    #[Test]
    public function 付いていないポストの外すを送ってもエラーにならず件数は変わらない(): void
    {
        $this->post->bookmarkedBy()->attach($this->other);

        $this->unbookmark()->assertRedirect(route('posts.index'));

        $this->assertDatabaseCount('bookmarks', 1);
    }

    #[Test]
    public function 外しても他のユーザーのブックマークは残る(): void
    {
        $this->post->bookmarkedBy()->attach([$this->user->id, $this->other->id]);

        $this->unbookmark();

        $this->assertDatabaseCount('bookmarks', 1);
        $this->assertDatabaseHas('bookmarks', ['user_id' => $this->other->id, 'post_id' => $this->post->id]);
        $this->assertDatabaseMissing('bookmarks', ['user_id' => $this->user->id]);
    }

    #[Test]
    public function 自分のポストにもブックマークを付けられる(): void
    {
        $mine = Post::factory()->for($this->user)->for($this->category)->create(['title' => '自分のポスト']);

        $this->bookmark(post: $mine)->assertRedirect(route('posts.index'));

        $this->assertDatabaseHas('bookmarks', ['user_id' => $this->user->id, 'post_id' => $mine->id]);
    }

    #[Test]
    public function タイムラインにブックマークの数は出ない(): void
    {
        $this->post->bookmarkedBy()->attach([$this->user->id, $this->other->id]);

        $html = $this->timeline()->assertOk()->getContent();

        $this->assertStringNotContainsString('bookmark-count', $html);
        $this->assertStringNotContainsString('bookmarked_by_count', $html);
        // 自分の分（1 件）だけが「付いている」表示になる。他人の分は足されない
        $this->assertSame(1, substr_count($html, self::REMOVE_LABEL));
    }

    #[Test]
    public function 未ログインではブックマークを付けられずログイン画面へ飛ぶ(): void
    {
        $this->post(route('bookmarks.store', $this->post))->assertRedirect(route('login'));

        $this->assertDatabaseCount('bookmarks', 0);
    }

    #[Test]
    public function 未ログインではブックマークを外せずログイン画面へ飛ぶ(): void
    {
        $this->post->bookmarkedBy()->attach($this->user);

        $this->delete(route('bookmarks.destroy', $this->post))->assertRedirect(route('login'));

        $this->assertDatabaseCount('bookmarks', 1);
    }

    #[Test]
    public function 存在しないポストへの付け外しは404になる(): void
    {
        $this->actingAs($this->user)->post('/posts/999999/bookmark')->assertNotFound();
        $this->actingAs($this->user)->delete('/posts/999999/bookmark')->assertNotFound();

        $this->assertDatabaseCount('bookmarks', 0);
    }

    #[Test]
    public function ポストを削除するとそのポストのブックマークも消える(): void
    {
        $this->post->bookmarkedBy()->attach([$this->user->id, $this->other->id]);

        $this->post->delete();

        $this->assertDatabaseCount('bookmarks', 0);
        $this->assertModelExists($this->user);
        $this->assertModelExists($this->other);
    }

    #[Test]
    public function ユーザーを削除するとそのユーザーのブックマークも消える(): void
    {
        $this->post->bookmarkedBy()->attach([$this->user->id, $this->other->id]);

        $this->user->delete();

        $this->assertDatabaseCount('bookmarks', 1);
        $this->assertDatabaseMissing('bookmarks', ['user_id' => $this->user->id]);
        $this->assertDatabaseHas('bookmarks', ['user_id' => $this->other->id, 'post_id' => $this->post->id]);
        $this->assertModelExists($this->post);
    }

    #[Test]
    public function 同じユーザーと同じポストの組を直接2つ入れようとすると一意制約で拒否される(): void
    {
        $this->post->bookmarkedBy()->attach($this->user);

        $this->expectException(QueryException::class);

        DB::table('bookmarks')->insert([
            'user_id' => $this->user->id,
            'post_id' => $this->post->id,
        ]);
    }

    // --- 既存の動きが変わっていないことの確認 ---

    #[Test]
    public function ブックマークを付けてもタイムラインは新しい順のまま内容とリンク先も変わらない(): void
    {
        $createdAt = now()->subMinutes(10);
        $newer = Post::factory()->for($this->user)->for($this->category)->create([
            'title' => '新しいポスト',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        // 古い方だけ付けても、並びは変わらない
        $this->bookmark();

        $this->timeline()
            ->assertOk()
            ->assertSeeInOrder(['新しいポスト', '他人のポスト'])
            ->assertSee($this->other->name)
            ->assertSee($this->category->name)
            ->assertSee('本文')
            ->assertSee(route('posts.show', $this->post), false)
            ->assertSee(route('posts.show', $newer), false);
    }

    #[Test]
    public function ブックマークを付けても全ポストがページ分けなしで出る(): void
    {
        Post::factory()->for($this->other)->for($this->category)->count(30)->create();

        $this->bookmark();

        $html = $this->timeline()->assertOk()->getContent();

        // 31 件すべてにボタンが出る（付けた 1 件は「外す」、残り 30 件は「付ける」）
        $this->assertSame(30, substr_count($html, self::ADD_LABEL));
        $this->assertSame(1, substr_count($html, self::REMOVE_LABEL));
    }

    #[Test]
    public function 他人のポストには編集と削除が出ず直接叩いても403になる(): void
    {
        $this->bookmark();

        $this->timeline()
            ->assertOk()
            ->assertSee('他人のポスト')
            ->assertDontSee(route('posts.edit', $this->post), false)
            ->assertDontSee('削除');

        $this->actingAs($this->user)->get(route('posts.edit', $this->post))->assertForbidden();
        $this->actingAs($this->user)->delete(route('posts.destroy', $this->post))->assertForbidden();
        $this->assertModelExists($this->post);
    }

    #[Test]
    public function いいねとブックマークは同じポストに付けても影響し合わない(): void
    {
        // 他のユーザーのいいねが 1 つ付いている
        $this->post->likedBy()->attach($this->other);

        $this->actingAs($this->user)->post(route('likes.store', $this->post));
        $this->bookmark();

        $this->assertDatabaseCount('likes', 2);
        $this->assertDatabaseCount('bookmarks', 1);

        $this->timeline()
            ->assertSee('いいねを取り消す')
            ->assertSee('<span class="like-count">2</span>', false)
            ->assertSee(self::ON_CLASS, false);

        // ブックマークを外してもいいねはそのまま
        $this->unbookmark();

        $this->assertDatabaseCount('likes', 2);
        $this->assertDatabaseCount('bookmarks', 0);

        $this->timeline()
            ->assertSee('いいねを取り消す')
            ->assertSee('<span class="like-count">2</span>', false)
            ->assertDontSee(self::ON_CLASS, false);
    }

    #[Test]
    public function ブックマークしても返信のリンクは今まで通り出る(): void
    {
        $this->bookmark();

        $this->timeline()
            ->assertOk()
            ->assertSee('返信')
            ->assertSee(route('posts.show', $this->post), false);
    }

    #[Test]
    public function ポスト詳細画面にはブックマークのボタンが出ない(): void
    {
        $this->post->bookmarkedBy()->attach($this->user);

        $this->actingAs($this->user)
            ->get(route('posts.show', $this->post))
            ->assertOk()
            ->assertSee('他人のポスト')
            ->assertDontSee(self::ADD_LABEL, false)
            ->assertDontSee(self::REMOVE_LABEL, false)
            ->assertDontSee('bookmark');
    }
}
