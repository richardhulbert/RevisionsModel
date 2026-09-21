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
$revision->branch;   // the branch (even if soft-deleted)
$revision->owner;    // the author (even if soft-deleted)
```

`$revision->prime` is the chain's id, an integer, not a relation. To fetch
the chain's first row, query it: `Page::find($revision->prime)`.

Never give a relation (or any public method) the same name as a column. When
that column is missing from a model's attributes, Eloquent resolves the
method as a relation instead, and a relation that reads its own missing
column recurses until memory runs out.

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
## Finding the current revision by value: `currentWhere()`

*Since 0.6.0.*

```php
$page = Page::currentWhere('slug', '/about')->firstOrFail();
```

`currentWhere($column, $value)` returns the **current revision** of every **live chain**
whose `$column` equals `$value`.

- **Current** means the latest live revision on the authenticated user's branch. A chain with
  no revision on that branch falls back to its latest revision on the public branch. The
  fallback is decided per chain, so one query can return a draft of one record and the
  published version of another.
- **Live** means the chain's prime row is not soft-deleted. Deleting a record soft-deletes its
  prime and leaves the later revisions untouched, because a revision is never mutated. This
  check is what hides them.
- A revision soft-deleted on its own is skipped, and its chain falls back to the previous live
  revision.

To list every current record, use `allLatest()`. To find records by a value, use
`currentWhere()`.

### Why not just `where()`?

A chain is a history, and its older rows keep their old values.
`Page::where('slug', '/about')` matches every revision that ever had that slug, so a page
renamed from `/about` to `/company` is still found at `/about`.

`currentWhere()` decides which revision is current *before* it compares the value. A renamed
record matches its new value only, and the old value is free for another record to use.

Matching against the prime row with `whereColumn('id', 'prime')` has the opposite fault: the
prime carries the value the record was *created* with, so a rename is never seen.

### Composing

It is an ordinary scope, so you can add clauses, order and paginate. It adds no join, so
unqualified column names stay unambiguous.

```php
Page::currentWhere('template_id', $template->prime)
    ->where('status', '>', 0)
    ->get();
```

### The branch

The branch comes from the authenticated user's branch attribute
(`revisions.user_branch_attribute`), the same source `update()` uses. An unauthenticated
request sees the public branch only.

### Safety

`$column` is interpolated into SQL. Pass only column names written in your own code, never
request input. `$value` is always bound.

### Performance

Each candidate row runs two small correlated subqueries. The value filter narrows the
candidates first, and an index on `(prime, branch_id, id)` keeps the subqueries cheap.


### Deleting

Deleting any revision deletes the **prime** record of the chain (with soft
deletes, this marks the whole record as deleted without losing history):

```php
$revision->delete();
```

The prime row is deleted as a model, whichever revision you call `delete()`
on, so `deleting`, `deleted` and (with soft deletes) `trashed` fire on the
prime row, and observers such as Laravel Scout's hear about it. A `deleting`
listener returning `false` stops the delete. If the prime row is already gone,
`delete()` returns `null` and fires nothing.

Only the prime row is marked deleted; later revisions are left as they are.
To tell whether a chain is deleted, check its prime row.

`allLatest()` does this check but be careful if you are searching for a model you will need to include a join to an alias to the table this:
```sql
SELECT
	T.*
FROM
	`some_table` T
	INNER JOIN `some_table` as T1 on T1.id = T.prime
WHERE
	T.`slug` = '/about' AND T1.deleted_at IS NUlL
```

At the moment `LastRevision()` a scoped method does not look at the parent prime to check if it is deleted!
This is by design it is a method on a hydrated model so at that point we assume the application has checked to see if the model is 'deleted'

## Testing

```bash
composer install
composer test
```

## License

MIT — see [LICENSE.md](LICENSE.md).
