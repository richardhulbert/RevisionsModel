<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Branch model
    |--------------------------------------------------------------------------
    | The Eloquent model that represents a branch. Every revision row carries
    | a branch_id pointing at one of these. The branch() relation on a
    | RevisionsModel resolves against this class.
    */
    'branch_model' => \App\Models\Branch::class,

    /*
    |--------------------------------------------------------------------------
    | User model
    |--------------------------------------------------------------------------
    | The model recorded as the author of a revision (user_id) and returned
    | by the owner() relation. When null, the default auth provider model
    | (config auth.providers.users.model) is used.
    */
    'user_model' => null,

    /*
    |--------------------------------------------------------------------------
    | Public branch id
    |--------------------------------------------------------------------------
    | The id of the branch that represents published / public content. Used
    | as the fallback whenever no branch is given and no user is logged in.
    */
    'public_branch_id' => 1,

    /*
    |--------------------------------------------------------------------------
    | User branch attribute
    |--------------------------------------------------------------------------
    | The attribute on the authenticated user that holds the id of the branch
    | they are currently working on.
    */
    'user_branch_attribute' => 'branch',

    /*
    |--------------------------------------------------------------------------
    | Default user id
    |--------------------------------------------------------------------------
    | The user_id written on a revision created while no user is
    | authenticated (e.g. from seeders or console commands).
    */
    'default_user_id' => 1,

];
