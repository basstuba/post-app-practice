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

class LikeTest extends TestCase
{
    use RefreshDatabase;

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

    private function like(?User $as = null, ?Post $post = null)
    {
        return $this->actingAs($as ?? $this->user)->post(route('likes.store', $post ?? $this->post));
    }

    private function unlike(?User $as = null, ?Post $post = null)
    {
        return $this->actingAs($as ?? $this->user)->delete(route('likes.destroy', $post ?? $this->post));
    }

    private function timeline(?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->get(route('posts.index'));
    }

    private function likeCount(int $n): string
    {
        return '<span class="like-count">'.$n.'</span>';
    }

    // --- 受け入れ条件 ---

    #[Test]
    public function タイムラインのポストそれぞれにいいねのボタンと数が出ている(): void
    {
        Post::factory()->for($this->user)->for($this->category)->create(['title' => '自分のポスト']);

        $html = $this->timeline()->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'いいねする'));
        $this->assertSame(2, substr_count($html, $this->likeCount(0)));
        $this->assertSame(0, substr_count($html, 'いいねを取り消す'));
    }

    #[Test]
    public function いいねしていないポストのボタンを押すと数が1つ増えて押した状態の見た目になる(): void
    {
        $this->like()->assertRedirect(route('posts.index'));

        $this->assertDatabaseHas('likes', ['user_id' => $this->user->id, 'post_id' => $this->post->id]);
        $this->assertDatabaseCount('likes', 1);

        $this->timeline()
            ->assertOk()
            ->assertSee('いいねを取り消す')
            ->assertDontSee('いいねする')
            ->assertSee('like-form liked', false)
            ->assertSee($this->likeCount(1), false);
    }

    #[Test]
    public function 押した状態のボタンをもう一度押すと数が1つ減って押していない見た目に戻る(): void
    {
        $this->post->likedBy()->attach($this->user);

        $this->unlike()->assertRedirect(route('posts.index'));

        $this->assertDatabaseMissing('likes', ['user_id' => $this->user->id, 'post_id' => $this->post->id]);
        $this->assertDatabaseCount('likes', 0);

        $this->timeline()
            ->assertOk()
            ->assertSee('いいねする')
            ->assertDontSee('いいねを取り消す')
            ->assertDontSee('like-form liked', false)
            ->assertSee($this->likeCount(0), false);
    }

    #[Test]
    public function 別のアカウントでは自分が押していないポストは押していない見た目のまま数だけが増えている(): void
    {
        $this->like();

        // user がログアウトして other でログインし直した想定。other は他人（自分）のポストの持ち主だが、押してはいない
        $this->timeline($this->other)
            ->assertOk()
            ->assertSee('いいねする')
            ->assertDontSee('いいねを取り消す')
            ->assertDontSee('like-form liked', false)
            ->assertSee($this->likeCount(1), false);

        // さらに別のアカウントでも同じ
        $this->timeline(User::factory()->create())
            ->assertSee('いいねする')
            ->assertDontSee('いいねを取り消す')
            ->assertSee($this->likeCount(1), false);
    }

    // --- 境界・認可・その他 ---

    #[Test]
    public function 自分のポストにもいいねできる(): void
    {
        $mine = Post::factory()->for($this->user)->for($this->category)->create(['title' => '自分のポスト']);

        $this->like(post: $mine)->assertRedirect(route('posts.index'));

        $this->assertDatabaseHas('likes', ['user_id' => $this->user->id, 'post_id' => $mine->id]);
    }

    #[Test]
    public function 数はそのポストに付いた全ユーザー分の合計になる(): void
    {
        $this->like();
        $this->like($this->other);
        $this->like(User::factory()->create());

        $this->assertDatabaseCount('likes', 3);

        $this->timeline()->assertSee($this->likeCount(3), false);
    }

    #[Test]
    public function 押したポストだけ押した状態になり他のポストは押していない見た目のまま(): void
    {
        $createdAt = now()->subMinutes(10);
        $newer = Post::factory()->for($this->other)->for($this->category)->create([
            'title' => '新しいポスト',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        // 古い方（他人のポスト）だけ押す
        $this->like();

        $html = $this->timeline()->getContent();

        $this->assertSame(1, substr_count($html, 'いいねを取り消す'));
        $this->assertSame(1, substr_count($html, 'いいねする'));
        // 並びは新しい順。新しい方（押していない・0）が先、古い方（押している・1）が後
        $this->assertSame(1, substr_count($html, $this->likeCount(1)));
        $this->assertSame(1, substr_count($html, $this->likeCount(0)));
        $this->assertLessThan(strpos($html, 'いいねを取り消す'), strpos($html, 'いいねする'));
        $this->assertLessThan(strpos($html, $this->likeCount(1)), strpos($html, $this->likeCount(0)));
        $this->assertModelExists($newer);
    }

    #[Test]
    public function 続けて2回押しても同じユーザーのいいねは1つのまま(): void
    {
        $this->like();
        $this->like()->assertRedirect(route('posts.index'));

        $this->assertDatabaseCount('likes', 1);
        $this->timeline()->assertSee($this->likeCount(1), false);
    }

    #[Test]
    public function 押していないポストの取り消しを送っても何も起きない(): void
    {
        $this->post->likedBy()->attach($this->other);

        $this->unlike()->assertRedirect(route('posts.index'));

        // 他人のいいねは消えず、数も変わらない
        $this->assertDatabaseCount('likes', 1);
        $this->assertDatabaseHas('likes', ['user_id' => $this->other->id, 'post_id' => $this->post->id]);
    }

    #[Test]
    public function 取り消しても他のユーザーのいいねは残る(): void
    {
        $this->post->likedBy()->attach([$this->user->id, $this->other->id]);

        $this->unlike();

        $this->assertDatabaseCount('likes', 1);
        $this->assertDatabaseHas('likes', ['user_id' => $this->other->id, 'post_id' => $this->post->id]);
        $this->timeline()->assertSee($this->likeCount(1), false);
    }

    #[Test]
    public function 同じユーザーと同じポストの組を直接2つ入れようとすると一意制約で拒否される(): void
    {
        $this->post->likedBy()->attach($this->user);

        $this->expectException(QueryException::class);

        DB::table('likes')->insert([
            'user_id' => $this->user->id,
            'post_id' => $this->post->id,
        ]);
    }

    #[Test]
    public function 同時に送られて先に別のリクエストが登録済みでも500にならずいいねは1つのまま(): void
    {
        // 自分の INSERT の直前に、別のリクエストが同じ組を入れ終えた状況を再現する
        $raced = false;
        DB::beforeExecuting(function (string $query) use (&$raced) {
            if ($raced || ! str_starts_with($query, 'insert') || ! str_contains($query, '`likes`')) {
                return;
            }

            $raced = true;
            DB::table('likes')->insert([
                'user_id' => $this->user->id,
                'post_id' => $this->post->id,
            ]);
        });

        $this->like()->assertRedirect(route('posts.index'));

        $this->assertTrue($raced);
        $this->assertDatabaseCount('likes', 1);
        $this->assertDatabaseHas('likes', ['user_id' => $this->user->id, 'post_id' => $this->post->id]);
    }

    #[Test]
    public function 未ログインではいいねできずログイン画面へ飛ぶ(): void
    {
        $this->post(route('likes.store', $this->post))->assertRedirect(route('login'));

        $this->assertDatabaseCount('likes', 0);
    }

    #[Test]
    public function 未ログインでは取り消しもできずログイン画面へ飛ぶ(): void
    {
        $this->post->likedBy()->attach($this->user);

        $this->delete(route('likes.destroy', $this->post))->assertRedirect(route('login'));

        $this->assertDatabaseCount('likes', 1);
    }

    #[Test]
    public function 存在しないポストへのいいねと取り消しは404になる(): void
    {
        $this->actingAs($this->user)->post('/posts/999999/like')->assertNotFound();
        $this->actingAs($this->user)->delete('/posts/999999/like')->assertNotFound();

        $this->assertDatabaseCount('likes', 0);
    }

    #[Test]
    public function ユーザーを削除するとそのユーザーのいいねも消える(): void
    {
        $this->post->likedBy()->attach([$this->user->id, $this->other->id]);

        $this->user->delete();

        $this->assertDatabaseCount('likes', 1);
        $this->assertDatabaseMissing('likes', ['user_id' => $this->user->id]);
        $this->assertDatabaseHas('likes', ['user_id' => $this->other->id, 'post_id' => $this->post->id]);
    }

    #[Test]
    public function ポストを削除するとそのポストのいいねも消える(): void
    {
        $this->post->likedBy()->attach([$this->user->id, $this->other->id]);

        $this->post->delete();

        $this->assertDatabaseCount('likes', 0);
        $this->assertModelExists($this->user);
        $this->assertModelExists($this->other);
    }

    // --- 既存の動きが変わっていないことの確認 ---

    #[Test]
    public function いいねしてもタイムラインの並び順は作成日時の新しい順のまま(): void
    {
        $createdAt = now()->subMinutes(10);
        Post::factory()->for($this->other)->for($this->category)->create([
            'title' => '新しいポスト',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        // 古い方だけ押しても、並びは変わらない
        $this->like();

        $this->timeline()
            ->assertOk()
            ->assertSeeInOrder(['新しいポスト', '他人のポスト']);
    }

    #[Test]
    public function いいねしても全ポストがページ分けなしで出る(): void
    {
        Post::factory()->for($this->other)->for($this->category)->count(30)->create();

        $this->like();

        $html = $this->timeline()->assertOk()->getContent();

        // 31 件すべてにボタンが出る（押した 1 件は「取り消す」、残り 30 件は「する」）
        $this->assertSame(30, substr_count($html, 'いいねする'));
        $this->assertSame(1, substr_count($html, 'いいねを取り消す'));
    }

    #[Test]
    public function いいねしてもポストの内容と編集済みの目印は変わらない(): void
    {
        $this->like();

        $this->assertDatabaseHas('posts', ['id' => $this->post->id, 'title' => '他人のポスト', 'content' => '本文']);

        $this->timeline()
            ->assertOk()
            ->assertSee('他人のポスト')
            ->assertDontSee('編集済み');
    }

    #[Test]
    public function 編集済みのポストにもいいねでき編集済みの目印はそのまま出る(): void
    {
        $edited = Post::factory()->for($this->other)->for($this->category)->create([
            'title' => '編集したポスト',
            // 作成の 4 分後に更新された（updated_at が created_at より後 = 編集済み）
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinute(),
        ]);

        $this->like(post: $edited);

        $html = $this->timeline()->getContent();

        $this->assertSame(1, substr_count($html, '編集済み'));
        $this->assertLessThan(strpos($html, '編集したポスト'), strpos($html, '編集済み'));
    }

    #[Test]
    public function いいねしても返信_編集_削除のリンクは今まで通り出る(): void
    {
        $mine = Post::factory()->for($this->user)->for($this->category)->create(['title' => '自分のポスト']);

        $this->like(post: $mine);

        $this->timeline()
            ->assertOk()
            ->assertSee(route('posts.show', $mine), false)
            ->assertSee(route('posts.edit', $mine), false)
            ->assertSee(route('posts.destroy', $mine), false)
            ->assertSee(route('posts.show', $this->post), false);
    }

    #[Test]
    public function 他人のポストにいいねしても編集_削除は出ない(): void
    {
        $this->like();

        $this->timeline()
            ->assertOk()
            ->assertSee('他人のポスト')
            ->assertDontSee(route('posts.edit', $this->post), false)
            ->assertDontSee('削除');
    }

    #[Test]
    public function いいねが付いたポストも編集と削除は今まで通りの権限で動く(): void
    {
        $mine = Post::factory()->for($this->user)->for($this->category)->create(['title' => '自分のポスト']);
        $mine->likedBy()->attach($this->other);

        // 他人は編集も削除もできず、いいねも消えない
        $this->actingAs($this->other)->delete(route('posts.destroy', $mine))->assertForbidden();
        $this->assertModelExists($mine);
        $this->assertDatabaseCount('likes', 1);

        // 持ち主は削除できる
        $this->actingAs($this->user)->delete(route('posts.destroy', $mine))->assertRedirect(route('posts.index'));
        $this->assertModelMissing($mine);
    }
}
