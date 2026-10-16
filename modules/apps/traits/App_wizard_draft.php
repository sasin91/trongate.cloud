<?php

/**
 * The new-app wizard's answers so far, kept in the panel session (Valkey) so
 * a browser refresh opens the same question with the same answers.
 * app_steps.js posts the whole form each time it moves to another question;
 * a successful launch or Cancel forgets it.
 *
 * Each wizard has its own key ('project-{id}' or 'onboarding'), so two
 * projects' wizards in two tabs do not mix.
 *
 * Used by App_form: _app_form_data() reads the draft on a fresh open (a GET)
 * and the form's _posted_* readers go through _answer().
 */
trait App_wizard_draft {

    /** Typed SQL longer than this is not kept: it can run to megabytes, and the session is read on every page */
    const DRAFT_MAX_SQL_BYTES = 65536;

    /**
     * Fields never kept: environment variable values are often secrets (the
     * question reads them again from config/ after a refresh), the CSRF token
     * is the session's own, and wizard_step is kept apart
     */
    const DRAFT_SKIPPED_FIELDS = ['csrf_token', 'env', 'wizard_step'];

    /**
     * The draft's answers, read by _answer() on a fresh open
     *
     * @var array<string, mixed>
     */
    private array $wizard_answers = [];

    /**
     * Keep the posted form as the wizard's draft, open on $step
     *
     * @param array<string, mixed> $posted
     */
    function _save_wizard_draft(string $key, string $step, array $posted): void {
        if (preg_match('/^[a-z_]{1,30}$/', $step) !== 1) {
            $step = '';
        }

        $_SESSION['app_wizard'][$key] = [
            'step' => $step,
            'answers' => $this->_draft_answers($posted),
        ];
    }

    /**
     * The posted fields worth keeping
     *
     * @param array<string, mixed> $posted
     * @return array<string, mixed>
     */
    function _draft_answers(array $posted): array {
        foreach (self::DRAFT_SKIPPED_FIELDS as $field) {
            unset($posted[$field]);
        }

        $sql = $posted['setup_sql'] ?? '';

        if (is_string($sql) && strlen($sql) > self::DRAFT_MAX_SQL_BYTES) {
            unset($posted['setup_sql']);
        }

        return $posted;
    }

    /**
     * The wizard's draft, or an empty one
     *
     * @return array{step: string, answers: array<string, mixed>}
     */
    function _wizard_draft(string $key): array {
        $draft = $_SESSION['app_wizard'][$key] ?? null;

        if (!is_array($draft)) {
            return ['step' => '', 'answers' => []];
        }

        return [
            'step' => (string) ($draft['step'] ?? ''),
            'answers' => (array) ($draft['answers'] ?? []),
        ];
    }

    function _forget_wizard_draft(string $key): void {
        unset($_SESSION['app_wizard'][$key]);
    }

    /**
     * Fill _answer() from the draft. Only a fresh open (a GET) uses it: a
     * failed submit shows what was just posted.
     */
    function _use_wizard_draft(string $key): void {
        if (REQUEST_TYPE === 'POST') {
            return;
        }

        $this->wizard_answers = $this->_wizard_draft($key)['answers'];
    }

    /**
     * Whether the questions show earlier answers: a failed submit, or a draft
     */
    function _has_answers(): bool {
        if (REQUEST_TYPE === 'POST') {
            return true;
        }

        return $this->wizard_answers !== [];
    }

    /**
     * One answer, like post(): what was posted, or on a fresh open what the
     * draft kept. Missing answers are ''.
     */
    function _answer(string $name, bool $clean_up = false): string|int|float|array {
        if (REQUEST_TYPE === 'POST') {
            return post($name, $clean_up);
        }

        $value = $this->wizard_answers[$name] ?? '';

        if ($clean_up && is_string($value)) {
            $value = trim(preg_replace('/\s+/', ' ', $value));
        }

        return $value;
    }
}
