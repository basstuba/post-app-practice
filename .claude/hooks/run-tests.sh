#!/bin/bash
# ファイル編集のたびにテストを回す（PostToolUse）。
# sail が無い環境と、worktree ではテストを回さない（worktree は実装だけ。テストはメインで回す）。
test -x vendor/bin/sail || exit 0
"$(dirname "$0")/in-worktree.sh" && exit 0
./vendor/bin/sail artisan test >&2 || exit 2
