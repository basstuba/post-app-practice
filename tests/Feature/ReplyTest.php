<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Reply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReplyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->post = Post::factory()->create(['content' => 'ポストの本文です']);
    }

    private function send(string $content, ?User $as = null, ?Post $post = null)
    {
        return $this->actingAs($as ?? $this->user)
            ->post(route('replies.store', $post ?? $this->post), ['content' => $content]);
    }

    // --- 受け入れ条件 ---

    #[Test]
    public function ポスト1件の画面にポストとリプライが表示される(): void
    {
        Reply::factory()->for($this->post)->create(['content' => 'こんにちはリプライ']);

        $this->actingAs($this->user)->get(route('posts.show', $this->post))
            ->assertOk()
            ->assertSee('ポストの本文です')
            ->assertSee('こんにちはリプライ');
    }

    #[Test]
    public function 別のポストのリプライは表示されない(): void
    {
        Reply::factory()->for(Post::factory())->create(['content' => '別ポストのリプライ']);

        $this->actingAs($this->user)->get(route('posts.show', $this->post))
            ->assertOk()
            ->assertDontSee('別ポストのリプライ');
    }

    #[Test]
    public function リプライは古い順に並ぶ(): void
    {
        Reply::factory()->for($this->post)->create(['content' => '二番目', 'created_at' => now()->subMinute()]);
        Reply::factory()->for($this->post)->create(['content' => '一番目', 'created_at' => now()->subHour()]);
        Reply::factory()->for($this->post)->create(['content' => '三番目', 'created_at' => now()]);

        $this->actingAs($this->user)->get(route('posts.show', $this->post))
            ->assertSeeInOrder(['一番目', '二番目', '三番目']);
    }

    #[Test]
    public function 同じ秒のリプライはid順に並ぶ(): void
    {
        $at = now();
        Reply::factory()->for($this->post)->create(['content' => '先に入ったリプライ', 'created_at' => $at]);
        Reply::factory()->for($this->post)->create(['content' => '後に入ったリプライ', 'created_at' => $at]);

        $this->actingAs($this->user)->get(route('posts.show', $this->post))
            ->assertSeeInOrder(['先に入ったリプライ', '後に入ったリプライ']);
    }

    #[Test]
    public function リプライが無いと案内が表示される(): void
    {
        $this->actingAs($this->user)->get(route('posts.show', $this->post))
            ->assertSee('まだリプライがありません。');
    }

    #[Test]
    public function リプライがあると案内は表示されない(): void
    {
        Reply::factory()->for($this->post)->create();

        $this->actingAs($this->user)->get(route('posts.show', $this->post))
            ->assertDontSee('まだリプライがありません。');
    }

    #[Test]
    public function 本文を入れて送信するとリプライが増える(): void
    {
        $this->send('よろしくお願いします')->assertRedirect(route('posts.show', $this->post));

        $this->assertDatabaseCount('replies', 1);
        $this->assertDatabaseHas('replies', [
            'user_id' => $this->user->id,
            'post_id' => $this->post->id,
            'content' => 'よろしくお願いします',
        ]);

        $this->actingAs($this->user)->get(route('posts.show', $this->post))
            ->assertSee('よろしくお願いします');
    }

    #[Test]
    public function 本文が空だとエラーになりリプライは増えない(): void
    {
        $this->send('')->assertSessionHasErrors(['content' => '本文を入力してください。']);

        $this->assertDatabaseCount('replies', 0);
    }

    #[Test]
    public function 自分のリプライは削除できる(): void
    {
        $reply = Reply::factory()->for($this->post)->for($this->user)->create();

        $this->actingAs($this->user)->delete(route('replies.destroy', $reply))
            ->assertRedirect(route('posts.show', $this->post));

        $this->assertModelMissing($reply);
    }

    #[Test]
    public function 他人のリプライは削除できない(): void
    {
        $reply = Reply::factory()->for($this->post)->create();

        $this->actingAs($this->user)->delete(route('replies.destroy', $reply))->assertForbidden();

        $this->assertModelExists($reply);
    }

    // --- 境界・認可・その他 ---

    #[Test]
    public function 本文140字ちょうどなら送信できる(): void
    {
        $this->send(str_repeat('あ', 140))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('replies', 1);
    }

    #[Test]
    public function 本文141字だとエラーになり増えない(): void
    {
        $this->send(str_repeat('あ', 141))
            ->assertSessionHasErrors(['content' => '本文は140字以内で入力してください。']);

        $this->assertDatabaseCount('replies', 0);
    }

    #[Test]
    public function 改行を含む140字は通る(): void
    {
        // ブラウザ送信を想定して改行は \r\n。1 字として数える
        $content = str_repeat('あ', 69)."\r\n".str_repeat('い', 70);

        $this->send($content)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('replies', ['content' => str_replace("\r\n", "\n", $content)]);
    }

    #[Test]
    public function 本文に配列を送っても500にならずエラーになる(): void
    {
        $this->actingAs($this->user)
            ->post(route('replies.store', $this->post), ['content' => ['x']])
            ->assertSessionHasErrors('content');

        $this->assertDatabaseCount('replies', 0);
    }

    #[Test]
    public function 空白だけの本文はエラーになる(): void
    {
        $this->send("   \n  ")->assertSessionHasErrors('content');

        $this->assertDatabaseCount('replies', 0);
    }

    #[Test]
    public function 削除ボタンは自分のリプライにだけ出る(): void
    {
        $mine = Reply::factory()->for($this->post)->for($this->user)->create(['content' => '自分のリプライ']);
        $others = Reply::factory()->for($this->post)->create(['content' => '他人のリプライ']);

        $this->actingAs($this->user)->get(route('posts.show', $this->post))
            ->assertSee(route('replies.destroy', $mine), false)
            ->assertDontSee(route('replies.destroy', $others), false);
    }

    #[Test]
    public function ポストの持ち主でも他人のリプライは削除できない(): void
    {
        $reply = Reply::factory()->for($this->post)->create();

        $this->actingAs($this->post->user)->delete(route('replies.destroy', $reply))->assertForbidden();

        $this->assertModelExists($reply);
    }

    #[Test]
    public function 他人のポストにリプライを送れる(): void
    {
        $this->assertNotSame($this->user->id, $this->post->user_id);

        $this->send('他人のポストへ')->assertRedirect(route('posts.show', $this->post));

        $this->assertDatabaseCount('replies', 1);
    }

    #[Test]
    public function エラーで戻ると入力した本文が残っている(): void
    {
        $content = str_repeat('あ', 141);

        $this->actingAs($this->user)->from(route('posts.show', $this->post))
            ->post(route('replies.store', $this->post), ['content' => $content])
            ->assertRedirect(route('posts.show', $this->post));

        $this->actingAs($this->user)->get(route('posts.show', $this->post))
            ->assertSee($content)
            ->assertSee('本文は140字以内で入力してください。');
    }

    #[Test]
    public function 未ログインではログイン画面へ送られる(): void
    {
        $reply = Reply::factory()->for($this->post)->create();

        $this->get(route('posts.show', $this->post))->assertRedirect('/login');
        $this->post(route('replies.store', $this->post), ['content' => 'x'])->assertRedirect('/login');
        $this->delete(route('replies.destroy', $reply))->assertRedirect('/login');

        $this->assertDatabaseCount('replies', 1);
    }

    #[Test]
    public function 存在しないポストは404になる(): void
    {
        $this->actingAs($this->user)->get('/posts/9999')->assertNotFound();
        $this->actingAs($this->user)->post('/posts/9999/replies', ['content' => 'x'])->assertNotFound();

        $this->assertDatabaseCount('replies', 0);
    }

    #[Test]
    public function 存在しないリプライの削除は404になる(): void
    {
        $this->actingAs($this->user)->delete('/replies/9999')->assertNotFound();
    }

    #[Test]
    public function ポストを削除するとリプライも消える(): void
    {
        $reply = Reply::factory()->for($this->post)->create();

        $this->actingAs($this->post->user)->delete(route('posts.destroy', $this->post));

        $this->assertModelMissing($reply);
    }

    #[Test]
    public function ユーザーを削除するとそのリプライも消える(): void
    {
        $reply = Reply::factory()->for($this->post)->for($this->user)->create();

        $this->user->delete();

        $this->assertModelMissing($reply);
    }

    // --- 既存の動きが変わっていないことの確認 ---

    #[Test]
    public function タイムラインに返信リンクが出て編集削除は自分のポストだけ(): void
    {
        $mine = Post::factory()->for($this->user)->create();

        $response = $this->actingAs($this->user)->get(route('posts.index'))->assertOk();

        $response->assertSee(route('posts.show', $mine), false)
            ->assertSee(route('posts.show', $this->post), false)
            ->assertSee(route('posts.edit', $mine), false)
            ->assertDontSee(route('posts.edit', $this->post), false);
    }

    #[Test]
    public function 他人のポストは編集も削除もできない(): void
    {
        $this->actingAs($this->user)->get(route('posts.edit', $this->post))->assertForbidden();
        $this->actingAs($this->user)->delete(route('posts.destroy', $this->post))->assertForbidden();
    }

    // --- 言語設定 ---

    #[Test]
    public function 言語設定はjaになっている(): void
    {
        $this->assertSame('ja', config('app.locale'));
        $this->assertSame('en', config('app.fallback_locale'));
    }

    #[Test]
    public function ポスト編集で本文が空だと日本語のエラーが出る(): void
    {
        $post = Post::factory()->for($this->user)->create();

        $this->actingAs($this->user)->put(route('posts.update', $post), [
            'title' => 'タイトル',
            'category_id' => $post->category_id,
            'content' => '',
        ])->assertSessionHasErrors(['content' => '本文を入力してください。']);
    }

    #[Test]
    public function ユーザー登録で必須項目が空だと日本語のエラーが出て作られない(): void
    {
        $before = User::count();

        $this->post('/register', [])
            ->assertSessionHasErrors([
                'name' => '名前を入力してください。',
                'email' => 'メールアドレスを入力してください。',
                'password' => 'パスワードを入力してください。',
            ]);

        $this->assertSame($before, User::count());
    }

    #[Test]
    public function 訳していない文言でも翻訳キーがそのまま出ない(): void
    {
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');

        $this->assertStringNotContainsString('auth.', session('errors')->first('email'));
    }
}
