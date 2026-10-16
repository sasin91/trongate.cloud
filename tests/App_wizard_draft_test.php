<?php

/**
 * Tests for App_wizard_draft: what the new-app wizard keeps in the session
 * so a refresh opens the same question, and what it leaves out.
 *
 * Run: php tests/App_wizard_draft_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';

define('REQUEST_TYPE', 'GET');

require_once dirname(__DIR__) . '/modules/apps/traits/App_wizard_draft.php';

final class Wizard_draft_host {
    use App_wizard_draft;
}

$_SESSION = [];

test('keeps the open question and the answers', function () {
    $host = new Wizard_draft_host();
    $host->_save_wizard_draft('project-1', 'database', ['repository' => 'owner/app', 'label' => 'Shop', 'sql_files' => ['schema.sql']]);

    $draft = $host->_wizard_draft('project-1');
    assert_same('database', $draft['step']);
    assert_same(['repository' => 'owner/app', 'label' => 'Shop', 'sql_files' => ['schema.sql']], $draft['answers']);
});

test('leaves out environment values, the CSRF token and the step field', function () {
    $host = new Wizard_draft_host();
    $host->_save_wizard_draft('project-1', 'env', ['csrf_token' => 'abc', 'env' => ['DB_PASS' => 'secret'], 'wizard_step' => 'env', 'label' => 'Shop']);

    assert_same(['label' => 'Shop'], $host->_wizard_draft('project-1')['answers']);
});

test('leaves out typed SQL that is too long to keep', function () {
    $host = new Wizard_draft_host();
    $host->_save_wizard_draft('project-1', 'database', ['setup_sql' => str_repeat('x', Wizard_draft_host::DRAFT_MAX_SQL_BYTES + 1)]);
    assert_same([], $host->_wizard_draft('project-1')['answers']);

    $host->_save_wizard_draft('project-1', 'database', ['setup_sql' => 'CREATE TABLE posts (id INT);']);
    assert_same(['setup_sql' => 'CREATE TABLE posts (id INT);'], $host->_wizard_draft('project-1')['answers']);
});

test('a step name that is not a question is dropped', function () {
    $host = new Wizard_draft_host();
    $host->_save_wizard_draft('project-1', '"><script>', []);

    assert_same('', $host->_wizard_draft('project-1')['step']);
});

test('each wizard has its own draft, and forgetting one keeps the others', function () {
    $host = new Wizard_draft_host();
    $host->_save_wizard_draft('project-1', 'name', ['label' => 'One']);
    $host->_save_wizard_draft('project-2', 'type', ['label' => 'Two']);
    $host->_forget_wizard_draft('project-1');

    assert_same(['step' => '', 'answers' => []], $host->_wizard_draft('project-1'));
    assert_same('type', $host->_wizard_draft('project-2')['step']);
});

test('a fresh open answers from the draft, cleaned up when asked', function () {
    $host = new Wizard_draft_host();
    $host->_save_wizard_draft('project-3', 'settings', ['health_check_path' => "  /health \n", 'process_name' => ['worker']]);

    assert_same(false, $host->_has_answers());
    assert_same('', $host->_answer('label'));

    $host->_use_wizard_draft('project-3');
    assert_same(true, $host->_has_answers());
    assert_same('/health', $host->_answer('health_check_path', true));
    assert_same(['worker'], $host->_answer('process_name'));
    assert_same('', $host->_answer('label'));
});

finish_tests();
