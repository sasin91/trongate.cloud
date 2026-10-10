<?php

/**
 * Tests for App_state::for_processes (what each of an app's processes is
 * doing, from its Deployment and Pods) and the overview's process nodes
 * (modules/apps/views/_processes.php).
 *
 * Run: php tests/App_state_test.php (exits non-zero on a failure)
 */

define('APPPATH', dirname(__DIR__) . '/');

require_once APPPATH . 'modules/services/clients/Kubernetes.php';
require_once APPPATH . 'modules/platform/live/App_state.php';

$failures = 0;

$test = function (string $name, callable $body) use (&$failures): void {
    try {
        $body();
        echo "ok   {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL {$name}\n     {$e->getMessage()}\n";
    }
};

$same = function ($expected, $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException("Expected:\n" . var_export($expected, true) . "\nGot:\n" . var_export($actual, true));
    }
};

$contains = function (string $needle, string $haystack): void {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException("Expected to find '{$needle}' in:\n{$haystack}");
    }
};

/** A cluster that answers list() from fixed objects and records the selectors asked for */
class Fake_kubernetes extends Kubernetes {

    /** @var list<string> */
    public array $selectors = [];

    /** @param array<string, list<array>> $objects by kind */
    function __construct(private array $objects) {
        parent::__construct('https://kube.invalid', 'token');
    }

    function with_timeout(int $seconds): self {
        return $this;
    }

    function list(string $api_version, string $kind, ?string $namespace, string $label_selector = ''): array {
        $this->selectors[] = $label_selector;

        return $this->objects[$kind] ?? [];
    }
}

$process_labels = fn(string $process) => [Manifests::APP_LABEL => '2', 'app.kubernetes.io/component' => 'process', Manifests::PROCESS_LABEL => $process];

$deployment = fn(string $process, int $replicas, int $ready) => [
    'metadata' => ['name' => "p2-{$process}", 'labels' => $process_labels($process)],
    'spec' => ['replicas' => $replicas],
    'status' => ['readyReplicas' => $ready],
];

$pod = fn(string $process, ?string $waiting = null) => [
    'metadata' => ['name' => "p2-{$process}-abc12", 'labels' => $process_labels($process)],
    'status' => ['containerStatuses' => $waiting === null ? [] : [['state' => ['waiting' => ['reason' => $waiting, 'message' => 'back-off restarting']]]]],
];

$test('for_processes reads each process by its label', function () use ($same, $deployment, $pod) {
    $kube = new Fake_kubernetes([
        'Deployment' => [$deployment('worker', 2, 2), $deployment('mailer', 1, 0)],
        'Pod' => [$pod('worker'), $pod('worker'), $pod('mailer', 'CrashLoopBackOff')],
    ]);

    $states = App_state::for_processes($kube, 'project-1', 2);

    $same(['running', '2 of 2 ready'], [$states['worker']['state'], $states['worker']['detail']]);
    $same(['crashing', 'CrashLoopBackOff: back-off restarting'], [$states['mailer']['state'], $states['mailer']['detail']]);
    $same([Manifests::process_selector(2), Manifests::process_selector(2)], $kube->selectors);
});

$test('for_processes is empty before the namespace exists', function () use ($same) {
    $kube = new class ([]) extends Fake_kubernetes {
        function list(string $api_version, string $kind, ?string $namespace, string $label_selector = ''): array {
            throw new Client_Error('namespaces "project-9" not found', 404);
        }
    };

    $same([], App_state::for_processes($kube, 'project-9', 2));
});

$test('for_processes refuses a namespace that is not a tenant\'s', function () {
    try {
        App_state::for_processes(new Fake_kubernetes([]), 'kube-system', 2);
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException('Expected an InvalidArgumentException');
});

$render = function (array $processes, ?array $process_states): string {
    $app = (object) ['id' => 2];
    ob_start();
    require APPPATH . 'modules/apps/views/_processes.php';

    return (string) ob_get_clean();
};

$worker = ['name' => 'worker', 'command' => 'php modules/queue/runtime.php work --time-limit=3600', 'replicas' => 1];

$test('a running process shows its state, command and links', function () use ($render, $contains, $worker) {
    $html = $render([$worker], ['worker' => App_state::_make('running', '1 of 1 ready')]);

    $contains('worker</strong>', $html);
    $contains('php modules/queue/runtime.php work --time-limit=3600', $html);
    $contains('Running</span> &middot; 1 of 1 ready', $html);
    $contains('href="apps/2/logs"', $html);
    $contains('href="apps/2/settings#processes"', $html);
});

$test('a process the next deploy will start or remove says so', function () use ($render, $contains, $worker) {
    $html = $render([$worker], ['old' => App_state::_make('running', '1 of 1 ready')]);

    $contains('Starts on next deploy', $html);
    $contains('old</strong>', $html);
    $contains('Removed on next deploy', $html);
});

$test('a crashing process is flagged; no state when the cluster cannot be read', function () use ($render, $contains, $same, $worker) {
    $contains('<span class="ov-warn-text">Crashing</span>', $render([$worker], ['worker' => App_state::_make('crashing', 'CrashLoopBackOff')]));

    $html = $render([$worker], null);
    $same(false, str_contains($html, 'next deploy'));
    $contains('href="apps/2/logs"', $html);
});

exit($failures === 0 ? 0 : 1);
