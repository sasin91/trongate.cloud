<?php

/**
 * Where a deploy reads an app's code: the deploy task's Source step asks it
 * which commit to build, then for that commit's files in the build directory.
 *
 *   Github_source     a GitHub repository (source_provider 'github')
 *   Internal_source   a trongate.cloud git repository (source_provider 'internal')
 *
 * Deploy_sources::for_app() picks the one for an app.
 */
interface Deploy_source {

    /**
     * What the deploy log calls the repository: owner/name, or p12.git
     */
    function name(): string;

    /**
     * Full SHA of the commit to build: $commit_sha (7 to 40 characters), or
     * the branch head when it is empty or when the deploy follows the branch
     * (Follows_branch)
     *
     * @param array<string, mixed> $deployment
     */
    function commit(string $branch, array $deployment, string $commit_sha, Task_log $log): string;

    /**
     * The commit's files in $src; $work is the build directory's scratch space
     */
    function extract(Runtime $runtime, Task_log $log, string $commit_sha, string $work, string $src): void;
}
