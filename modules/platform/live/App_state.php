<?php

require_once __DIR__ . '/../manifests/Manifests.php';
require_once __DIR__ . '/../../services/clients/Kubernetes.php';

/**
 * Live state of customer apps, read from their Deployments and Pods.
 *
 * Framework-free. The web app reads it with its own read-only service account
 * (deploy/k8s/platform/app-rbac.yaml); the stored deployment status only says
 * how the last rollout went, this says what the app is doing now.
 */
class App_state {

    /** state => label shown in the UI */
    const LABELS = [
        'running' => 'Running',
        'asleep' => 'Asleep',
        'starting' => 'Starting',
        'crashing' => 'Crashing',
        'stuck' => 'Stuck',
        'not_running' => 'Not running',
    ];

    /** Container waiting reasons that mean the app cannot start on its own */
    const CRASH_REASONS = [
        'CrashLoopBackOff',
        'ImagePullBackOff',
        'ErrImagePull',
        'InvalidImageName',
        'CreateContainerConfigError',
        'CreateContainerError',
        'RunContainerError',
    ];

    /** Short timeouts: a page render must not hang on an unreachable API server */
    const TIMEOUT_SECONDS = 3;

    /**
     * States of all the apps in a project's namespace (project.namespace)
     *
     * @return array<int, array{state: string, label: string, detail: string}> keyed by app id
     */
    static function for_project(Kubernetes $kube, string $namespace): array {
        return self::_collect($kube, $namespace, 'app.kubernetes.io/managed-by=' . Manifests::MANAGED_BY);
    }

    /**
     * State of one app; not_running when it has no Deployment
     *
     * @return array{state: string, label: string, detail: string}
     */
    static function for_app(Kubernetes $kube, string $namespace, int $app_id): array {
        $states = self::_collect($kube, $namespace, 'app.kubernetes.io/name=' . Manifests::app_name($app_id));

        return $states[$app_id] ?? self::_make('not_running');
    }

    /**
     * States of the app's processes (Manifests::_process_deployment) that have
     * a Deployment; a process missing here has not been deployed yet
     *
     * @return array<string, array{state: string, label: string, detail: string}> keyed by process name
     */
    static function for_processes(Kubernetes $kube, string $namespace, int $app_id): array {
        if (!Manifests::is_tenant_namespace($namespace)) {
            throw new InvalidArgumentException("Not a tenant namespace: '{$namespace}'");
        }

        $kube = $kube->with_timeout(self::TIMEOUT_SECONDS);
        $selector = Manifests::process_selector($app_id);

        try {
            $deployments = $kube->list('apps/v1', 'Deployment', $namespace, $selector);
            $pods = $kube->list('v1', 'Pod', $namespace, $selector);
        } catch (Client_Error $e) {
            if ($e->getCode() === 404) {
                return [];
            }

            throw $e;
        }

        $pods_by_process = [];
        foreach ($pods as $pod) {
            $pods_by_process[$pod['metadata']['labels'][Manifests::PROCESS_LABEL] ?? ''][] = $pod;
        }

        $states = [];
        foreach ($deployments as $deployment) {
            $process = (string) ($deployment['metadata']['labels'][Manifests::PROCESS_LABEL] ?? '');

            if ($process !== '') {
                $states[$process] = self::_state($deployment, $pods_by_process[$process] ?? []);
            }
        }

        return $states;
    }

    /**
     * @return array<int, array{state: string, label: string, detail: string}>
     */
    static function _collect(Kubernetes $kube, string $namespace, string $selector): array {
        if (!Manifests::is_tenant_namespace($namespace)) {
            throw new InvalidArgumentException("Not a tenant namespace: '{$namespace}'");
        }

        $kube = $kube->with_timeout(self::TIMEOUT_SECONDS);

        try {
            $deployments = $kube->list('apps/v1', 'Deployment', $namespace, $selector);
            $pods = $kube->list('v1', 'Pod', $namespace, $selector);
        } catch (Client_Error $e) {
            // The project's namespace only exists after its first deploy
            if ($e->getCode() === 404) {
                return [];
            }

            throw $e;
        }

        $pods_by_app = [];
        foreach ($pods as $pod) {
            $pods_by_app[$pod['metadata']['labels']['app.kubernetes.io/name'] ?? ''][] = $pod;
        }

        $states = [];
        foreach ($deployments as $deployment) {
            $labels = $deployment['metadata']['labels'] ?? [];
            $app_id = (int) ($labels[Manifests::APP_LABEL] ?? 0);

            // The app's own Deployment, not its processes' (Manifests::_process_deployment)
            if ($app_id > 0 && ($deployment['metadata']['name'] ?? '') === Manifests::app_name($app_id)) {
                $states[$app_id] = self::_state($deployment, $pods_by_app[$labels['app.kubernetes.io/name'] ?? ''] ?? []);
            }
        }

        return $states;
    }

    /**
     * @return array{state: string, label: string, detail: string}
     */
    static function _state(array $deployment, array $pods): array {
        $desired = (int) ($deployment['spec']['replicas'] ?? 1);
        $ready = (int) ($deployment['status']['readyReplicas'] ?? 0);

        // Sablier scales idle apps to zero; the next request wakes them
        if ($desired === 0) {
            return self::_make('asleep', 'Wakes up on the next request');
        }

        foreach ($pods as $pod) {
            foreach ($pod['status']['containerStatuses'] ?? [] as $status) {
                $waiting = $status['state']['waiting'] ?? null;

                if ($waiting !== null && in_array($waiting['reason'] ?? '', self::CRASH_REASONS, true)) {
                    return self::_make('crashing', trim(($waiting['reason'] ?? '') . ': ' . ($waiting['message'] ?? ''), ': '));
                }
            }

            foreach ($pod['status']['conditions'] ?? [] as $condition) {
                if (($condition['type'] ?? '') === 'PodScheduled' && ($condition['status'] ?? '') === 'False') {
                    return self::_make('stuck', 'Not scheduled: ' . ($condition['message'] ?? ''));
                }
            }
        }

        foreach ($deployment['status']['conditions'] ?? [] as $condition) {
            if (($condition['type'] ?? '') === 'Progressing' && ($condition['reason'] ?? '') === 'ProgressDeadlineExceeded') {
                return self::_make('stuck', (string) ($condition['message'] ?? 'Rollout exceeded its progress deadline'));
            }
        }

        if ($ready >= $desired) {
            return self::_make('running', "{$ready} of {$desired} ready");
        }

        return self::_make('starting', "{$ready} of {$desired} ready");
    }

    /**
     * @return array{state: string, label: string, detail: string}
     */
    static function _make(string $state, string $detail = ''): array {
        return [
            'state' => $state,
            'label' => self::LABELS[$state],
            'detail' => mb_strimwidth($detail, 0, 300, '…'),
        ];
    }
}
