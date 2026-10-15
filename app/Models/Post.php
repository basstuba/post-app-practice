<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category_id',
        'title',
        'content',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function likedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'likes')->withTimestamps();
    }

    public function bookmarkedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'bookmarks')->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        // タグの id 順
        return $this->belongsToMany(Tag::class)->orderBy('tags.id');
    }

    public function isEdited(): bool
    {
        // 列は足さず、作成後に更新されたか（updated_at が created_at より後か）で判断する
        return $this->updated_at->gt($this->created_at);
    }

    public function replies(): HasMany
    {
        // 古い順。同じ秒のリプライは id 順にして並びを安定させる
        return $this->hasMany(Reply::class)->orderBy('created_at')->orderBy('id');
    }
}
