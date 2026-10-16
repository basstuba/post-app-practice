---
name: post-merge-cleanup
description: worktree の機能の PR がマージされたあとの片付けをする。メインを最新にし、worktree・ローカルブランチ（worktree-<機能名>・receive-<機能名>）・リモートブランチ origin/worktree-<機能名> を削除する。削除前に対象を表示し、マージされていないものは消さない。「PR をマージしたので main を最新にして worktree とブランチを片付けて」「origin/worktree-<機能名> も消して」など、マージ後の後始末の依頼で使う。
argument-hint: <機能名（例：search）> [--fresh]
disable-model-invocation: true
---

# マージ後の片付け

対象：$ARGUMENTS

呼ばれた時点で、次の削除は依頼されたものとして進める。ただし**消す前に必ず対象を表示**し、マージされていない・未コミットの変更があるものは消さずに止まって確認する。

## 手順

1. **マージ済みか確かめる**：`gh pr list --state merged --head worktree-<機能名>`。マージ済みの PR が無ければ止まる。
2. **メインを最新にする**：`git switch main` → `git pull --ff-only origin main`。ff できなければ止まって報告する。
3. **対象を洗い出して表示する**
   - `git worktree list`（`.claude/worktrees/<機能名>` があるか）
   - `git branch --list 'worktree-<機能名>' 'receive-<機能名>'`
   - `git ls-remote --heads origin worktree-<機能名>`
   - worktree に未コミットの変更がある（`git -C <path> status --short` が空でない）、または push 済みでない commit がある場合は、消さずに止まる。
4. **消す**（この順）
   - `git worktree remove <path>`（`--force` は付けない）
   - `git branch -d worktree-<機能名> receive-<機能名>`（存在するものだけ。`-d` が拒否したら `-D` にせず、マージされていないとして報告する）
   - `git push origin --delete worktree-<機能名>`（リモートが残っていれば）
   - `git fetch --prune origin`
5. **`--fresh` が付いているときだけ** `./vendor/bin/sail artisan migrate:fresh --seed`（DB が作り直される。付いていなければ実行せず、必要なら提案だけ）。
6. **CLAUDE.md が現状とずれていないか確認する**（直さず、ずれを報告するだけ）
   - 今回の機能で入ったもの：`git diff <マージ前の main>..main --stat` と、マージされた PR の変更ファイル（`gh pr view <N> --json files`）。
   - CLAUDE.md の次の箇所と突き合わせる：「コマンド」（増えたコマンド・seed）、「アーキテクチャ」（ルートの順序、認可、バリデーションの置き場所、ビューの書き方、言語設定）、「作業の決まり」。
   - 機能が増えた事実の一覧ではなく、**書いてあることが今のコードと食い違う箇所**と、**次の作業で守るべき新しい決まり**だけを挙げる。ずれが無ければ「ずれは無かった」と書く。
   - 修正は依頼されたときだけ行う。
7. **報告**：消したもの、残したもの（理由つき）、CLAUDE.md の確認結果、`git status` と `git branch -a` の最終状態。`docs/<機能名>.md` があり先頭に実装時点の記録の注記が無ければ、「注記が未追記です（`design-doc-draft` で追記できます）」と一言添える。

## やらないこと

- マージされていないブランチ・変更のある worktree の強制削除（`-D`、`--force`）
- `uat-shots/` や `.playwright-mcp/` の削除（`.gitignore` 済みで、この Skill の対象外）
- 依頼されていない `migrate:fresh`
