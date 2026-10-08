<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostContentLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Post $post;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->category = Category::create(['name' => 'お知らせ']);
        $this->post = Post::create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'title' => 'タイトル',
            'content' => '元の本文',
        ]);
    }

    private function update(string $content, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->put(route('posts.update', $this->post), [
            'title' => 'タイトル',
            'category_id' => $this->category->id,
            'content' => $content,
        ]);
    }

    public function test_本文140字ちょうどなら更新できる(): void
    {
        $content = str_repeat('あ', 140);

        $this->update($content)->assertRedirect(route('posts.index'));

        $this->assertSame($content, $this->post->fresh()->content);
    }

    public function test_本文141字だとエラーになり保存されない(): void
    {
        $this->update(str_repeat('あ', 141))
            ->assertSessionHasErrors(['content' => '本文は140字以内で入力してください。']);

        $this->assertSame('元の本文', $this->post->fresh()->content);
    }

    public function test_改行を含む140字は通る(): void
    {
        // ブラウザ送信を想定して改行は \r\n。1 字として数える
        $content = str_repeat('あ', 69)."\r\n".str_repeat('い', 70);

        $this->update($content)->assertRedirect(route('posts.index'));

        $this->assertSame(str_replace("\r\n", "\n", $content), $this->post->fresh()->content);
    }

    public function test_本文に配列を送っても500にならずエラーになる(): void
    {
        $this->actingAs($this->user)->put(route('posts.update', $this->post), [
            'title' => 'タイトル',
            'category_id' => $this->category->id,
            'content' => ['x'],
        ])->assertSessionHasErrors('content');

        $this->assertSame('元の本文', $this->post->fresh()->content);
    }

    public function test_他人の投稿は更新できない(): void
    {
        $this->update('更新', User::factory()->create())->assertForbidden();

        $this->assertSame('元の本文', $this->post->fresh()->content);
    }

    public function test_編集画面に残り文字数が表示される(): void
    {
        // 「元の本文」は4字
        $this->actingAs($this->user)->get(route('posts.edit', $this->post))
            ->assertOk()
            ->assertSee('残り136字');
    }

    public function test_超過して更新に失敗した編集画面にエラーとオーバー表示が出る(): void
    {
        $this->actingAs($this->user)->from(route('posts.edit', $this->post))
            ->put(route('posts.update', $this->post), [
                'title' => 'タイトル',
                'category_id' => $this->category->id,
                'content' => str_repeat('あ', 142),
            ]);

        $this->actingAs($this->user)->get(route('posts.edit', $this->post))
            ->assertSee('本文は140字以内で入力してください。')
            ->assertSee('2字オーバー');
    }
}
