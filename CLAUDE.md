# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## このリポジトリについて

COACHTECH の教材用の小さな Laravel 10 投稿アプリ（post-app）。Tutorial 13〜15 でこのアプリ 1 本を題材に、設計（13）→ 機能追加（14）→ 仕組み化（15）を進める。リポジトリの大半は教材の成果物（設計書）で、アプリ本体は小さい。

- `docs/` … 学習者自身が作る設計書。章ごとに `docs/13-N/` に置く。ファイル名の先頭が題材を表す：`post-`＝この投稿アプリ、`cafe-`＝章末演習のカフェのモバイルオーダーアプリ、`register-`＝会員登録のテスト設計。図は `.drawio` とその書き出し `.png` をペアで置く。機能追加の設計書は `docs/` 直下に置く（例：`docs/reply.md`＝リプライ機能。実装はこの設計書に沿って行う）。
- `answers/` … Tutorial 13 の解答例。アプリのコードとは無関係で、機能追加時に読む必要はない。学習者の `docs/` を `answers/` の内容で上書き・修正しないこと（答え合わせは学習者自身が行う）。
- 設計書・コミットメッセージ・UI 文言・コード中のコメントは日本語。Tutorial 13 の設計書のコミットは `tutorial13-N: <内容>` の形式。機能追加のコミットは `tutorial13-N:` を付けず、内容を日本語で書く。

## コマンド

すべて Laravel Sail（Docker）経由で実行する。PHP / MySQL のバージョンはコンテナが決める（PHP 8.5 runtime、MySQL 8.4）。

```bash
./vendor/bin/sail up -d                      # 起動（http://localhost）
./vendor/bin/sail down                       # 停止
./vendor/bin/sail artisan migrate --seed     # テーブル作成＋練習用データ
./vendor/bin/sail artisan migrate:fresh --seed  # DB を作り直す
./vendor/bin/sail pint                       # コード整形（laravel/pint）
```

- 初回起動直後の `migrate` で「Connection refused」が出るのは MySQL の起動待ち。少し待って再実行する。

練習用アカウント（`--seed`）：`usera@example.com` / `userb@example.com`、パスワードはどちらも `password`。シーダーはユーザー 7 人（上の 2 人＋`mio`・`sota`・`yui`・`kento`・`akari` の `@example.com`、パスワードは同じ）、ポスト 25 件（usera・userb は各 4 件）、カテゴリ 3 件（お知らせ・技術メモ・雑記）を入れる。リプライは入れない。

## 作業の決まり

依頼文に毎回書かなくてよい前提。依頼で個別に指定があればそちらを優先する。

- **変えないもの**：機能追加・修正では、タイムライン（`posts.index`）の今の動きを変えない。今の動きは「全ポストを新しい順（`latest()`）に、ページ分けなしで並べる」で、一覧に出る項目・並び順・リンク先も含む。追加する機能のためにここを変える必要が出たら、変えずに止めて確認する。依頼に「変えないもの」が他にあれば、それも同様に守る。
- **入力チェックのメッセージは日本語**で出す。訳は `lang/ja/validation.php` に足し、使うルールと項目名が足りなければそこへ追加する。コントローラー内でメッセージを個別指定する場合も日本語にする。

### PR 本文の型

PR 本文は毎回次の見出し・順で、日本語で書く。

1. **テスト結果**：実行したコマンドと、件数・成否。
2. **受け入れ条件ごとの判定**：Issue（または設計書）の受け入れ条件を 1 行ずつ、表で「条件｜判定（満たす / 満たさない / 未確認）｜根拠（テスト名や確認した画面）」の形にする。
3. **守ることの判定**：上の「変えないもの」と依頼で指定された守ることを 1 つずつ挙げ、守れているかを根拠つきで書く。
4. **確認していないこと**：テストでも画面でも確かめていない点、環境の都合で回せなかったもの。無ければ「なし」と書く。
5. **Closes #N**：対応する Issue 番号。最終行に置く。

## アーキテクチャ

- **認証は Laravel Fortify**（自前の認証コントローラーはない）。`FortifyServiceProvider` でログイン／登録ビュー（`resources/views/auth/`）を指定し、登録処理は `app/Actions/Fortify/CreateNewUser.php` がバリデーションとユーザー作成を担う。有効な機能は `config/fortify.php` の `features`（registration・resetPasswords のみ）。ログイン後の遷移先は `config/fortify.php` の `'home' => '/posts'`。
- **投稿機能**は `routes/web.php` の `auth` ミドルウェアグループ内のみ：一覧・詳細（`posts.show`）・新規作成（`posts.create` / `posts.store`）・編集・更新・削除（`PostController`）。新規作成は専用画面（`resources/views/posts/create.blade.php`、編集画面と同じ作り）で、入口はタイムラインのヘッダーの「ポストを書く」リンク。タイトル・トピック・本文を受け取り、投稿者は入力ではなくログイン中のユーザーから決める。`GET /posts/create` は `posts.show` より前に書く（後ろだと `{post}` に取られて404になる）。トピックは「選択してください」（空）が先頭で、未選択はエラー。送信後はタイムラインに戻る。入力チェックは更新と同じ内容で `PostController` 内に持つ（`store` と `update` で共通のルールメソッド）。
- **リプライ機能**：ポスト詳細（`resources/views/posts/show.blade.php`）にリプライの一覧と送信フォームがある。`ReplyController` が送信（`replies.store`、`POST /posts/{post}/replies`）と削除（`replies.destroy`、`DELETE /replies/{reply}`）を担い、どちらも送信・削除後はポスト詳細へ戻る。リプライは平らな一覧で古い順（`created_at`、`id`）、本文は必須・140字以内（`\r\n` を `\n` にそろえてから数える。ポストの `update` と同じ）。
- **認可は `PostPolicy`**（投稿者本人のみ update / delete 可）と `ReplyPolicy`（投稿者本人のみ delete 可。ポストの持ち主でも他人のリプライは消せない）。`AuthServiceProvider::$policies` は空で、ポリシーは命名規約による自動検出で `Post`・`Reply` に紐づく。コントローラーは各アクションで `$this->authorize()` を呼び、他人の投稿・リプライは 403。ビュー側も `@can` でボタンの表示を切り替える。
- **データ**：`users` 1—* `posts` *—1 `categories`、`users` 1—* `replies` *—1 `posts`。`posts.user_id`、`replies.user_id`、`replies.post_id` は `cascadeOnDelete`（ユーザー・ポストを消すとリプライも消える）、`posts.category_id` は制約のみ。バリデーションはコントローラー内の `$request->validate()`（FormRequest は未使用）。Factory は `UserFactory`・`PostFactory`・`ReplyFactory`。
- **ビュー**は Blade の単独ファイルで、レイアウト継承なし・CSS は各ファイルの `<style>` にインライン（Vite / npm は実質未使用）。
- **言語設定**：`config/app.php` の `locale` は `ja`（`fallback_locale` は `en`）。入力チェックの訳は `lang/ja/validation.php`（使っているルールと項目名のみ）。`auth.php`・`passwords.php`・`ja.json` は無いため、ログイン失敗・パスワード再設定・403/404 画面の文言は英語のまま。
