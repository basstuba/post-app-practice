<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Reply;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TagTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Category $category;

    /** @var \Illuminate\Support\Collection<int, Tag> id 順の 5 件（Laravel / 雑談 / 暮らし / つまずき / 質問） */
    private $tags;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->category = Category::factory()->create();

        $this->seed(TagSeeder::class);
        $this->tags = Tag::orderBy('id')->get();
    }

    private function send(array $override = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->post(route('posts.store'), array_merge([
            'title' => '新しいタイトル',
            'category_id' => $this->category->id,
            'content' => '新しい本文',
        ], $override));
    }

    private function update(Post $post, array $override = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->put(route('posts.update', $post), array_merge([
            'title' => $post->title,
            'category_id' => $post->category_id,
            'content' => $post->content,
        ], $override));
    }

    /** 自分のポストを作り、渡したタグ（$this->tags の添字）を付ける */
    private function postWithTags(array $tagIndexes = [], array $attributes = []): Post
    {
        $post = Post::factory()->for($this->user)->for($this->category)->create($attributes);
        $post->tags()->sync(collect($tagIndexes)->map(fn ($i) => $this->tags[$i]->id)->all());

        return $post;
    }

    private function tagIdsOf(Post $post): array
    {
        return $post->tags()->pluck('tags.id')->map(fn ($id) => (int) $id)->all();
    }

    // --- 受け入れ条件 ---

    #[Test]
    public function 作成画面にタグ5件がチェックボックスで並ぶ(): void
    {
        $this->actingAs($this->user)->get(route('posts.create'))
            ->assertOk()
            ->assertSeeInOrder(['Laravel', '雑談', '暮らし', 'つまずき', '質問'])
            ->assertSee('name="tags[]"', false)
            ->assertSee('type="checkbox"', false);
    }

    #[Test]
    public function 作成画面でタグを2つ選んで保存するとその2件だけが付く(): void
    {
        $this->send(['tags' => [$this->tags[0]->id, $this->tags[3]->id]])
            ->assertRedirect(route('posts.index'));

        $post = Post::firstWhere('title', '新しいタイトル');
        $this->assertSame([$this->tags[0]->id, $this->tags[3]->id], $this->tagIdsOf($post));
        $this->assertDatabaseCount('post_tag', 2);
    }

    #[Test]
    public function 編集画面で付いているタグにだけチェックが入る(): void
    {
        $post = $this->postWithTags([1, 4]);

        $html = $this->actingAs($this->user)->get(route('posts.edit', $post))
            ->assertOk()
            ->getContent();

        foreach ($this->tags as $i => $tag) {
            $pattern = '/<input type="checkbox" name="tags\[\]" value="'.$tag->id.'"( checked)?/';
            preg_match($pattern, $html, $m);
            $this->assertNotEmpty($m, "タグ{$tag->id}のチェックボックスが無い");
            $this->assertSame(in_array($i, [1, 4], true), isset($m[1]), "タグ{$tag->name}のチェック状態");
        }
    }

    #[Test]
    public function 編集画面でタグを付け替えて保存できる(): void
    {
        $post = $this->postWithTags([0, 1]);

        $this->update($post, ['tags' => [$this->tags[1]->id, $this->tags[2]->id]])
            ->assertRedirect(route('posts.index'));

        $this->assertSame([$this->tags[1]->id, $this->tags[2]->id], $this->tagIdsOf($post));
    }

    #[Test]
    public function タイムラインのポストに付いているタグが出る(): void
    {
        $this->postWithTags([0, 2]);

        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertOk()
            ->assertSeeInOrder(['#Laravel', '#暮らし'])
            ->assertSee('post-tags');
    }

    #[Test]
    public function タグが付いていないポストだけだとタグの並びが出ない(): void
    {
        $this->postWithTags([]);

        // CSS のクラス名と区別するため、要素の属性として出ているかを見る
        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertOk()
            ->assertDontSee('<div class="post-tags">', false);
    }

    #[Test]
    public function タグで絞り込むとそのタグが付いたポストだけが新しい順に並ぶ(): void
    {
        $this->postWithTags([0], ['title' => '古い該当', 'created_at' => now()->subDay()]);
        $this->postWithTags([0, 1], ['title' => '新しい該当', 'created_at' => now()]);
        $this->postWithTags([1], ['title' => '別タグのポスト']);
        $this->postWithTags([], ['title' => 'タグなしのポスト']);

        $this->actingAs($this->user)->get(route('posts.index', ['tag' => $this->tags[0]->id]))
            ->assertOk()
            ->assertSeeInOrder(['新しい該当', '古い該当'])
            ->assertDontSee('別タグのポスト')
            ->assertDontSee('タグなしのポスト');
    }

    #[Test]
    public function 他人のポストの編集画面を開くと403になる(): void
    {
        $other = Post::factory()->for(User::factory())->create();

        $this->actingAs($this->user)->get(route('posts.edit', $other))->assertForbidden();
    }

    #[Test]
    public function 他人のポストのタグは更新できず元のまま(): void
    {
        $owner = User::factory()->create();
        $post = Post::factory()->for($owner)->for($this->category)->create(['title' => '元のタイトル', 'content' => '元の本文']);
        $post->tags()->sync([$this->tags[0]->id]);

        $this->update($post, ['title' => '書き換え', 'content' => '書き換え本文', 'tags' => [$this->tags[1]->id]])
            ->assertForbidden();

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => '元のタイトル', 'content' => '元の本文']);
        $this->assertSame([$this->tags[0]->id], $this->tagIdsOf($post));
    }

    // --- 境界・認可・その他 ---

    #[Test]
    public function タグを選ばずにポストを作れる(): void
    {
        $this->send()->assertRedirect(route('posts.index'));

        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseCount('post_tag', 0);
    }

    #[Test]
    public function タグを全件選んで保存すると5件とも付く(): void
    {
        $this->send(['tags' => $this->tags->pluck('id')->all()])->assertRedirect(route('posts.index'));

        $this->assertSame($this->tags->pluck('id')->all(), $this->tagIdsOf(Post::first()));
    }

    #[Test]
    public function タグを全部外して保存すると0件になりタグ自体は残る(): void
    {
        $post = $this->postWithTags([0, 1]);

        $this->update($post)->assertRedirect(route('posts.index'));

        $this->assertSame([], $this->tagIdsOf($post));
        $this->assertDatabaseCount('tags', 5);
    }

    #[Test]
    public function 存在しないタグのidを送って作成するとエラーになりポストは増えない(): void
    {
        $this->send(['tags' => [999999]])
            ->assertSessionHasErrors(['tags.0' => '選択されたタグは正しくありません。']);

        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('post_tag', 0);
    }

    #[Test]
    public function 存在しないタグのidを送って更新するとエラーになり元のまま(): void
    {
        $post = $this->postWithTags([0], ['title' => '元のタイトル']);

        $this->update($post, ['title' => '変えたタイトル', 'tags' => [999999]])
            ->assertSessionHasErrors(['tags.0' => '選択されたタグは正しくありません。']);

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => '元のタイトル']);
        $this->assertSame([$this->tags[0]->id], $this->tagIdsOf($post));
    }

    #[Test]
    public function 同じタグを2回送るとエラーになり保存されない(): void
    {
        $id = $this->tags[0]->id;

        $this->send(['tags' => [$id, $id]])
            ->assertSessionHasErrors(['tags.0' => 'タグが重複しています。']);

        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('post_tag', 0);
    }

    #[Test]
    public function tagsが配列でないと日本語のエラーになり保存されない(): void
    {
        $this->send(['tags' => '1'])
            ->assertSessionHasErrors(['tags' => 'タグの形式が正しくありません。']);

        $this->assertDatabaseCount('posts', 0);
    }

    #[Test]
    public function tagsに空文字を送って作成するとタグ0件として保存される(): void
    {
        $this->send(['tags' => ''])->assertRedirect(route('posts.index'));

        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseCount('post_tag', 0);
    }

    #[Test]
    public function tagsに空文字を送って更新するとタグが0件になりタグ自体は残る(): void
    {
        $post = $this->postWithTags([0, 1], ['title' => '元のタイトル']);

        $this->update($post, ['title' => '変えたタイトル', 'tags' => ''])
            ->assertRedirect(route('posts.index'));

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => '変えたタイトル']);
        $this->assertDatabaseCount('post_tag', 0);
        $this->assertDatabaseCount('tags', 5);
    }

    #[Test]
    public function tagsの要素が整数でないと日本語のエラーになり保存されない(): void
    {
        $this->send(['tags' => ['abc']])
            ->assertSessionHasErrors(['tags.0' => 'タグは整数で入力してください。']);

        $this->assertDatabaseCount('posts', 0);
    }

    #[Test]
    public function tagsの要素が入れ子の配列でも500にならずエラーになる(): void
    {
        $this->send(['tags' => [[1, 2]]])
            ->assertSessionHasErrors(['tags.0' => 'タグは整数で入力してください。']);

        $this->assertDatabaseCount('posts', 0);
    }

    #[Test]
    public function エラーで戻った作成画面に入力内容と選んだタグが残る(): void
    {
        $this->actingAs($this->user)->from(route('posts.create'))
            ->post(route('posts.store'), [
                'title' => '残るタイトル',
                'category_id' => $this->category->id,
                'content' => '',
                'tags' => [$this->tags[1]->id, $this->tags[2]->id],
            ])
            ->assertRedirect(route('posts.create'));

        $html = $this->actingAs($this->user)->get(route('posts.create'))->getContent();

        $this->assertStringContainsString('残るタイトル', $html);
        $this->assertStringContainsString('本文を入力してください。', $html);
        foreach ($this->tags as $i => $tag) {
            preg_match('/<input type="checkbox" name="tags\[\]" value="'.$tag->id.'"( checked)?/', $html, $m);
            $this->assertSame(in_array($i, [1, 2], true), isset($m[1]), "タグ{$tag->name}のチェック状態");
        }
    }

    #[Test]
    public function タグのエラーが作成画面に日本語で出る(): void
    {
        $this->actingAs($this->user)->from(route('posts.create'))
            ->post(route('posts.store'), [
                'title' => 'タイトル',
                'category_id' => $this->category->id,
                'content' => '本文',
                'tags' => [999999],
            ]);

        $this->actingAs($this->user)->get(route('posts.create'))
            ->assertSee('選択されたタグは正しくありません。')
            ->assertDontSee('validation.');
    }

    #[Test]
    public function 編集画面でタグを全部外しほかの項目でエラーになっても元のタグは復活しない(): void
    {
        $post = $this->postWithTags([0, 1]);

        $this->actingAs($this->user)->from(route('posts.edit', $post))
            ->put(route('posts.update', $post), [
                'title' => '',
                'category_id' => $post->category_id,
                'content' => $post->content,
            ])
            ->assertRedirect(route('posts.edit', $post));

        $html = $this->actingAs($this->user)->get(route('posts.edit', $post))->getContent();

        $this->assertStringContainsString('タイトルを入力してください。', $html);
        $this->assertStringNotContainsString(' checked', $html);
        $this->assertSame([$this->tags[0]->id, $this->tags[1]->id], $this->tagIdsOf($post));
    }

    #[Test]
    public function 未ログインだとログイン画面へ送られDBは変わらない(): void
    {
        $post = $this->postWithTags([0]);

        $this->get(route('posts.create'))->assertRedirect(route('login'));
        $this->post(route('posts.store'), [
            'title' => 'x', 'category_id' => $this->category->id, 'content' => 'x', 'tags' => [$this->tags[1]->id],
        ])->assertRedirect(route('login'));
        $this->get(route('posts.edit', $post))->assertRedirect(route('login'));
        $this->put(route('posts.update', $post), [
            'title' => 'x', 'category_id' => $this->category->id, 'content' => 'x', 'tags' => [$this->tags[1]->id],
        ])->assertRedirect(route('login'));
        $this->get(route('posts.index', ['tag' => $this->tags[0]->id]))->assertRedirect(route('login'));

        $this->assertDatabaseCount('posts', 1);
        $this->assertSame([$this->tags[0]->id], $this->tagIdsOf($post));
    }

    #[Test]
    public function タイムライン先頭にすべてとタグ5件のリンクが並ぶ(): void
    {
        $html = $this->actingAs($this->user)->get(route('posts.index'))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('posts.index').'"', $html);
        foreach ($this->tags as $tag) {
            $this->assertStringContainsString('href="'.route('posts.index', ['tag' => $tag->id]).'"', $html);
        }
        $this->assertStringContainsString('すべて', $html);
    }

    #[Test]
    public function 絞り込み中は選んでいるタグだけが強調される(): void
    {
        $this->postWithTags([0, 1]);

        $html = $this->actingAs($this->user)->get(route('posts.index', ['tag' => $this->tags[1]->id]))
            ->assertOk()
            ->assertSee('#Laravel')
            ->getContent();

        // <style> 内のセレクタ a[aria-current] を数えないよう、属性値つきで数える
        $this->assertSame(1, substr_count($html, 'aria-current="true"'));
        $this->assertMatchesRegularExpression(
            '/href="[^"]*tag='.$this->tags[1]->id.'"\s+aria-current="true"/',
            $html
        );
    }

    #[Test]
    public function 各ポストのタグは絞り込みのリンクになっている(): void
    {
        $this->postWithTags([2]);

        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertSee('<a href="'.route('posts.index', ['tag' => $this->tags[2]->id]).'">#暮らし</a>', false);
    }

    #[Test]
    public function 絞り込んで0件だと該当なしの案内が出る(): void
    {
        $this->postWithTags([0]);

        $this->actingAs($this->user)->get(route('posts.index', ['tag' => $this->tags[4]->id]))
            ->assertOk()
            ->assertSee('このタグのポストはありません。')
            ->assertDontSee('まだポストがありません。');
    }

    #[Test]
    public function 不正なtagの値は404になる(): void
    {
        $this->actingAs($this->user);

        $this->get(route('posts.index', ['tag' => 999999]))->assertNotFound();
        $this->get(route('posts.index', ['tag' => 'abc']))->assertNotFound();
        $this->get(route('posts.index', ['tag' => '1.5']))->assertNotFound();
        $this->get(route('posts.index', ['tag' => -1]))->assertNotFound();
        $this->get('/posts?tag[]=1')->assertNotFound();
    }

    #[Test]
    public function tagが空なら絞り込みなしで全ポストが出る(): void
    {
        $this->postWithTags([0], ['title' => 'ポストその一']);
        $this->postWithTags([], ['title' => 'ポストその二']);

        $this->actingAs($this->user)->get('/posts?tag=')
            ->assertOk()
            ->assertSee('ポストその一')
            ->assertSee('ポストその二');
    }

    #[Test]
    public function ポスト詳細にタグが表示だけされる(): void
    {
        $owner = User::factory()->create();
        $post = Post::factory()->for($owner)->for($this->category)->create();
        $post->tags()->sync([$this->tags[0]->id, $this->tags[3]->id]);

        $this->actingAs($this->user)->get(route('posts.show', $post))
            ->assertOk()
            ->assertSeeInOrder(['#Laravel', '#つまずき'])
            ->assertDontSee('name="tags[]"', false)
            ->assertDontSee('?tag=', false);
    }

    #[Test]
    public function ポスト詳細でタグが無いとタグの要素が出ない(): void
    {
        $post = $this->postWithTags([]);

        $this->actingAs($this->user)->get(route('posts.show', $post))
            ->assertOk()
            ->assertDontSee('<div class="post-tags">', false);
    }

    #[Test]
    public function タグだけを変えて更新しても編集済みは付かない(): void
    {
        $at = now()->subHour();
        $post = $this->postWithTags([0], ['created_at' => $at, 'updated_at' => $at]);

        $this->update($post, ['tags' => [$this->tags[1]->id]])->assertRedirect(route('posts.index'));

        $this->assertFalse($post->fresh()->isEdited());
        $this->actingAs($this->user)->get(route('posts.index'))->assertDontSee('編集済み');
    }

    #[Test]
    public function タイトルも変えて更新すると編集済みが付く(): void
    {
        $at = now()->subHour();
        $post = $this->postWithTags([0], ['created_at' => $at, 'updated_at' => $at]);

        $this->update($post, ['title' => '変えたタイトル', 'tags' => [$this->tags[1]->id]]);

        $this->assertTrue($post->fresh()->isEdited());
    }

    #[Test]
    public function ポストを削除するとpost_tagの行が消えタグは残る(): void
    {
        $post = $this->postWithTags([0, 1]);

        $this->actingAs($this->user)->delete(route('posts.destroy', $post))->assertRedirect(route('posts.index'));

        $this->assertDatabaseCount('post_tag', 0);
        $this->assertDatabaseCount('tags', 5);
    }

    #[Test]
    public function ユーザーを削除するとポストとpost_tagの行が消える(): void
    {
        $this->postWithTags([0, 1]);

        $this->user->delete();

        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('post_tag', 0);
    }

    #[Test]
    public function タグを削除するとpost_tagの行が消えポストは残る(): void
    {
        $post = $this->postWithTags([0, 1]);

        $this->tags[0]->delete();

        $this->assertDatabaseCount('post_tag', 1);
        $this->assertModelExists($post);
    }

    #[Test]
    public function TagSeederは5件を仕様どおりの名前で入れ他のデータは増やさない(): void
    {
        $this->assertSame(['Laravel', '雑談', '暮らし', 'つまずき', '質問'], Tag::orderBy('id')->pluck('name')->all());
        // id の値は見ない。ロールバックしても AUTO_INCREMENT は戻らず、1〜5 になるとは限らない
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('posts', 0);
    }

    #[Test]
    public function TagSeederを2回実行しても5件のまま(): void
    {
        $this->seed(TagSeeder::class);

        $this->assertDatabaseCount('tags', 5);
    }

    #[Test]
    public function DatabaseSeederでタグが入りpost_tagは0件(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('tags', 5);
        $this->assertDatabaseCount('post_tag', 0);
        // setUp で作った分（ユーザー 1・トピック 1）に、シーダーの分（ユーザー 7・トピック 3・ポスト 25）が足される
        $this->assertDatabaseCount('users', 8);
        $this->assertDatabaseCount('categories', 4);
        $this->assertDatabaseCount('posts', 25);
    }

    // --- 既存の動きが変わっていないことの確認 ---

    #[Test]
    public function tagなしのタイムラインは全ポストが新しい順に並ぶ(): void
    {
        $this->postWithTags([0], ['title' => '二番目のポスト', 'content' => '二番目の本文', 'created_at' => now()->subHour()]);
        $this->postWithTags([], ['title' => '一番目のポスト', 'content' => '一番目の本文', 'created_at' => now()]);
        $this->postWithTags([1], ['title' => '三番目のポスト', 'content' => '三番目の本文', 'created_at' => now()->subDay()]);

        $first = Post::firstWhere('title', '一番目のポスト');

        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertOk()
            ->assertSeeInOrder(['一番目のポスト', '二番目のポスト', '三番目のポスト'])
            ->assertSee('一番目の本文')
            ->assertSee($this->category->name)
            ->assertSee('href="'.route('posts.show', $first).'"', false);
    }

    #[Test]
    public function タイムラインは26件以上でもページ分けされない(): void
    {
        Post::factory()->for($this->user)->for($this->category)->count(60)->create();

        $html = $this->actingAs($this->user)->get(route('posts.index'))->getContent();

        $this->assertSame(60, substr_count($html, '<article class="post">'));
    }

    #[Test]
    public function 他人のポストには編集と削除が出ないがいいねは出る(): void
    {
        $other = Post::factory()->for(User::factory())->for($this->category)->create();
        $other->tags()->sync([$this->tags[0]->id]);

        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertOk()
            ->assertDontSee(route('posts.edit', $other), false)
            ->assertDontSee('>削除<', false)
            ->assertSee('いいねする');
    }

    #[Test]
    public function 絞り込み中も自分のポストにだけ編集と削除が出て絞り込みの有無で変わらない(): void
    {
        $mine = $this->postWithTags([0]);
        $other = Post::factory()->for(User::factory())->for($this->category)->create();
        $other->tags()->sync([$this->tags[0]->id]);

        $this->actingAs($this->user)->get(route('posts.index', ['tag' => $this->tags[0]->id]))
            ->assertOk()
            ->assertSee(route('posts.edit', $mine), false)
            ->assertDontSee(route('posts.edit', $other), false)
            ->assertSee('いいねする');
    }

    #[Test]
    public function タグを付けずにポストを作る今までの操作とトピック必須の文言は変わらない(): void
    {
        $this->send()->assertRedirect(route('posts.index'));
        $this->assertDatabaseCount('posts', 1);

        $this->send(['category_id' => ''])
            ->assertSessionHasErrors(['category_id' => 'トピックを選択してください。']);
        $this->assertDatabaseCount('posts', 1);
    }

    #[Test]
    public function タイトル255字と本文140字は通り1字超過はエラーになる(): void
    {
        $this->send(['title' => str_repeat('あ', 255), 'content' => str_repeat('あ', 140)])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('posts', 1);

        $this->send(['title' => str_repeat('あ', 256)])
            ->assertSessionHasErrors(['title' => 'タイトルは255文字以内で入力してください。']);
        $this->send(['content' => str_repeat('あ', 141)])
            ->assertSessionHasErrors(['content' => '本文は140字以内で入力してください。']);
        $this->assertDatabaseCount('posts', 1);
    }

    #[Test]
    public function 編集でタイトル本文トピックを更新でき編集済みが付く(): void
    {
        $at = now()->subHour();
        $post = $this->postWithTags([], ['title' => '元', 'content' => '元の本文', 'created_at' => $at, 'updated_at' => $at]);
        $newCategory = Category::factory()->create();

        $this->update($post, ['title' => '新', 'content' => '新しい本文', 'category_id' => $newCategory->id])
            ->assertRedirect(route('posts.index'));

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => '新', 'content' => '新しい本文', 'category_id' => $newCategory->id]);
        $this->assertTrue($post->fresh()->isEdited());
    }

    #[Test]
    public function 他人のポストの削除は今までどおり403(): void
    {
        $other = Post::factory()->for(User::factory())->create();

        $this->actingAs($this->user)->delete(route('posts.destroy', $other))->assertForbidden();

        $this->assertModelExists($other);
    }

    #[Test]
    public function ポスト詳細のリプライは今までどおり表示と送信ができる(): void
    {
        $post = $this->postWithTags([0]);
        Reply::factory()->for($post)->create(['content' => '既存のリプライ']);

        $this->actingAs($this->user)->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee('既存のリプライ');

        $this->actingAs($this->user)
            ->post(route('replies.store', $post), ['content' => '新しいリプライ'])
            ->assertRedirect();

        $this->assertDatabaseHas('replies', ['post_id' => $post->id, 'content' => '新しいリプライ']);
    }
}
