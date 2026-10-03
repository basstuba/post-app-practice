# モバイルオーダーアプリ テーブル定義書

admins（管理者）
| カラム名 | データ型 | 制約 | 説明 |
|:--------|:--------|:-----|:-----|
|id|BIGINT|PRIMARY KEY,AUTO_INCREMENT|管理者を1人づつ区別する番号|
|name|VARCHAR(20)|NOT NULL|管理者名|
|mail|VARCHAR(30)|UNIQUE,NOT NULL|メールアドレス|
|password|VARCHAR(255)|NOT NULL|パスワード|
|created_at|TIMESTAMP|-|作成日時|
|updated_at|TIMESTAMP|-|更新日時|

items（商品）
| カラム名 | データ型 | 制約 | 説明 |
|:--------|:--------|:-----|:-----|
|id|BIGINT|PRIMARY KEY,AUTO_INCREMENT|商品を1つづつ区別する番号|
|name|VARCHAR(255)|NOT NULL|商品名|
|price|INTEGER|NOT NULL|商品価格|
|detail|VARCHAR(255)|NOT NULL|商品説明文|
|stock|INTEGER|NOT NULL|在庫数|
|category|ENUM('drink','food')|NOT NULL|商品の種類（ドリンクかフード）|
|created_at|TIMESTAMP|-|作成日時|
|updated_at|TIMESTAMP|-|更新日時|

orders（注文）
| カラム名 | データ型 | 制約 | 説明 |
|:--------|:--------|:-----|:-----|
|id|BIGINT|PRIMARY KEY,AUTO_INCREMENT|注文を1件づつ区別する番号|
|item_id|BIGINT|FOREIGN KEY,NOT NULL|商品|
|order_number|VARCHAR(6)|UNIQUE,NOT NULL|注文番号|
|order_quantity|INTEGER|NOT NULL|注文個数|
|order_price|INTEGER|NOT NULL|注文したときの商品価格|
|status|BOOLEAN|DEFAULT false,NOT NULL|注文の状態。受け渡し済になるとtrueになる|
|created_at|TIMESTAMP|-|作成日時|
|updated_at|TIMESTAMP|-|更新日時|
