<?php

namespace RichardHulbert\Revisions\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use RichardHulbert\Revisions\RevisionsModel;

class Post extends RevisionsModel
{
    use SoftDeletes;

    protected $fillable = ['title', 'template_id'];

    public function templateOnBranch(): HasOne
    {
        return $this->hasOneRevision(Template::class, 'template_id');
    }

    public function templatePublic(): HasOne
    {
        return $this->hasOneRevision(Template::class, 'template_id', 1);
    }
}
