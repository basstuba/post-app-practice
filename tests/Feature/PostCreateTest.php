<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Reply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PostCreateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => '書く太郎']);
        $this->category = Category::factory()->create(['name' => '技術メモ']);
    }

    private function send(array $override = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->post(route('posts.store'), array_merge([
            'title' => '新しいタイトル',
            'category_id' => $this->category->id,
            'content' => '新しい本文',
        ], $override));
    }

    // --- 受け入れ条件 ---

    #[Test]
    public function タイムラインにポストを書く作成画面へのリンクが表示される(): void
    {
        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertOk()
            ->assertSee('ポストを書く')
            ->assertSee(route('posts.create'));
    }

    #[Test]
    public function 作成画面にタイトル_トピック_本文の入力欄と投稿ボタンが表示される(): void
    {
        $other = Category::factory()->create(['name' => '雑記']);

        $this->actingAs($this->user)->get(route('posts.create'))
            ->assertOk()
            ->assertSee('name="title"', false)
            ->assertSee('name="category_id"', false)
            ->assertSee('name="content"', false)
            ->assertSee('投稿する')
            ->assertSeeInOrder([
                '選択してください',
                $this->category->name,
                $other->name,
            ]);
    }

    #[Test]
    public function 入力して送信するとポストが増えタイムラインへ戻る(): void
    {
        $this->send()->assertRedirect(route('posts.index'));

        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseHas('posts', [
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'title' => '新しいタイトル',
            'content' => '新しい本文',
        ]);
    }

    #[Test]
    public function 送信したポストがタイムラインの先頭に表示される(): void
    {
        Post::factory()->create(['title' => '古いポスト', 'created_at' => now()->subDay()]);

        $this->send();

        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertOk()
            ->assertSeeInOrder(['新しいタイトル', '古いポスト']);
    }

    #[Test]
    public function 本文が空のまま送信するとエラーになりポストは増えない(): void
    {
        $this->send(['content' => ''])
            ->assertSessionHasErrors(['content' => '本文を入力してください。']);

        $this->assertDatabaseCount('posts', 0);
    }

    #[Test]
    public function 増えたポストに自分の名前と編集_削除の操作が出る(): void
    {
        $this->send();
        $post = Post::firstOrFail();

        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertSee('書く太郎')
            ->assertSee(route('posts.edit', $post))
            ->assertSee('name="_method" value="DELETE"', false);
    }

    #[Test]
    public function 未ログインでは作成画面を開けずログイン画面へ飛ばされる(): void
    {
        $this->get(route('posts.create'))->assertRedirect(route('login'));
    }

    #[Test]
    public function 未ログインでは送信できずポストは増えない(): void
    {
        $this->post(route('posts.store'), [
            'title' => 'タイトル',
            'category_id' => $this->category->id,
            'content' => '本文',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('posts', 0);
    }

    // --- 投稿者 ---

    #[Test]
    public function 他のユーザーのuser_idを送っても無視され投稿者は本人になる(): void
    {
        $other = User::factory()->create();

        $this->send(['user_id' => $other->id]);

        $this->assertDatabaseHas('posts', ['title' => '新しいタイトル', 'user_id' => $this->user->id]);
        $this->assertDatabaseMissing('posts', ['user_id' => $other->id]);
    }

    // --- 入力チェック ---

    #[Test]
    public function タイトルが空だとエラーになりポストは増えない(): void
    {
        $this->send(['title' => ''])
            ->assertSessionHasErrors(['title' => 'タイトルを入力してください。']);

        $this->assertDatabaseCount('posts', 0);
    }

    #[Test]
    public function タイトルは255字まで送信でき256字はエラーになる(): void
    {
        $this->send(['title' => str_repeat('あ', 255)])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('posts', 1);

        $this->send(['title' => str_repeat('あ', 256)])
            ->assertSessionHasErrors(['title' => 'タイトルは255文字以内で入力してください。']);
        $this->assertDatabaseCount('posts', 1);
    }

    #[Test]
    public function トピックを選ばずに送信するとエラーになりポストは増えない(): void
    {
        $this->send(['category_id' => ''])
            ->assertSessionHasErrors(['category_id' => 'トピックを選択してください。']);

        $this->assertDatabaseCount('posts', 0);
    }

    #[Test]
    public function 存在しないトピックのidで送信するとエラーになりポストは増えない(): void
    {
        $this->send(['category_id' => 99999])
            ->assertSessionHasErrors(['category_id' => '選択されたトピックは正しくありません。']);

        $this->assertDatabaseCount('posts', 0);
    }

    #[Test]
    public function 本文は140字まで送信でき141字はエラーになる(): void
    {
        $this->send(['content' => str_repeat('あ', 140)])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('posts', 1);

        $this->send(['content' => str_repeat('あ', 141)])
            ->assertSessionHasErrors(['content' => '本文は140字以内で入力してください。']);
        $this->assertDatabaseCount('posts', 1);
    }

    #[Test]
    public function 改行を含む本文は改行を1字として数え保存時は改行が1文字になる(): void
    {
        // ブラウザは改行を \r\n で送る。139字 + 改行1字 = 140字
        $content = str_repeat('あ', 69)."\r\n".str_repeat('い', 70);

        $this->send(['content' => $content])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('posts', [
            'content' => str_repeat('あ', 69)."\n".str_repeat('い', 70),
        ]);
    }

    #[Test]
    public function 空白だけの本文はエラーになりポストは増えない(): void
    {
        $this->send(['content' => '   '])->assertSessionHasErrors('content');

        $this->assertDatabaseCount('posts', 0);
    }

    #[Test]
    public function エラーで戻ったとき入力した内容が作成画面に残る(): void
    {
        $this->from(route('posts.create'))->actingAs($this->user)
            ->post(route('posts.store'), [
                'title' => '残るタイトル',
                'category_id' => $this->category->id,
                'content' => '',
            ])
            ->assertRedirect(route('posts.create'));

        $this->actingAs($this->user)->get(route('posts.create'))
            ->assertSee('value="残るタイトル"', false)
            ->assertSee('value="'.$this->category->id.'" selected', false);
    }

    // --- 画面 ---

    #[Test]
    public function 作成画面のパスがポスト1件の画面に取られない(): void
    {
        $this->actingAs($this->user)->get('/posts/create')
            ->assertOk()
            ->assertSee('ポストを書く');
    }

    #[Test]
    public function 作成画面の入力欄にrequired属性が付いていない(): void
    {
        $html = $this->actingAs($this->user)->get(route('posts.create'))->getContent();

        foreach (['input', 'select', 'textarea'] as $tag) {
            preg_match_all("/<{$tag}\\b[^>]*>/", $html, $m);
            $this->assertNotEmpty($m[0], "{$tag} が見つからない");

            foreach ($m[0] as $element) {
                $this->assertStringNotContainsString('required', $element);
            }
        }
    }

    #[Test]
    public function 作成画面の本文欄に残り文字数が表示される(): void
    {
        $this->actingAs($this->user)->get(route('posts.create'))
            ->assertSee('id="content-counter"', false)
            ->assertSee('残り140字');
    }

    #[Test]
    public function エラーで戻った作成画面の残り文字数は入力済みの文字数を引いた値になる(): void
    {
        $this->from(route('posts.create'))->actingAs($this->user)
            ->post(route('posts.store'), [
                'title' => '',
                'category_id' => $this->category->id,
                'content' => str_repeat('あ', 40),
            ]);

        $this->actingAs($this->user)->get(route('posts.create'))
            ->assertSee('残り100字');
    }

    // --- 既存の動きが変わっていない ---

    #[Test]
    public function 他人のポストには編集_削除が出ず返信リンクは出る(): void
    {
        $post = Post::factory()->create();

        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertSee(route('posts.show', $post))
            ->assertDontSee(route('posts.edit', $post))
            ->assertDontSee('name="_method" value="DELETE"', false);

        $this->actingAs($this->user)->get(route('posts.edit', $post))->assertForbidden();
        $this->actingAs($this->user)->delete(route('posts.destroy', $post))->assertForbidden();
    }

    #[Test]
    public function 作成したポストにリプライを送れる(): void
    {
        $this->send();
        $post = Post::firstOrFail();

        $this->actingAs($this->user)->get(route('posts.show', $post))->assertOk();

        $this->actingAs($this->user)
            ->post(route('replies.store', $post), ['content' => 'リプライ'])
            ->assertRedirect(route('posts.show', $post));

        $this->assertSame(1, Reply::where('post_id', $post->id)->count());
    }
}
