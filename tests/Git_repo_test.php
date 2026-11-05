<?php

/**
 * Tests for Git_repo (trongate.cloud git's bare repositories) and the CGI
 * response parsing of Git_http. Needs git on PATH.
 *
 * Run: php tests/Git_repo_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';

define('APPPATH', dirname(__DIR__) . '/');
define('PLATFORM_GIT_ROOT', sys_get_temp_dir() . '/git-repo-test-' . getmypid());

require_once APPPATH . 'modules/platform/git/Git_repo.php';
require_once APPPATH . 'modules/git/Git_http.php';

$root = PLATFORM_GIT_ROOT;
$work = $root . '-work';

/** Run git in $dir for the test's own commits */
$git = function (string $dir, string ...$args): string {
    $result = Git_repo::git(['-C', $dir, '-c', 'user.name=Test', '-c', 'user.email=test@example.com', ...$args]);

    if ($result['code'] !== 0) {
        throw new RuntimeException('git ' . implode(' ', $args) . ': ' . $result['err']);
    }

    return trim($result['out']);
};

test('names: from an app id, a clone address and a request path', function () use ($root) {
    assert_same('p12', Git_repo::for_app(12)->name);
    assert_same($root . '/p12.git', Git_repo::for_app(12)->path());
    assert_same('https://trongate.cloud/git/p12.git', Git_repo::clone_url('https://trongate.cloud/', 12));
    assert_same('p12', Git_repo::from_url('https://trongate.cloud/git/p12.git')?->name);
    assert_same(12, Git_repo::from_url('https://trongate.cloud/git/p12.git')?->app_id());
    assert_same(null, Git_repo::from_url('https://github.com/owner/name'));
    assert_same('p7', Git_repo::from_path_name('p7.git')?->name);
    assert_same(null, Git_repo::from_path_name('p0.git'));
    assert_same(null, Git_repo::from_path_name('../p7.git'));
    assert_same(null, Git_repo::from_path_name('p7'));
});

test('is_safe_ref: branch names and SHAs only', function () {
    assert_same(true, Git_repo::is_safe_ref('main'));
    assert_same(true, Git_repo::is_safe_ref('feature/login-form'));
    assert_same(true, Git_repo::is_safe_ref('a1b2c3d'));
    assert_same(false, Git_repo::is_safe_ref(''));
    assert_same(false, Git_repo::is_safe_ref('--upload-pack=x'));
    assert_same(false, Git_repo::is_safe_ref('main..other'));
    assert_same(false, Git_repo::is_safe_ref('main^{tree}'));
});

test('create: an empty bare repository with no branches', function () {
    $repo = Git_repo::for_app(3);
    $repo->create();
    $repo->create(); // twice is fine

    assert_same(true, $repo->exists());
    assert_same([], $repo->branch_heads());
    assert_same(null, $repo->resolve('main'));
});

test('a push moves main: branch_heads, resolve, moved_branches and archive', function () use ($git, $work) {
    $repo = Git_repo::for_app(3);
    mkdir($work . '/public', 0700, true);
    file_put_contents($work . '/public/index.php', "<?php echo 'hi';\n");
    $git($work, 'init', '--quiet', '--initial-branch=main');
    $git($work, 'add', '.');
    $git($work, 'commit', '--quiet', '-m', 'First');
    $before = $repo->branch_heads();
    $git($work, 'push', '--quiet', $repo->path(), 'main');
    $sha = $git($work, 'rev-parse', 'HEAD');

    assert_same(['main' => $sha], $repo->branch_heads());
    assert_same(['main' => $sha], Git_repo::moved_branches($before, $repo->branch_heads()));
    assert_same([], Git_repo::moved_branches($repo->branch_heads(), $repo->branch_heads()));
    assert_same($sha, $repo->resolve('main'));
    assert_same($sha, $repo->resolve(substr($sha, 0, 7)));
    assert_same(null, $repo->resolve('no-such-branch'));

    $tar = $work . '/source.tar';
    $repo->archive($sha, $tar);
    $out = shell_exec('tar -tf ' . escapeshellarg($tar));
    assert_contains('public/index.php', (string) $out);
});

test('parse_cgi_head: Status becomes the code, the rest headers', function () {
    $parsed = Git_http::parse_cgi_head("Status: 403 Forbidden\r\nContent-Type: text/plain\r\nExpires: Fri, 01 Jan 1980 00:00:00 GMT\r\n\r\n");

    assert_same(403, $parsed['status']);
    assert_same(['Content-Type: text/plain', 'Expires: Fri, 01 Jan 1980 00:00:00 GMT'], $parsed['headers']);
    assert_same(200, Git_http::parse_cgi_head("Content-Type: application/x-git-upload-pack-advertisement\n\n")['status']);
});

test('head_end: the body starts after the blank line, not before', function () {
    assert_same(null, Git_http::head_end("Status: 200 OK\r\nContent-Type: text/plain\r\n"));
    assert_same(37, Git_http::head_end("Status: 200 OK\r\nContent-Type: x/y\r\n\r\nbody\n\nmore"));
    assert_same(16, Git_http::head_end("Content-Type: x\n\nbody"));
});

shell_exec('rm -rf ' . escapeshellarg($root) . ' ' . escapeshellarg($work));

finish_tests();
