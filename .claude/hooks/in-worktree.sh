#!/bin/bash
# git worktree の中なら 0、メインの作業ツリーなら 1 を返す。
# worktree では .git の置き場所（git-dir）が共通の .git（git-common-dir）と別になる。
gd=$(realpath "$(git rev-parse --git-dir 2>/dev/null)" 2>/dev/null) || exit 1
cd_=$(realpath "$(git rev-parse --git-common-dir 2>/dev/null)" 2>/dev/null) || exit 1
[ "$gd" != "$cd_" ]
