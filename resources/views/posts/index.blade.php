<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ホーム / つぶやき投稿アプリ</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Hiragino Sans", "Noto Sans JP", sans-serif; background: #FFFFFF; color: #0F1419; font-feature-settings: "palt"; }
        a { text-decoration: none; color: inherit; }
        .col { max-width: 600px; margin: 0 auto; min-height: 100dvh; border-left: 1px solid #EFF1F4; border-right: 1px solid #EFF1F4; }
        .chrome { position: sticky; top: 0; z-index: 10; background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px); border-bottom: 1px solid #EFF1F4; }
        .chrome-row { display: flex; justify-content: space-between; align-items: center; gap: 0.8rem; padding: 0.6rem 1rem; }
        .brand { display: flex; align-items: center; gap: 0.5rem; font-weight: 800; font-size: 1.05rem; letter-spacing: -0.02em; }
        .brand .mark { width: 28px; height: 28px; border-radius: 38%; background: linear-gradient(135deg, #FFB03A, #FF7A59); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; font-weight: 800; flex-shrink: 0; }
        .me { display: flex; align-items: center; gap: 0.7rem; min-width: 0; }
        .me .name { font-size: 0.8rem; color: #5B6570; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .logout-btn { background: #FFFFFF; color: #0F1419; border: 1px solid #D3D9DE; padding: 0.38rem 1rem; border-radius: 999px; font-size: 0.8rem; font-weight: 700; cursor: pointer; font-family: inherit; flex-shrink: 0; }
        .logout-btn:hover { background: #F7F8F9; }
        .logout-btn:focus-visible { outline: 2px solid #E8792B; outline-offset: 2px; }
        .page-title { padding: 0.55rem 1rem 0.7rem; font-weight: 800; font-size: 1.06rem; }
        .post { display: flex; gap: 0.75rem; padding: 0.9rem 1rem; border-bottom: 1px solid #EFF1F4; }
        .post:hover { background: #F7F8F9; }
        .avatar { width: 44px; height: 44px; border-radius: 999px; flex-shrink: 0; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; }
        .post-body { flex: 1; min-width: 0; }
        .post-head { display: flex; align-items: baseline; gap: 0.3rem; flex-wrap: wrap; }
        .post-head .name { font-weight: 700; font-size: 0.94rem; }
        .post-head .time { color: #5B6570; font-size: 0.82rem; font-variant-numeric: tabular-nums; }
        .post-head .time::before { content: "・"; margin-right: 0.05rem; color: #98A1A8; }
        .post-head .edited { color: #98A1A8; font-size: 0.78rem; }
        .topic { margin-left: auto; border-radius: 999px; padding: 0.1rem 0.6rem; font-size: 0.7rem; font-weight: 700; }
        .post-title { font-weight: 700; font-size: 0.96rem; margin: 0.28rem 0 0.06rem; }
        .post-text { font-size: 0.94rem; line-height: 1.7; color: #0F1419; overflow-wrap: anywhere; white-space: pre-line; }
        .post-actions { display: flex; gap: 1.2rem; margin-top: 0.5rem; }
        .post-actions a, .post-actions button { background: none; border: none; padding: 0; color: #5B6570; font-size: 0.8rem; font-weight: 600; cursor: pointer; font-family: inherit; }
        .post-actions a:hover { color: #0F1419; text-decoration: underline; text-underline-offset: 3px; }
        .post-actions button:hover { color: #D6402C; text-decoration: underline; text-underline-offset: 3px; }
        .post-actions a:focus-visible, .post-actions button:focus-visible { outline: 2px solid #E8792B; outline-offset: 2px; border-radius: 4px; }
        .post-actions .like-form { display: inline-flex; align-items: center; gap: 0.35rem; }
        .post-actions .like-btn:hover { color: #D6402C; text-decoration: underline; text-underline-offset: 3px; }
        .post-actions .like-form.liked .like-btn { color: #D6402C; font-weight: 800; }
        .post-actions .like-count { color: #5B6570; font-size: 0.8rem; font-variant-numeric: tabular-nums; }
        .post-actions .like-form.liked .like-count { color: #D6402C; font-weight: 800; }
        .post-actions .bookmark-form { display: inline-flex; align-items: center; }
        .post-actions .bookmark-btn { display: inline-flex; align-items: center; line-height: 0; }
        .post-actions .bookmark-btn svg { width: 18px; height: 18px; display: block; }
        .post-actions .bookmark-btn:hover { color: #0F1419; text-decoration: none; }
        .post-actions .bookmark-btn.bookmarked { color: #2F5AA8; }
        .page-title-row { display: flex; justify-content: space-between; align-items: center; gap: 0.8rem; padding-right: 1rem; }
        .page-title-row .page-title { padding-right: 0; }
        .write-link { background: #0F1419; color: #fff; padding: 0.38rem 1rem; border-radius: 999px; font-size: 0.8rem; font-weight: 700; flex-shrink: 0; }
        .write-link:hover { background: #272C30; }
        .write-link:focus-visible { outline: 2px solid #E8792B; outline-offset: 2px; }
        .tag-filter { display: flex; flex-wrap: wrap; gap: 0.4rem; padding: 0.7rem 1rem; border-bottom: 1px solid #EFF1F4; }
        .tag-filter a { border: 1px solid #D3D9DE; border-radius: 999px; padding: 0.2rem 0.8rem; font-size: 0.8rem; font-weight: 600; color: #5B6570; }
        .tag-filter a:hover { background: #F7F8F9; }
        .tag-filter a[aria-current] { background: #0F1419; border-color: #0F1419; color: #fff; font-weight: 700; }
        .tag-filter a:focus-visible, .post-tags a:focus-visible { outline: 2px solid #E8792B; outline-offset: 2px; }
        .post-tags { display: flex; flex-wrap: wrap; gap: 0.2rem 0.6rem; margin-top: 0.35rem; }
        .post-tags a { color: #2F5AA8; font-size: 0.85rem; font-weight: 600; }
        .post-tags a:hover { text-decoration: underline; text-underline-offset: 3px; }
        .empty { color: #5B6570; text-align: center; padding: 3.5rem 1rem; }
    </style>
</head>
<body>
    <div class="col">
        <header class="chrome">
            <div class="chrome-row">
                <a href="{{ route('posts.index') }}" class="brand"><span class="mark">つ</span>つぶやき投稿アプリ</a>
                <div class="me">
                    <span class="name">{{ auth()->user()->name }}（{{ auth()->user()->email }}）</span>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="logout-btn">ログアウト</button>
                    </form>
                </div>
            </div>
            <div class="page-title-row">
                <div class="page-title">ホーム</div>
                <a href="{{ route('posts.create') }}" class="write-link">ポストを書く</a>
            </div>
        </header>

        <main class="feed">
            <nav class="tag-filter" aria-label="タグで絞り込む">
                <a href="{{ route('posts.index') }}">すべて</a>
                @foreach ($tags as $t)
                    <a href="{{ route('posts.index', ['tag' => $t->id]) }}" @if($tag && $tag->id === $t->id) aria-current="true" @endif>#{{ $t->name }}</a>
                @endforeach
            </nav>

            @if($posts->isEmpty())
                <p class="empty">{{ $tag ? 'このタグのポストはありません。' : 'まだポストがありません。' }}</p>
            @else
                @php
                    // アイコンの色は登録した名前から決まる（crc32 % 18）
                    $grad = [
                        ['#5B8DEF', '#3F5FD0'],
                        ['#E8A23D', '#C97F1B'],
                        ['#34B58B', '#1E8A6E'],
                        ['#E05252', '#B93245'],
                        ['#8E6AE0', '#6748BE'],
                        ['#3FB0C9', '#2884A5'],
                        ['#F2865C', '#DB5A34'],
                        ['#6D7BE0', '#4A55C2'],
                        ['#8FAE3E', '#6C8A26'],
                        ['#31B183', '#188064'],
                        ['#7C93B5', '#56718F'],
                        ['#F7A934', '#E8721F'],
                        ['#EF6A6A', '#CE3A50'],
                        ['#9B6CE8', '#6E48C9'],
                        ['#E45FA3', '#C13A85'],
                        ['#B58A5C', '#8F653A'],
                        ['#55A8E2', '#3380BC'],
                        ['#4E9E8E', '#2F7A6B'],
                    ];
                    $topicTone = [
                        'お知らせ' => ['#FFF3DC', '#8A5714'],
                        '技術メモ' => ['#E8F1FD', '#2F5AA8'],
                        '雑記' => ['#F0F1F3', '#57606A'],
                    ];
                @endphp
                @foreach ($posts as $post)
                    @php
                        [$g1, $g2] = $grad[crc32($post->user->name) % count($grad)];
                        [$tBg, $tFg] = $topicTone[$post->category->name] ?? ['#F0F1F3', '#57606A'];
                    @endphp
                    <article class="post">
                        <div class="avatar" style="background: linear-gradient(135deg, {{ $g1 }}, {{ $g2 }});">{{ mb_substr($post->user->name, 0, 1) }}</div>
                        <div class="post-body">
                            <div class="post-head">
                                <span class="name">{{ $post->user->name }}</span>
                                <span class="time">{{ $post->created_at->format('n月j日 H:i') }}</span>
                                @if($post->isEdited())
                                    <span class="edited">編集済み</span>
                                @endif
                                <span class="topic" style="background: {{ $tBg }}; color: {{ $tFg }};">{{ $post->category->name }}</span>
                            </div>
                            <p class="post-title">{{ $post->title }}</p>
                            <p class="post-text">{{ $post->content }}</p>
                            @if($post->tags->isNotEmpty())
                                <div class="post-tags">
                                    @foreach ($post->tags as $postTag)
                                        <a href="{{ route('posts.index', ['tag' => $postTag->id]) }}">#{{ $postTag->name }}</a>
                                    @endforeach
                                </div>
                            @endif
                            <div class="post-actions">
                                <a href="{{ route('posts.show', $post) }}">返信</a>
                                @php $liked = $post->liked_by_me; @endphp
                                <form action="{{ route($liked ? 'likes.destroy' : 'likes.store', $post) }}" method="POST" class="like-form{{ $liked ? ' liked' : '' }}">
                                    @csrf
                                    @if($liked)
                                        @method('DELETE')
                                    @endif
                                    <button type="submit" class="like-btn">{{ $liked ? 'いいねを取り消す' : 'いいねする' }}</button>
                                    <span class="like-count">{{ $post->liked_by_count }}</span>
                                </form>
                                @php $bookmarked = $post->bookmarked_by_me; @endphp
                                <form action="{{ route($bookmarked ? 'bookmarks.destroy' : 'bookmarks.store', $post) }}" method="POST" class="bookmark-form">
                                    @csrf
                                    @if($bookmarked)
                                        @method('DELETE')
                                    @endif
                                    <button type="submit" class="bookmark-btn{{ $bookmarked ? ' bookmarked' : '' }}" aria-label="{{ $bookmarked ? 'ブックマークを外す' : 'ブックマークする' }}" title="{{ $bookmarked ? 'ブックマークを外す' : 'ブックマークする' }}">
                                        <svg viewBox="0 0 24 24" fill="{{ $bookmarked ? '#2F5AA8' : 'none' }}" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4.5L5 21V4a1 1 0 0 1 1-1z"/></svg>
                                    </button>
                                </form>
                                @can('update', $post)
                                    <a href="{{ route('posts.edit', $post) }}">編集</a>
                                @endcan
                                @can('delete', $post)
                                    <form action="{{ route('posts.destroy', $post) }}" method="POST" style="display: inline;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit">削除</button>
                                    </form>
                                @endcan
                            </div>
                        </div>
                    </article>
                @endforeach
            @endif
        </main>
    </div>
</body>
</html>
