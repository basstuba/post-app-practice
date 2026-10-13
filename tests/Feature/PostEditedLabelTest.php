<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Reply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PostEditedLabelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Category $category;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->category = Category::factory()->create();

        // 作成から 1 時間たった、まだ編集していないポスト（updated_at は created_at と同じ）
        $createdAt = now()->subHour();
        $this->post = Post::factory()->for($this->user)->for($this->category)->create([
            'title' => '元のタイトル',
            'content' => '元の本文',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function update(array $override = [], ?User $as = null, ?Post $post = null)
    {
        return $this->actingAs($as ?? $this->user)->put(route('posts.update', $post ?? $this->post), array_merge([
            'title' => '直したタイトル',
            'category_id' => $this->category->id,
            'content' => '直した本文',
        ], $override));
    }

    // --- 受け入れ条件 ---

    #[Test]
    public function 一度も編集していないポストには編集済みが出ない(): void
    {
        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertOk()
            ->assertSee('元のタイトル')
            ->assertDontSee('編集済み');
    }

    #[Test]
    public function 自分のポストを編集して保存するとタイムラインのそのポストに編集済みが出る(): void
    {
        $this->update()->assertRedirect(route('posts.index'));

        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertOk()
            // 「編集済み」はヘッダー部（名前・時刻の並び）にあり、タイトルより前に出る
            ->assertSeeInOrder(['編集済み', '直したタイトル'])
            ->assertDontSee('元のタイトル');
    }

    // --- 境界・認可・その他 ---

    #[Test]
    public function 編集したポストにだけ編集済みが出て他のポストには出ない(): void
    {
        $other = Post::factory()->for($this->user)->for($this->category)->create([
            'title' => '触っていないポスト',
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);

        $this->update();

        $html = $this->actingAs($this->user)->get(route('posts.index'))->getContent();

        $this->assertSame(1, substr_count($html, '編集済み'));
        // 並びは新しい順。触っていない方（30 分前）が先、編集した方（1 時間前）が後
        $this->assertLessThan(strpos($html, '編集済み'), strpos($html, '触っていないポスト'));
        $this->assertLessThan(strpos($html, '直したタイトル'), strpos($html, '編集済み'));
        $this->assertModelExists($other);
    }

    #[Test]
    public function 編集済みは自分以外のユーザーのタイムラインにも出る(): void
    {
        $this->update();

        $this->actingAs(User::factory()->create())->get(route('posts.index'))
            ->assertOk()
            ->assertSee('直したタイトル')
            ->assertSee('編集済み');
    }

    #[Test]
    public function 入力エラーで保存されなかったときは編集済みが出ない(): void
    {
        $this->from(route('posts.edit', $this->post))
            ->update(['content' => ''])
            ->assertSessionHasErrors(['content' => '本文を入力してください。']);

        $this->assertDatabaseHas('posts', ['id' => $this->post->id, 'title' => '元のタイトル', 'content' => '元の本文']);

        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertOk()
            ->assertDontSee('編集済み');
    }

    #[Test]
    public function 他人のポストは編集できず編集済みも出ない(): void
    {
        $this->update(as: User::factory()->create())->assertForbidden();

        $this->assertDatabaseHas('posts', ['id' => $this->post->id, 'title' => '元のタイトル', 'content' => '元の本文']);

        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertDontSee('編集済み');
    }

    #[Test]
    public function 未ログインでは編集できず編集済みも出ない(): void
    {
        $this->put(route('posts.update', $this->post), [
            'title' => '直したタイトル',
            'category_id' => $this->category->id,
            'content' => '直した本文',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseHas('posts', ['id' => $this->post->id, 'title' => '元のタイトル']);
    }

    #[Test]
    public function リプライが付いても編集済みは出ない(): void
    {
        Reply::factory()->for($this->post)->for($this->user)->create(['content' => 'リプライです']);

        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertOk()
            ->assertDontSee('編集済み');
    }

    // --- 既存の動きが変わっていないことの確認 ---

    #[Test]
    public function 編集してもタイムラインの並び順は作成日時の新しい順のまま(): void
    {
        $newer = Post::factory()->for($this->user)->for($this->category)->create([
            'title' => '新しいポスト',
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        // 古い方を編集して updated_at を最新にしても、並びは created_at のまま
        $this->update();

        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertOk()
            ->assertSeeInOrder(['新しいポスト', '直したタイトル']);

        $this->assertModelExists($newer);
    }

    #[Test]
    public function 編集済みのポストにも返信_編集_削除のリンクが出る(): void
    {
        $this->update();

        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertOk()
            ->assertSee(route('posts.show', $this->post), false)
            ->assertSee(route('posts.edit', $this->post), false)
            ->assertSee(route('posts.destroy', $this->post), false);
    }

    #[Test]
    public function 編集済みのポストを削除するとタイムラインから消える(): void
    {
        $this->update();

        $this->actingAs($this->user)->delete(route('posts.destroy', $this->post))
            ->assertRedirect(route('posts.index'));

        $this->assertModelMissing($this->post);

        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertDontSee('直したタイトル')
            ->assertDontSee('編集済み');
    }

    #[Test]
    public function 他人の編集済みポストには編集_削除が出ない(): void
    {
        $this->update();

        $this->actingAs(User::factory()->create())->get(route('posts.index'))
            ->assertOk()
            ->assertSee('編集済み')
            ->assertDontSee(route('posts.edit', $this->post), false)
            // posts.destroy の URL は「返信」リンク（posts.show）と同じなので、URL ではなく削除フォームの有無で確かめる
            ->assertDontSee('削除')
            ->assertDontSee('value="DELETE"', false);
    }
}
