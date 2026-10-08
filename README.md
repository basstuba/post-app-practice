# post-app

ポストの作成・一覧・編集・削除と、ポストへのリプライができる、小さな Laravel アプリです。Tutorial 13 から 15 まで、このアプリ 1 本を題材に設計・実装・仕組み化を進めます。

## できること

- ユーザー登録・ログイン・ログアウト
- ポストの一覧表示（投稿者の名前とカテゴリつき。新しい順）
- ポストの新規作成（タイムラインのヘッダーの「ポストを書く」から。タイトル・トピック・本文を入力し、本文は 140 字以内）
- ポストの編集（タイトル・トピック・本文）と削除
- 自分のポストだけを編集・削除（他人のポストは編集・削除ボタンが出ず、URL を直接開くと 403 になります）
- ポスト 1 件の画面（タイムラインの「返信」から）で、リプライの一覧表示（古い順）・送信（140 字以内）・削除
- 自分のリプライだけを削除（ポストの持ち主でも、他人のリプライは削除ボタンが出ず、削除しようとすると 403 になります）

## フォルダについて

`answers/` は Tutorial 13 の答え合わせ用で、アプリのコードとは関係ありません。設計書の解答例が入っているだけなので、アプリの動きには影響しません。詳しくは `answers/README.md` を見てください。

自分で作る設計書は `docs/` に置きます（Tutorial 13 の中で作ります）。機能追加の設計書は `docs/` 直下に置きます（リプライ機能は `docs/reply.md`、ポスト新規作成機能は `docs/post-create.md`）。

## 使用技術

| 項目 | 内容 |
|:-----|:-----|
| フレームワーク | Laravel 10 |
| 言語 | PHP |
| データベース | MySQL |
| 実行環境 | Laravel Sail（Docker） |
| 認証 | Laravel Fortify |

PHP と MySQL のバージョンは Sail のコンテナが決めます。手元で確かめるときは `./vendor/bin/sail php -v` と `./vendor/bin/sail mysql --version` を実行してください。

## セットアップ

Docker Desktop（または Docker Engine）を起動してから実行してください。Windows の方は WSL（Ubuntu）のターミナルで実行します。

```bash
# パッケージをインストールする
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php82-composer:latest \
    composer install

# 環境ファイルを作る
cp .env.example .env

# Sail を起動する
./vendor/bin/sail up -d

# アプリケーションキーを作る
./vendor/bin/sail artisan key:generate

# テーブルを作り、練習用データを入れる
./vendor/bin/sail artisan migrate --seed
```

ブラウザで `http://localhost` を開くと、トップページが表示されます。

初回起動時は `migrate` で「Connection refused」が出ることがあります。MySQL の起動が終わっていないだけなので、少し待ってからもう一度実行してください。

## 練習用のアカウント

`migrate --seed` で、カテゴリ 3 件（お知らせ・技術メモ・雑記）、ユーザー 7 人、ポスト 25 件が入ります。ログインに使う 2 人は次のとおりです。

| メールアドレス | パスワード | 名前 | ポスト |
|:---------------|:-----------|:-----|:-------|
| `usera@example.com` | `password` | はるか | `/posts/1`・`/posts/6`・`/posts/13`・`/posts/20` |
| `userb@example.com` | `password` | だいち | `/posts/2`・`/posts/9`・`/posts/16`・`/posts/23` |

残りの 5 人（`mio@example.com`・`sota@example.com`・`yui@example.com`・`kento@example.com`・`akari@example.com`）のパスワードも `password` です。リプライは入っていません。

本物の値は入っていません。すべて練習用のダミーです。

## テスト

```bash
./vendor/bin/sail artisan test
```

## 停止

```bash
./vendor/bin/sail down
```

このリポジトリはTutorial 14でも使います

## 設計のメモ

機能ごとの設計書は、実装した時点の記録です。このあとは更新していません。ここには、設計書から「なぜそう作ったか」だけを抜き出しています。

### リプライ機能

設計書: [`docs/reply.md`](docs/reply.md)（Issue: https://github.com/basstuba/post-app-practice/issues/1）

**作らないと決めたもの**

- リプライへのリプライ（入れ子）、リプライの編集、通知。リプライは常にポストに直接つく平らな一覧にした
- 送信や削除に成功したときのメッセージ。一覧に増える・消えること自体が結果の確認になる
- `auth.php`・`passwords.php`・`ja.json` の訳（ログイン失敗、パスワード再設定、403・404 の画面）。英語のまま残し、別の Issue で扱う
- `lang/ja/validation.php` の、使っていないルールの訳

**選ばなかった作り方**

- `PostPolicy` に判定を足す形にはせず、`ReplyPolicy` を別に作った。`AuthServiceProvider::$policies` には登録せず、命名規約による自動検出に任せている
- FormRequest は使わず、コントローラー内の `validate()` に書いた（既存の書き方に合わせた）
- 「本文は140字以内で入力してください。」を、言語ファイルの汎用文言にはしなかった。ポストと同じく `validate()` の第2引数で指定している
- 140字の上限を DB の列では持たず、入力チェックで守る（ポストの `content` と同じやり方）
- タイムラインのポスト本体はリンクにせず、編集・削除と同じ行に「返信」リンクを足した

**知らずに変えると壊れる決めごと**

- 他人のリプライは、ポストの持ち主でも消せない。判定は `user_id` の一致だけ
- 並び順は `created_at` と `id` の両方で決める。`id` を外すと、同じ秒に入ったリプライの順番がぶれる
- `replies.user_id`・`replies.post_id` は `cascadeOnDelete`。外すと、ポストやユーザーを消したときにリプライが残るか、削除が失敗する
- 本文は `\r\n` を `\n` にそろえてから数える。そろえないと、ブラウザが送る改行が 2 字になり、ポストの本文と数え方がずれる
- アプリの言語設定は `ja`。戻すと、リプライ以外の入力チェックの文言も英語に戻る。本文140字の文言は、既存の `PostContentLimitTest` の期待値とそろえてある
- タイムラインの「返信」リンクは、ポストの持ち主かどうかに関係なく出す。編集・削除の行は自分のポストにしか出ないので、他人のポストには「返信」だけの行を出している

### ポスト新規作成機能

設計書: [`docs/post-create.md`](docs/post-create.md)（Issue: https://github.com/basstuba/post-app-practice/issues/3）

**作らないと決めたもの**

- `posts` テーブルの列の追加（マイグレーションなし）
- タイムラインに置く入力フォーム。入口はヘッダーの「ポストを書く」リンクだけにした
- `PostPolicy` の `create`。ログインしていれば誰でも作れるので、`auth` ミドルウェアで足りる
- 投稿に成功したときのメッセージ。タイムラインの先頭に増えること自体が結果の確認になる

**選ばなかった作り方**

- FormRequest は使わず、コントローラー内の `validate()` に書いた（既存の書き方に合わせた）
- 作成画面と編集画面でビューを共通化しなかった。編集画面は触らないと決めたので、残り文字数の表示は作成画面の側にも持たせている
- 入力欄に `required` 属性も `maxlength` も付けなかった。ブラウザの確認に先に止められず、サーバー側のエラーを必ず表示するため（リプライと同じ判断）

**知らずに変えると壊れる決めごと**

- `GET /posts/create` は `GET /posts/{post}` より前に書く。後ろに書くと `create` が `{post}` に取られ、404 になる
- 投稿者は `auth()->id()` で決め、リクエストの `user_id` は受け取らない。検証済みの値から `user_id` を取る形にすると、他人の名前で投稿できてしまう
- トピックの先頭は「選択してください」（値は空）で、選ばずに送るとエラー。先頭のトピックが気づかないまま選ばれるのを防ぐ
- 「トピックを選択してください。」の文言は、`PostController` の `postRules()` / `postMessages()` で `store` と `update` が共通に使っている。変えると編集の更新にも影響する
- 本文の共通ルールは `ValidatesContent`。中身や文言を変えると、ポストの編集・リプライ・`PostContentLimitTest` の期待値にも響く
- 本文は `\r\n` を `\n` にそろえてから数え、保存する本文の改行も `\n`
- 作成画面の残り文字数の数え方は、編集画面と同じ（絵文字などは 1 字、改行は 1 字）。変えると両画面の表示がずれる
