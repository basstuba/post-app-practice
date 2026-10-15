#!/bin/bash
# worktree でテストを回すコマンドを止める（PreToolUse / Bash）。exit 2 でコマンドは実行されない。
"$(dirname "$0")/in-worktree.sh" || exit 0
if grep -Eq '(artisan[[:space:]]+test|sail[[:space:]]+test|phpunit|vendor/bin/pest)'; then
  echo "worktree ではテストを実行しません。テストは書くだけにして、実行はメインの作業ツリーで行います。" >&2
  exit 2
fi
exit 0
