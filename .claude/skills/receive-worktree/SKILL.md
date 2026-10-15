---
name: receive-worktree
description: worktree で作られた機能のブランチ（origin/worktree-<機能名>）を受け取り、メインの作業ツリーで receive-<機能名> ブランチに取り込んで、マイグレーションと全件テストを回して結果を報告する。失敗しても直さず報告だけする。「worktree-<機能名> を取り込んでテストして」「receive-<機能名> を作ってテストを回して」「worktree で修正したので取り込み直して」など、worktree の成果をメインで受け取ってテストする依頼では、必ずこの Skill を使う。
argument-hint: <機能名（例：search）>
---

# worktree の成果を受け取ってテストする

worktree（実装担当）が push した `origin/worktree-<機能名>` を、メインの作業ツリーで受け取り、テストを回して報告する。**報告だけで、コードは直さない。** worktree ではテストを回さない決まりなので、この受け取りが最初のテスト実行になる。直すのは worktree 側（修正の指示文は `fix-request-draft` で作る）。

## 入力

- 機能名：$ARGUMENTS（例：`search` → `origin/worktree-search` を `receive-search` に取り込む）。空なら `git branch -r` の `origin/worktree-*` から候補を出して確認する。

## 手順

1. **作業ツリーを確かめる**：`git status --short` が空で、メインの作業ツリー（`.claude/worktrees/` の中ではない）であること。未コミットの変更があれば、触らずに止めて確認する。
2. **取り込む**
   - `git fetch origin`
   - `receive-<機能名>` がまだ無い（初回）：`git switch -c receive-<機能名> origin/worktree-<機能名>`
   - すでにある（worktree で直したあとの再取り込み）：`git switch receive-<機能名>` → `git pull origin worktree-<機能名>`
   - `origin/worktree-<機能名>` が無ければ、push されていないということ。そう報告して止まる。
3. **マイグレーション**：`git diff --name-only main...HEAD -- database/migrations` に新しいファイルがあれば `./vendor/bin/sail artisan migrate`。無ければ飛ばす（飛ばしたと報告に書く）。
4. **全件テスト**：`./vendor/bin/sail artisan test`。絞り込まない。
5. **報告**

## 報告の形式

- 取り込んだもの：ブランチ名、`git log main..HEAD --oneline` の要約（初回か再取り込みか）
- マイグレーション：実行した / 新しいものは無かった
- テスト結果：**件数と成否**（例：`141 件成功、2 件失敗、deprecated 2 件`）
- 失敗があれば、1 件ずつ：テスト名、`ファイル:行`、失敗メッセージの要点。**直さない。** 原因が読み取れるなら「〜と読みました」と添える
- deprecated などの警告は、件数と出どころを別に書く

## やらないこと

- テストが落ちても、コードやテストを直すこと（`.claude/rules/testing.md` の「落ちたら直す」はこの Skill には当てはまらない。直すのは worktree 側）
- `main` へのマージ、push、`migrate:fresh`
- 未コミットの変更を stash／破棄してまで切り替えること
