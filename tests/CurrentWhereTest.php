<?php

namespace RichardHulbert\Revisions\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
// ASSUMPTION: Post has a string `title` column and uses SoftDeletes.
use RichardHulbert\Revisions\Tests\Fixtures\Post;

/**
 * currentWhere($column, $value): the current revision of every live chain
 * whose $column equals $value.
 *
 * "Current" is decided per chain BEFORE the value is compared. Filtering on
 * the value first would find an older row still carrying an old value and
 * serve the chain from a name it no longer has.
 */
class CurrentWhereTest extends TestCase
{
    private function chain(string $title, int $branch = 1): Post
    {
        return Post::new(['title' => $title, 'user_id' => 1, 'branch_id' => $branch]);
    }

    /** authBranchId() reads the user's `branch` attribute; no users table needed. */
    private function actingOnBranch(int $branch): void
    {
        Auth::setUser(new GenericUser(['id' => 1, 'branch' => $branch]));
    }

    public function test_it_matches_the_latest_revision_of_a_chain(): void
    {
        $first = $this->chain('abc');
        $second = $first->update(['title' => 'abc']);
        $third = $second->update(['title' => 'abc']);

        // sole(): exactly one row per chain, and it is the newest
        $this->assertSame($third->id, Post::currentWhere('title', 'abc')->sole()->id);
    }

    public function test_a_renamed_chain_matches_its_new_value_only(): void
    {
        $first = $this->chain('abc');
        $renamed = $first->update(['title' => 'xyz']);

        $this->assertSame($renamed->id, Post::currentWhere('title', 'xyz')->sole()->id);
        $this->assertNull(Post::currentWhere('title', 'abc')->first());
    }

    public function test_a_value_given_up_by_a_rename_can_be_used_by_another_chain(): void
    {
        $this->chain('abc')->update(['title' => 'xyz']);
        $other = $this->chain('abc');

        $this->assertSame($other->id, Post::currentWhere('title', 'abc')->sole()->id);
    }

    public function test_a_deleted_chain_is_not_matched(): void
    {
        $first = $this->chain('abc');
        $latest = $first->update(['title' => 'abc']);

        // the admin path: delete through the latest revision
        $latest->delete();

        // the latest row itself is untouched; the scope is what hides it
        $this->assertFalse(Post::withTrashed()->find($latest->id)->trashed());
        $this->assertNull(Post::currentWhere('title', 'abc')->first());
    }

    public function test_an_individually_deleted_latest_revision_falls_back_to_the_previous_one(): void
    {
        $first = $this->chain('abc');
        $second = $first->update(['title' => 'abc']);
        $third = $second->update(['title' => 'abc']);

        // soft-delete that one row only, as parent::delete() would
        Post::whereKey($third->id)->delete();

        $this->assertSame($second->id, Post::currentWhere('title', 'abc')->sole()->id);
    }

    public function test_a_guest_sees_the_public_branch_only(): void
    {
        $first = $this->chain('abc');
        $first->update(['title' => 'xyz', 'branch_id' => 2]);

        $this->assertSame($first->id, Post::currentWhere('title', 'abc')->sole()->id);
        $this->assertNull(Post::currentWhere('title', 'xyz')->first());
    }

    public function test_a_user_sees_their_own_branch_revision(): void
    {
        $first = $this->chain('abc');
        $draft = $first->update(['title' => 'xyz', 'branch_id' => 2]);

        $this->actingOnBranch(2);

        $this->assertSame($draft->id, Post::currentWhere('title', 'xyz')->sole()->id);
        // on branch 2 the chain is 'xyz', so the public name no longer matches
        $this->assertNull(Post::currentWhere('title', 'abc')->first());
    }

    public function test_a_user_falls_back_to_public_for_a_chain_not_on_their_branch(): void
    {
        $public = $this->chain('abc')->update(['title' => 'abc']);

        $this->actingOnBranch(2);

        $this->assertSame($public->id, Post::currentWhere('title', 'abc')->sole()->id);
    }

    public function test_another_branch_is_invisible(): void
    {
        $first = $this->chain('abc');
        $first->update(['title' => 'xyz', 'branch_id' => 3]);

        $this->actingOnBranch(2);

        $this->assertSame($first->id, Post::currentWhere('title', 'abc')->sole()->id);
        $this->assertNull(Post::currentWhere('title', 'xyz')->first());
    }

    public function test_it_returns_one_current_revision_per_matching_chain(): void
    {
        $a = $this->chain('shared')->update(['title' => 'shared']);
        $b = $this->chain('shared');
        $this->chain('other');

        $expected = collect([$a->id, $b->id])->sort()->values()->all();
        $actual = Post::currentWhere('title', 'shared')->pluck('id')->sort()->values()->all();

        $this->assertSame($expected, $actual);
    }

    /** Pins the reason the scope uses whereExists rather than a self-join. */
    public function test_it_composes_with_unqualified_clauses(): void
    {
        $latest = $this->chain('abc')->update(['title' => 'abc']);

        $ids = Post::currentWhere('title', 'abc')
            ->where('title', 'abc')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([$latest->id], $ids);
    }

    /** Pins against a join ever being reintroduced and clobbering attributes. */
    public function test_the_returned_model_carries_its_own_attributes(): void
    {
        $first = $this->chain('abc');
        $latest = $first->update(['title' => 'abc']);

        $found = Post::currentWhere('title', 'abc')->sole();

        $this->assertSame($latest->id, $found->id);
        $this->assertSame($first->id, $found->prime);
    }
}
