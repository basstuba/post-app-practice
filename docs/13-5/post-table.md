# 投稿アプリ テーブル定義書

posts（投稿）
| カラム名 | データ型 | 制約 | 説明 |
|:--------|:--------|:-----|:-----|
|id|BIGINT|PRIMARY KEY,AUTO_INCREMENT|投稿を1件づつ区別する番号|
|user_id|BIGINT|FOREIGN KEY,NOT NULL|投稿者|
|category_id|BIGINT|FOREIGN KEY,NOT NULL|カテゴリ|
|title|VARCHAR(255)|NOT NULL|投稿タイトル|
|content|TEXT|NOT NULL|投稿本文|
|created_at|TIMESTAMP|-|作成日時|
|updated_at|TIMESTAMP|-|更新日時|
