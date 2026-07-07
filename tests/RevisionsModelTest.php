<?php

namespace RichardHulbert\Revisions\Tests;

use RichardHulbert\Revisions\Tests\Fixtures\Branch;
use RichardHulbert\Revisions\Tests\Fixtures\Post;
use RichardHulbert\Revisions\Tests\Fixtures\Template;
use RichardHulbert\Revisions\Tests\Fixtures\User;

class RevisionsModelTest extends TestCase
{
    public function test_revision_columns_are_merged_into_fillable(): void
    {
        $fillable = (new Post)->getFillable();

        $this->assertContains('prime', $fillable);
        $this->assertContains('user_id', $fillable);
        $this->assertContains('branch_id', $fillable);
        $this->assertContains('title', $fillable);
    }

    public function test_new_sets_prime_to_own_id(): void
    {
        $post = Post::new(['title' => 'First']);

        $this->assertSame($post->id, $post->prime);
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'prime' => $post->id]);
    }

    public function test_update_creates_a_new_revision_and_keeps_the_original(): void
    {
        $user = $this->actingAsUserOnBranch(2);

        $post = Post::new(['title' => 'First']);
        $revision = $post->update(['title' => 'Second']);

        $this->assertNotSame($post->id, $revision->id);
        $this->assertSame($post->id, $revision->prime);
        $this->assertSame('Second', $revision->title);
        $this->assertSame(2, $revision->branch_id);
        $this->assertSame($user->id, $revision->user_id);

        // the original row is untouched
        $this->assertSame('First', $post->fresh()->title);
        $this->assertSame(2, Post::count());
    }

    public function test_update_accepts_an_explicit_branch(): void
    {
        $this->actingAsUserOnBranch(2);

        $post = Post::new(['title' => 'First']);
        $revision = $post->update(['title' => 'Published', 'branch_id' => 1]);

        $this->assertSame(1, $revision->branch_id);
    }

    public function test_update_without_a_user_falls_back_to_defaults(): void
    {
        $post = Post::new(['title' => 'First']);
        $revision = $post->update(['title' => 'Second']);

        $this->assertSame(1, $revision->branch_id);
        $this->assertSame(1, $revision->user_id);
    }

    public function test_last_public_and_branch_revisions(): void
    {
        $this->actingAsUserOnBranch(2);

        $post = Post::new(['title' => 'v1']);
        $post->update(['title' => 'public v2', 'branch_id' => 1]);
        $branchRev = $post->update(['title' => 'draft v3']);

        $this->assertSame('public v2', $post->lastPublicRevision()->first()->title);
        $this->assertSame('draft v3', $post->lastRevisionWithBranch()->first()->title);
        $this->assertSame($branchRev->id, $post->lastRevision()->first()->id);
    }

    public function test_last_revision_falls_back_to_public_branch(): void
    {
        $this->actingAsUserOnBranch(2);

        $post = Post::new(['title' => 'v1', 'branch_id' => 1]);
        $public = $post->update(['title' => 'public v2', 'branch_id' => 1]);

        // nothing on branch 2 yet, so the public revision is returned
        $this->assertSame($public->id, $post->lastRevision()->first()->id);
    }

    public function test_prime_branch_and_owner_relations(): void
    {
        $user = $this->actingAsUserOnBranch(2);

        $post = Post::new(['title' => 'v1']);
        $revision = $post->update(['title' => 'v2']);

        $this->assertSame($post->id, $revision->prime()->first()->id);
        $this->assertSame('draft', $revision->branch->name);
        $this->assertSame($user->id, $revision->owner->id);
    }

    public function test_branch_and_owner_survive_soft_deletion(): void
    {
        $user = $this->actingAsUserOnBranch(2);

        $post = Post::new(['title' => 'v1']);
        $revision = $post->update(['title' => 'v2']);

        Branch::find(2)->delete();
        $user->delete();

        $this->assertSame('draft', $revision->fresh()->branch->name);
        $this->assertSame($user->id, $revision->fresh()->owner->id);
    }

    public function test_revisions_lists_the_history_of_a_chain(): void
    {
        $this->actingAsUserOnBranch(2);

        $post = Post::new(['title' => 'v1', 'branch_id' => 1]);
        $post->update(['title' => 'draft v2']);
        $latestDraft = $post->update(['title' => 'draft v3']);

        // a different chain must not leak in
        Post::new(['title' => 'other chain']);

        $all = $post->revisions();
        $this->assertEqualsCanonicalizing(
            [$post->id, $latestDraft->id], // latest per branch (per day)
            $all->pluck('id')->all()
        );

        $draftOnly = $post->revisions(2);
        $this->assertSame([$latestDraft->id], $draftOnly->pluck('id')->all());
    }

    public function test_all_latest_returns_the_newest_revision_per_chain(): void
    {
        $this->actingAsUserOnBranch(2);

        $a = Post::new(['title' => 'a1', 'branch_id' => 1]);
        $a2 = $a->update(['title' => 'a2', 'branch_id' => 1]);
        $b = Post::new(['title' => 'b1', 'branch_id' => 1]);
        $draft = $a->update(['title' => 'a3 draft']); // branch 2

        $this->assertEqualsCanonicalizing(
            [$a2->id, $b->id],
            Post::allLatest()->pluck('id')->all()
        );
        $this->assertSame([$draft->id], Post::allLatest(2)->pluck('id')->all());
    }

    public function test_deleting_the_prime_deletes_it(): void
    {
        $post = Post::new(['title' => 'v1']);
        $post->delete();

        $this->assertSoftDeleted('posts', ['id' => $post->id]);
    }

    public function test_deleting_a_revision_deletes_the_prime(): void
    {
        $this->actingAsUserOnBranch(2);

        $post = Post::new(['title' => 'v1']);
        $revision = $post->update(['title' => 'v2']);

        $revision->delete();

        $this->assertSoftDeleted('posts', ['id' => $post->id]);
        $this->assertNotSoftDeleted('posts', ['id' => $revision->id]);
    }

    public function test_has_one_revision_resolves_per_branch(): void
    {
        $this->actingAsUserOnBranch(2);

        $template = Template::new(['name' => 'tpl v1', 'branch_id' => 1]);
        $publicTpl = $template->update(['name' => 'tpl public', 'branch_id' => 1]);
        $draftTpl = $template->update(['name' => 'tpl draft']); // branch 2

        $post = Post::new(['title' => 'page', 'template_id' => $template->prime]);

        $this->assertSame($draftTpl->id, $post->templateOnBranch()->first()->id);
        $this->assertSame($publicTpl->id, $post->templatePublic()->first()->id);

        // stays eager-loadable
        $loaded = Post::with('templateOnBranch', 'templatePublic')->find($post->id);
        $this->assertSame($draftTpl->id, $loaded->templateOnBranch->id);
        $this->assertSame($publicTpl->id, $loaded->templatePublic->id);
    }

    public function test_has_one_revision_defaults_to_public_branch_when_logged_out(): void
    {
        $template = Template::new(['name' => 'tpl v1', 'branch_id' => 1]);
        $publicTpl = $template->update(['name' => 'tpl public', 'branch_id' => 1]);

        $post = Post::new(['title' => 'page', 'template_id' => $template->prime]);

        $this->assertSame($publicTpl->id, $post->templateOnBranch()->first()->id);
    }

    public function test_blueprint_macro_created_the_revision_columns(): void
    {
        $user = User::create(['name' => 'Someone', 'branch' => 1]);

        $post = Post::create(['title' => 'raw', 'prime' => 99, 'user_id' => $user->id, 'branch_id' => 1]);

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'prime' => 99, 'branch_id' => 1]);
    }
}
