# Revisions Model for Laravel

Append-only, branch-aware revision models for Eloquent. A record is never
updated in place: `update()` clones the row with the new values, so every
change stacks up as a new revision and any change can be permanently rolled
back.

## The pattern

Every table using this pattern has three extra columns:

| Column      | Meaning                                                            |
|-------------|--------------------------------------------------------------------|
| `prime`     | The id of the first record in the chain. All revisions share it.   |
| `branch_id` | The branch the revision was made on. Branch `1` is public/published by default. |
| `user_id`   | The user who made the revision.                                    |

A "record" as the rest of your app sees it is identified by its `prime`;
the individual rows are its revisions. The newest row per branch is the
current state of that record on that branch.

## Installation

```bash
composer require richardhulbert/revisions-model
```

The service provider is auto-discovered. Publish the config if you need to
change any defaults:

```bash
php artisan vendor:publish --tag=revisions-config
```

```php
// config/revisions.php
return [
    'branch_model'          => \App\Models\Branch::class, // your branch model
    'user_model'            => null,      // null = default auth provider model
    'public_branch_id'      => 1,         // the published/public branch
    'user_branch_attribute' => 'branch',  // attribute on the user holding their current branch id
    'default_user_id'       => 1,         // author recorded when nobody is logged in
];
```

## Migrations

A `revisions()` Blueprint macro adds the three columns:

```php
Schema::create('pages', function (Blueprint $table) {
    $table->id();
    $table->revisions();          // prime, user_id, branch_id
    $table->string('title');
    // ...
    $table->softDeletes();
    $table->timestamps();
});
```

## Usage

Extend `RevisionsModel` instead of `Model`. The revision columns are merged
into `$fillable` automatically — no constructor boilerplate needed:

```php
use RichardHulbert\Revisions\RevisionsModel;

class Page extends RevisionsModel
{
    protected $fillable = ['title', 'slug', 'blocks'];
}
```

### Creating and updating

```php
$page = Page::new(['title' => 'Home']);       // starts a chain: prime = id

$draft = $page->update(['title' => 'Home!']); // NEW row on the user's branch
$live  = $page->update(['title' => 'Home!', 'branch_id' => 1]); // explicit branch
```

`update()` returns the new revision; the row you called it on is untouched.
`branch_id` defaults to the logged-in user's branch, `user_id` to the
logged-in user.

### Reading

```php
$page->lastRevision()->first();          // user's branch if it has revisions, else public
$page->lastRevisionWithBranch(3)->first();
$page->lastPublicRevision()->first();

$page->revisions();      // history: latest revision per branch per day
$page->revisions(3);     // history on one branch

Page::allLatest()->get();   // newest revision of every record (public branch)
Page::allLatest(3)->get();  // ... on branch 3
```

### Relations

```php
$revision->prime;    // the first record of the chain
$revision->branch;   // the branch (even if soft-deleted)
$revision->owner;    // the author (even if soft-deleted)
```

To point at another revisioned model, store its `prime` in a column and use
`hasOneRevision()` — an eager-loadable hasOne that resolves to the latest
revision on a branch:

```php
class Page extends RevisionsModel
{
    public function templateOnBranch(): HasOne
    {
        return $this->hasOneRevision(Template::class, 'template_id');      // user's branch
    }

    public function templatePublic(): HasOne
    {
        return $this->hasOneRevision(Template::class, 'template_id', 1);   // public branch
    }
}

Page::with('templateOnBranch')->get();  // works with with()/load()
```

### Deleting

Deleting any revision deletes the **prime** record of the chain (with soft
deletes, this marks the whole record as deleted without losing history):

```php
$revision->delete();
```

## Testing

```bash
composer install
composer test
```

## License

MIT — see [LICENSE.md](LICENSE.md).
