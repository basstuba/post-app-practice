<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Reply;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PostSearchTest extends TestCase
{
    use RefreshDatabase;

    private const NO_MATCH = '条件に合うポストはありません。';

    private const NO_POST = 'まだポストがありません。';

    private const NO_TAG_POST = 'このタグのポストはありません。';

    private const CLEAR_LINK = '条件をクリア';

    private const ADD_BOOKMARK_LABEL = 'aria-label="ブックマークする"';

    private User $user;

    private User $other;

    private Category $notice;

    private Category $tech;

    protected function setUp(): void
    {
        parent::setUp();

        // 名前・メールは画面に出る。特別な文字の検索などに紛れないよう固定する
        $this->user = User::factory()->create(['name' => '検索する人', 'email' => 'searcher@example.com']);
        $this->other = User::factory()->create(['name' => '投稿する人', 'email' => 'poster@example.com']);
        $this->notice = Category::factory()->create(['name' => 'お知らせ']);
        $this->tech = Category::factory()->create(['name' => '技術メモ']);
    }

    /** タイムラインに絞り込み条件を付けて開く */
    private function search(array $query = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->get(route('posts.index', $query));
    }

    /** 入力エラーのとき、検索欄のある一覧に戻ってくる状況（from() で戻り先を指定）で開く */
    private function searchFromIndex(array $query)
    {
        return $this->actingAs($this->user)->from(route('posts.index'))->get(route('posts.index', $query));
    }

    /** 既定では他人（$other）の、お知らせのポストを作る。順序が要るテストでは created_at を渡す */
    private function makePost(string $title, array $attributes = [], ?Category $category = null, ?User $author = null): Post
    {
        return Post::factory()
            ->for($author ?? $this->other)
            ->for($category ?? $this->notice)
            ->create(array_merge(['title' => $title, 'content' => '内容'], $attributes));
    }

    private function bookmark(Post $post, ?User $by = null): void
    {
        $post->bookmarkedBy()->attach(($by ?? $this->user)->id);
    }

    /** 並んでいるはずのタイトルは出ていて、並んでいないはずのタイトルは出ていないこと */
    private function assertTitles(TestResponse $response, array $shown, array $hidden = []): TestResponse
    {
        $response->assertOk();

        foreach ($shown as $title) {
            $response->assertSee($title);
        }

        foreach ($hidden as $title) {
            $response->assertDontSee($title);
        }

        return $response;
    }

    // --- 受け入れ条件 ---

    #[Test]
    public function キーワードで検索するとタイトルか本文に含むポストだけが並ぶ(): void
    {
        $this->makePost('検索語を含むタイトル');
        $this->makePost('本文側のポスト', ['content' => 'ここに検索語が入っている']);
        $this->makePost('含まないポスト', ['content' => '関係のない内容']);

        $this->assertTitles(
            $this->search(['q' => '検索語']),
            ['検索語を含むタイトル', '本文側のポスト'],
            ['含まないポスト'],
        );
    }

    #[Test]
    public function トピックを選んで検索するとそのトピックのポストだけが並ぶ(): void
    {
        $this->makePost('お知らせのポスト', [], $this->notice);
        $this->makePost('技術メモのポスト', [], $this->tech);

        $this->assertTitles(
            $this->search(['category' => $this->tech->id]),
            ['技術メモのポスト'],
            ['お知らせのポスト'],
        );
    }

    #[Test]
    public function ブックマークしたポストだけを選ぶと自分のブックマークだけが並ぶ(): void
    {
        $mine = $this->makePost('自分がブックマークしたポスト');
        $theirs = $this->makePost('他人だけがブックマークしたポスト');
        $this->makePost('誰もブックマークしていないポスト');
        $this->bookmark($mine);
        $this->bookmark($theirs, $this->other);

        $this->assertTitles(
            $this->search(['bookmarked' => 1]),
            ['自分がブックマークしたポスト'],
            ['他人だけがブックマークしたポスト', '誰もブックマークしていないポスト'],
        );
    }

    /**
     * 組み合わせの確認用データ（自分のブックマーク付き）
     * A: お知らせ・Laravel・ブックマーク / B: お知らせ・Laravel / C: 技術メモ・Laravel・ブックマーク
     * D: 技術メモ・PHP・ブックマーク / E: お知らせ・PHP・ブックマーク
     */
    private function seedCombination(): void
    {
        $this->bookmark($this->makePost('Laravel入門A', [], $this->notice));
        $this->makePost('Laravel応用B', [], $this->notice);
        $this->bookmark($this->makePost('Laravel実践C', [], $this->tech));
        $this->bookmark($this->makePost('PHPの基本D', [], $this->tech));
        $this->bookmark($this->makePost('PHP小話E', [], $this->notice));
    }

    #[Test]
    public function キーワードとトピックを指定すると両方に合うポストだけが並ぶ(): void
    {
        $this->seedCombination();

        $this->assertTitles(
            $this->search(['q' => 'Laravel', 'category' => $this->notice->id]),
            ['Laravel入門A', 'Laravel応用B'],
            ['Laravel実践C', 'PHPの基本D', 'PHP小話E'],
        );
    }

    #[Test]
    public function キーワードとブックマークを指定すると両方に合うポストだけが並ぶ(): void
    {
        $this->seedCombination();

        $this->assertTitles(
            $this->search(['q' => 'Laravel', 'bookmarked' => 1]),
            ['Laravel入門A', 'Laravel実践C'],
            ['Laravel応用B', 'PHPの基本D', 'PHP小話E'],
        );
    }

    #[Test]
    public function トピックとブックマークを指定すると両方に合うポストだけが並ぶ(): void
    {
        $this->seedCombination();

        $this->assertTitles(
            $this->search(['category' => $this->tech->id, 'bookmarked' => 1]),
            ['Laravel実践C', 'PHPの基本D'],
            ['Laravel入門A', 'Laravel応用B', 'PHP小話E'],
        );
    }

    #[Test]
    public function キーワードとトピックとブックマークの3つを指定するとすべてに合うポストだけが並ぶ(): void
    {
        $this->seedCombination();

        $this->assertTitles(
            $this->search(['q' => 'Laravel', 'category' => $this->notice->id, 'bookmarked' => 1]),
            ['Laravel入門A'],
            ['Laravel応用B', 'Laravel実践C', 'PHPの基本D', 'PHP小話E'],
        );
    }

    #[Test]
    public function 合うポストが無い条件で検索すると該当なしの案内が出る(): void
    {
        $this->makePost('ふつうのポスト');

        $this->search(['q' => '存在しない語'])
            ->assertOk()
            ->assertSee(self::NO_MATCH)
            ->assertDontSee('ふつうのポスト')
            ->assertDontSee(self::NO_POST);
    }

    #[Test]
    public function 条件を空にして検索すると全ポストが新しい順に並び条件をクリアは出ない(): void
    {
        $this->makePost('古いポスト', ['created_at' => now()->subDay()]);
        $this->makePost('新しいポスト', ['created_at' => now()]);

        // 検索欄を空のまま「検索」を押したときに送られる形
        $this->actingAs($this->user)->get(route('posts.index').'?q=&category=')
            ->assertOk()
            ->assertSeeInOrder(['新しいポスト', '古いポスト'])
            ->assertDontSee(self::CLEAR_LINK)
            ->assertDontSee(self::NO_MATCH);
    }

    #[Test]
    public function 条件を付けると条件をクリアが出て押すと全ポストの一覧に戻る(): void
    {
        $this->makePost('該当するポスト', ['content' => '検索語']);
        $this->makePost('該当しないポスト');

        $this->search(['q' => '検索語'])
            ->assertOk()
            ->assertSee(self::CLEAR_LINK)
            ->assertSee('href="'.route('posts.index').'" class="clear-link"', false)
            ->assertDontSee('該当しないポスト');

        // リンク先（条件なしの posts.index）では全ポストが並ぶ
        $this->search()
            ->assertOk()
            ->assertSee('該当するポスト')
            ->assertSee('該当しないポスト')
            ->assertDontSee(self::CLEAR_LINK);
    }

    // --- 境界・認可・その他 ---

    #[Test]
    public function キーワードがちょうど100字なら検索できる(): void
    {
        $this->makePost(str_repeat('あ', 100));
        $this->makePost('別のポスト');

        $this->assertTitles(
            $this->search(['q' => str_repeat('あ', 100)]),
            [str_repeat('あ', 100)],
            ['別のポスト'],
        );
    }

    #[Test]
    public function キーワードが101字だとエラーになり一覧は出ない(): void
    {
        $this->makePost('一覧に出るはずのポスト', ['content' => str_repeat('あ', 101)]);

        $this->searchFromIndex(['q' => str_repeat('あ', 101)])
            ->assertRedirect(route('posts.index'))
            ->assertSessionHasErrors(['q' => 'キーワードは100文字以内で入力してください。']);

        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertOk()
            ->assertSee('キーワードは100文字以内で入力してください。')
            ->assertDontSee('一覧に出るはずのポスト')
            ->assertDontSee(self::NO_POST)
            ->assertDontSee(self::NO_MATCH);
    }

    #[Test]
    public function 前後の空白を取り除いた結果が100字ならエラーにならない(): void
    {
        $this->makePost(str_repeat('あ', 100));

        // 空白を含めると 102 字。取り除いた後の文字数で数える
        $this->assertTitles(
            $this->search(['q' => ' '.str_repeat('あ', 100).'　']),
            [str_repeat('あ', 100)],
        );
    }

    #[Test]
    public function キーワードが半角空白だけだと絞り込まず全ポストが並ぶ(): void
    {
        $this->makePost('ポストその一');
        $this->makePost('ポストその二');

        $this->assertTitles($this->search(['q' => '   ']), ['ポストその一', 'ポストその二'])
            ->assertDontSee(self::CLEAR_LINK)
            ->assertDontSee(self::NO_MATCH);
    }

    #[Test]
    public function キーワードが全角空白だけだと絞り込まず全ポストが並ぶ(): void
    {
        $this->makePost('ポストその一');
        $this->makePost('ポストその二');

        $this->assertTitles($this->search(['q' => '　　']), ['ポストその一', 'ポストその二'])
            ->assertDontSee(self::CLEAR_LINK)
            ->assertDontSee(self::NO_MATCH);
    }

    #[Test]
    public function キーワードの前後に空白があっても取り除いた語で検索される(): void
    {
        $this->makePost('対象語のポスト');
        $this->makePost('関係ないポスト');

        // 半角と全角を前後に混ぜる
        $this->assertTitles(
            $this->search(['q' => " 　対象語　 "]),
            ['対象語のポスト'],
            ['関係ないポスト'],
        );
    }

    #[Test]
    public function 空白で区切った語は入力全体を1つの語として探す(): void
    {
        $this->makePost('りんご みかん');
        $this->makePost('りんごだけ');
        $this->makePost('みかんだけ');

        $this->assertTitles(
            $this->search(['q' => 'りんご みかん']),
            ['りんご みかん'],
            ['りんごだけ', 'みかんだけ'],
        );
    }

    #[Test]
    public function 英字の大文字小文字は区別せず検索できる(): void
    {
        $this->makePost('Laravel の話');
        $this->makePost('別の話');

        $this->assertTitles($this->search(['q' => 'LARAVEL']), ['Laravel の話'], ['別の話']);
    }

    #[Test]
    #[DataProvider('特別な文字')]
    public function 検索欄の特別な文字はふつうの文字として探す(string $keyword, string $expected): void
    {
        $titles = ['100%達成', '100円達成', 'a_b', 'aXb', 'C:\\dir', 'C:dir'];
        foreach ($titles as $title) {
            $this->makePost($title);
        }

        // % で全件が当たったり、_ が任意の 1 字になったりしない
        $this->assertTitles(
            $this->search(['q' => $keyword]),
            [$expected],
            array_values(array_diff($titles, [$expected])),
        );
    }

    public static function 特別な文字(): array
    {
        return [
            '%' => ['%', '100%達成'],
            '_' => ['_', 'a_b'],
            '\\' => ['\\', 'C:\\dir'],
        ];
    }

    #[Test]
    public function 投稿者名とトピック名とタグ名とリプライ本文にだけ含まれる語では出ない(): void
    {
        $author = User::factory()->create(['name' => '投稿者だけの語']);
        $category = Category::factory()->create(['name' => 'トピックだけの語']);

        $byAuthor = $this->makePost('投稿者名のポスト', [], $this->notice, $author);
        $this->makePost('トピック名のポスト', [], $category);
        $tagged = $this->makePost('タグ名のポスト');
        $tagged->tags()->attach(Tag::factory()->create(['name' => 'タグだけの語'])->id);
        $replied = $this->makePost('リプライのポスト');
        Reply::factory()->for($replied)->for($this->user)->create(['content' => 'リプライだけの語']);

        foreach (['投稿者だけの語', 'トピックだけの語', 'タグだけの語', 'リプライだけの語'] as $keyword) {
            $this->search(['q' => $keyword])
                ->assertOk()
                ->assertSee(self::NO_MATCH)
                ->assertDontSee($byAuthor->title)
                ->assertDontSee('トピック名のポスト')
                ->assertDontSee('タグ名のポスト')
                ->assertDontSee('リプライのポスト');
        }
    }

    #[Test]
    public function 検索結果は新しい順に並ぶ(): void
    {
        $this->makePost('検索語の古いポスト', ['created_at' => now()->subDays(2)]);
        $this->makePost('検索語の新しいポスト', ['created_at' => now()]);
        $this->makePost('検索語の真ん中のポスト', ['created_at' => now()->subDay()]);

        $this->search(['q' => '検索語'])
            ->assertOk()
            ->assertSeeInOrder(['検索語の新しいポスト', '検索語の真ん中のポスト', '検索語の古いポスト']);
    }

    #[Test]
    public function 検索後も検索欄にキーワードと選んだトピックとチェックが残る(): void
    {
        $this->makePost('対象語のポスト', [], $this->tech);

        $this->search(['q' => '対象語', 'category' => $this->tech->id, 'bookmarked' => 1])
            ->assertOk()
            ->assertSee('name="q"', false)
            ->assertSee('value="対象語"', false)
            ->assertSee('value="'.$this->tech->id.'" selected', false)
            ->assertDontSee('value="'.$this->notice->id.'" selected', false)
            ->assertSee('name="bookmarked" value="1" checked', false);
    }

    #[Test]
    public function 条件を付けていなければチェックは外れトピックは選ばれていない(): void
    {
        $this->search()
            ->assertOk()
            ->assertDontSee('name="bookmarked" value="1" checked', false)
            ->assertDontSee('selected', false);
    }

    #[Test]
    public function キーワードに配列を送っても500にならず文字列で入力するエラーが出る(): void
    {
        $this->makePost('一覧に出るはずのポスト');

        $this->searchFromIndex(['q' => ['a', 'b']])
            ->assertRedirect(route('posts.index'))
            ->assertSessionHasErrors(['q' => 'キーワードは文字列で入力してください。']);

        // 戻った画面（old に配列が入っている）も 500 にならない
        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertOk()
            ->assertSee('キーワードは文字列で入力してください。')
            ->assertDontSee('validation.')
            ->assertDontSee('一覧に出るはずのポスト');
    }

    #[Test]
    #[DataProvider('正しくないブックマークの値')]
    public function ブックマークの絞り込みに1以外を送るとエラーになる(string|array $value): void
    {
        $this->makePost('一覧に出るはずのポスト');

        $this->searchFromIndex(['bookmarked' => $value])
            ->assertRedirect(route('posts.index'))
            ->assertSessionHasErrors(['bookmarked' => '選択されたブックマークの絞り込みは正しくありません。']);

        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertOk()
            ->assertSee('選択されたブックマークの絞り込みは正しくありません。')
            ->assertDontSee('validation.')
            ->assertDontSee('一覧に出るはずのポスト');
    }

    public static function 正しくないブックマークの値(): array
    {
        return [
            '0' => ['0'],
            'abc' => ['abc'],
            '配列' => [['1']],
        ];
    }

    #[Test]
    public function エラーで戻ったとき入力したキーワードとエラーメッセージが画面に残る(): void
    {
        $keyword = str_repeat('あ', 101);

        $this->searchFromIndex(['q' => $keyword, 'category' => $this->tech->id])
            ->assertRedirect(route('posts.index'))
            ->assertSessionHasErrors('q');

        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertOk()
            ->assertSee('value="'.$keyword.'"', false)
            ->assertSee('value="'.$this->tech->id.'" selected', false)
            ->assertSee('キーワードは100文字以内で入力してください。');
    }

    #[Test]
    #[DataProvider('存在しないトピックの値')]
    public function 存在しないトピックを指定すると404になる(string|array $value): void
    {
        $this->makePost('ポスト');

        $this->actingAs($this->user)->get(route('posts.index', ['category' => $value]))->assertNotFound();
    }

    public static function 存在しないトピックの値(): array
    {
        return [
            '存在しない id' => ['99999'],
            '数値以外' => ['abc'],
            '小数' => ['1.5'],
            '負の数' => ['-1'],
            '配列' => [['1']],
        ];
    }

    #[Test]
    public function トピックと入力エラーが両方不正なときは404になる(): void
    {
        $this->searchFromIndex(['category' => '99999', 'q' => str_repeat('あ', 101)])->assertNotFound();
    }

    #[Test]
    public function トピックに空文字を送ると絞り込まず全ポストが並ぶ(): void
    {
        $this->makePost('お知らせのポスト', [], $this->notice);
        $this->makePost('技術メモのポスト', [], $this->tech);

        $this->actingAs($this->user)->get(route('posts.index').'?category=')
            ->assertOk()
            ->assertSee('お知らせのポスト')
            ->assertSee('技術メモのポスト')
            ->assertDontSee(self::CLEAR_LINK);
    }

    #[Test]
    public function タグを付けた状態で検索するとタグも隠し項目で引き継がれ検索条件とのANDで絞られる(): void
    {
        $tag = Tag::factory()->create(['name' => '対象タグ']);
        $otherTag = Tag::factory()->create(['name' => '別のタグ']);

        $both = $this->makePost('タグも語も合うポスト', ['content' => '検索語']);
        $both->tags()->attach($tag->id);
        $onlyTag = $this->makePost('タグだけ合うポスト');
        $onlyTag->tags()->attach($tag->id);
        $onlyWord = $this->makePost('語だけ合うポスト', ['content' => '検索語']);
        $onlyWord->tags()->attach($otherTag->id);

        // タグを選んだ状態の検索欄には、そのタグが隠し項目で入っている
        $this->search(['tag' => $tag->id])
            ->assertOk()
            ->assertSee('<input type="hidden" name="tag" value="'.$tag->id.'">', false)
            ->assertSee(self::CLEAR_LINK);

        // 「検索」を押すと tag も一緒に送られる
        $this->assertTitles(
            $this->search(['tag' => $tag->id, 'q' => '検索語']),
            ['タグも語も合うポスト'],
            ['タグだけ合うポスト', '語だけ合うポスト'],
        );
    }

    #[Test]
    public function タグを選んでいなければ隠し項目は出ない(): void
    {
        $this->search(['q' => '検索語'])
            ->assertOk()
            ->assertDontSee('name="tag"', false);
    }

    #[Test]
    public function 検索結果の他人のポストに編集と削除は出ないがいいねとブックマークは出る(): void
    {
        $theirs = $this->makePost('他人の検索語ポスト');
        $mine = $this->makePost('自分の検索語ポスト', [], $this->notice, $this->user);

        $this->search(['q' => '検索語'])
            ->assertOk()
            ->assertDontSee(route('posts.edit', $theirs), false)
            // posts.destroy の URL は「返信」リンク（posts.show）と同じなので、削除フォームの action 属性で確かめる
            ->assertDontSee('action="'.route('posts.destroy', $theirs).'"', false)
            ->assertSee(route('posts.edit', $mine), false)
            ->assertSee(route('likes.store', $theirs), false)
            ->assertSee(route('bookmarks.store', $theirs), false)
            ->assertSee(self::ADD_BOOKMARK_LABEL, false)
            ->assertSee('いいねする');
    }

    #[Test]
    public function 別のユーザーのブックマークの絞り込みはそのユーザー自身のブックマークだけが対象になる(): void
    {
        $byUser = $this->makePost('ユーザーAのブックマーク');
        $byOther = $this->makePost('ユーザーBのブックマーク');
        $this->makePost('誰もしていないポスト');
        $this->bookmark($byUser, $this->user);
        $this->bookmark($byOther, $this->other);

        $this->assertTitles(
            $this->search(['bookmarked' => 1], $this->user),
            ['ユーザーAのブックマーク'],
            ['ユーザーBのブックマーク', '誰もしていないポスト'],
        );

        $this->assertTitles(
            $this->search(['bookmarked' => 1], $this->other),
            ['ユーザーBのブックマーク'],
            ['ユーザーAのブックマーク', '誰もしていないポスト'],
        );
    }

    #[Test]
    public function 未ログインで検索するとログイン画面へ送られる(): void
    {
        $this->makePost('ポスト');

        $this->get(route('posts.index', ['q' => 'ポスト']))->assertRedirect(route('login'));
    }

    #[Test]
    public function 検索を実行してもデータの件数は変わらない(): void
    {
        $post = $this->makePost('検索語のポスト');
        $this->bookmark($post);
        $post->likedBy()->attach($this->other->id);
        Reply::factory()->for($post)->for($this->other)->create();

        $tables = ['users', 'categories', 'posts', 'bookmarks', 'likes', 'replies', 'tags', 'post_tag'];
        $before = array_map(fn ($table) => DB::table($table)->count(), $tables);

        $this->search(['q' => '検索語', 'category' => $this->notice->id, 'bookmarked' => 1])->assertOk();
        $this->searchFromIndex(['q' => str_repeat('あ', 101)]);

        $this->assertSame($before, array_map(fn ($table) => DB::table($table)->count(), $tables));
    }

    // --- 既存の動きが変わっていないことの確認 ---

    #[Test]
    public function 条件なしで開くと全ポストが新しい順に並び項目とリンクは今まで通りで検索欄が出る(): void
    {
        $first = $this->makePost('一番古いポスト', ['content' => '一番古い本文', 'created_at' => now()->subDays(2)]);
        $second = $this->makePost('二番目のポスト', ['created_at' => now()->subDay()], $this->tech);
        $third = $this->makePost('一番新しいポスト', ['created_at' => now()]);

        $this->search()
            ->assertOk()
            ->assertSeeInOrder(['一番新しいポスト', '二番目のポスト', '一番古いポスト'])
            ->assertSee('一番古い本文')
            ->assertSee('技術メモ')
            ->assertSee($this->other->name)
            ->assertSee('href="'.route('posts.show', $first).'"', false)
            ->assertSee('href="'.route('posts.show', $second).'"', false)
            ->assertSee('href="'.route('posts.show', $third).'"', false)
            ->assertSee('name="q"', false)
            ->assertSee('name="category"', false)
            ->assertSee('name="bookmarked"', false)
            ->assertSee('すべてのトピック')
            ->assertSee('ブックマークしたポストだけ')
            ->assertSee('>検索<', false)
            ->assertDontSee(self::CLEAR_LINK);
    }

    #[Test]
    public function 検索欄のトピックは先頭がすべてのトピックで続いてid順に並ぶ(): void
    {
        $this->search()
            ->assertOk()
            ->assertSeeInOrder([
                '<option value="">すべてのトピック</option>',
                '<option value="'.$this->notice->id.'"',
                '>お知らせ</option>',
                '<option value="'.$this->tech->id.'"',
                '>技術メモ</option>',
            ], false);
    }

    #[Test]
    public function 一覧はページ分けされず全件が並ぶ(): void
    {
        foreach (range(1, 30) as $i) {
            $this->makePost("ポスト番号{$i}番", ['created_at' => now()->subMinutes($i)]);
        }

        $this->search()
            ->assertOk()
            ->assertSee('ポスト番号1番')
            ->assertSee('ポスト番号30番');
    }

    #[Test]
    public function ポストが1件も無いときはまだポストがありませんが出て検索欄も出る(): void
    {
        $this->search()
            ->assertOk()
            ->assertSee(self::NO_POST)
            ->assertDontSee(self::NO_MATCH)
            ->assertSee('name="q"', false);
    }

    #[Test]
    public function タグだけを付けて0件のときはこのタグのポストはありませんのまま(): void
    {
        $tag = Tag::factory()->create();

        $this->search(['tag' => $tag->id])
            ->assertOk()
            ->assertSee(self::NO_TAG_POST)
            ->assertDontSee(self::NO_MATCH)
            ->assertDontSee(self::NO_POST);
    }

    #[Test]
    public function タグと検索条件を付けて0件のときは条件に合うポストはありませんが出る(): void
    {
        $tag = Tag::factory()->create();
        $this->makePost('ポスト')->tags()->attach($tag->id);

        $this->search(['tag' => $tag->id, 'q' => '存在しない語'])
            ->assertOk()
            ->assertSee(self::NO_MATCH)
            ->assertDontSee(self::NO_TAG_POST);
    }

    #[Test]
    public function 存在しないタグは今まで通り404になる(): void
    {
        $this->search(['tag' => 99999])->assertNotFound();
    }

    #[Test]
    public function 絞り込み中にいいねとブックマークを押しても条件は引き継がず一覧に戻る(): void
    {
        $post = $this->makePost('検索語のポスト');
        $searchUrl = route('posts.index', ['q' => '検索語', 'bookmarked' => 1]);

        $this->actingAs($this->user)->from($searchUrl)
            ->post(route('likes.store', $post))
            ->assertRedirect(route('posts.index'));

        $this->actingAs($this->user)->from($searchUrl)
            ->post(route('bookmarks.store', $post))
            ->assertRedirect(route('posts.index'));

        $this->assertDatabaseHas('bookmarks', ['user_id' => $this->user->id, 'post_id' => $post->id]);
    }

    #[Test]
    public function 自分のポストには編集と削除が出て他人のポストには出ない(): void
    {
        $mine = $this->makePost('自分のポスト', [], $this->notice, $this->user);
        $theirs = $this->makePost('他人のポスト');

        $this->search()
            ->assertOk()
            ->assertSee(route('posts.edit', $mine), false)
            // posts.destroy の URL は「返信」リンク（posts.show）と同じなので、削除フォームの action 属性で確かめる
            ->assertSee('action="'.route('posts.destroy', $mine).'"', false)
            ->assertDontSee(route('posts.edit', $theirs), false)
            ->assertDontSee('action="'.route('posts.destroy', $theirs).'"', false);
    }

    #[Test]
    public function ポスト作成の入力チェックは今まで通り日本語で出る(): void
    {
        $send = fn (array $override) => $this->actingAs($this->user)->post(route('posts.store'), array_merge([
            'title' => 'タイトル',
            'category_id' => $this->notice->id,
            'content' => '本文',
        ], $override));

        $send(['title' => ''])->assertSessionHasErrors(['title' => 'タイトルを入力してください。']);
        $send(['title' => str_repeat('あ', 256)])->assertSessionHasErrors(['title' => 'タイトルは255文字以内で入力してください。']);
        $send(['content' => str_repeat('あ', 141)])->assertSessionHasErrors(['content' => '本文は140字以内で入力してください。']);
        $send(['category_id' => ''])->assertSessionHasErrors(['category_id' => 'トピックを選択してください。']);

        $this->assertDatabaseCount('posts', 0);
    }

    // --- 言語設定 ---

    #[Test]
    public function 検索の入力エラーは日本語で出て翻訳キーがそのまま出ない(): void
    {
        $this->searchFromIndex(['q' => str_repeat('あ', 101), 'bookmarked' => 'abc'])
            ->assertSessionHasErrors([
                'q' => 'キーワードは100文字以内で入力してください。',
                'bookmarked' => '選択されたブックマークの絞り込みは正しくありません。',
            ]);

        $this->actingAs($this->user)->get(route('posts.index'))
            ->assertOk()
            ->assertSee('キーワードは100文字以内で入力してください。')
            ->assertSee('選択されたブックマークの絞り込みは正しくありません。')
            ->assertDontSee('validation.');
    }
}
