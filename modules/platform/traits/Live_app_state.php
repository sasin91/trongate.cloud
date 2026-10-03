<?php

require_once APPPATH . 'modules/platform/live/App_state.php';

/**
 * Live app state for controllers (see App_state). Pages render without it when
 * the cluster cannot be reached, e.g. in local development.
 */
trait Live_app_state {

    /**
     * States of the team's apps by project id, or null when the cluster cannot be read
     *
     * @return array<int, array{state: string, label: string, detail: string}>|null
     */
    function _live_app_states(int $team_id): ?array {
        try {
            return App_state::for_team(Kubernetes::from_env(), $team_id);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * State of one app, or null when the cluster cannot be read or it was never deployed
     *
     * @return array{state: string, label: string, detail: string}|null
     */
    function _live_app_state(object $project): ?array {
        if (empty($project->current_deployment_id)) {
            return null;
        }

        try {
            return App_state::for_project(Kubernetes::from_env(), (int) $project->team_id, (int) $project->id);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * A project's entry in _live_app_states(): null when unknown or never deployed
     *
     * @param array<int, array{state: string, label: string, detail: string}>|null $states
     * @return array{state: string, label: string, detail: string}|null
     */
    function _live_state_of(?array $states, object $project): ?array {
        if ($states === null || empty($project->current_deployment_id)) {
            return null;
        }

        return $states[(int) $project->id] ?? App_state::_make('not_running');
    }
}
