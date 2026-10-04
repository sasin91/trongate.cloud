<?php
/**
 * Public pages: the landing page and the dashboard shot it embeds.
 */
class Welcome extends Trongate {

    /**
     * The landing page. A standalone page (no public template) in the trongate.cloud palette.
     */
    public function index(): void {
        $this->view('default_homepage');
    }

    /**
     * The real app overview filled with sample data, framed by the landing page's hero.
     * Rendered in an iframe so the dashboard's styles stay apart from the landing page's.
     */
    public function preview(): void {
        require_once APPPATH . 'modules/platform/manifests/Manifests.php';

        $minutes_ago = fn(int $minutes) => date('Y-m-d H:i', time() - $minutes * 60);
        $deployment = fn(int $id, string $status, string $sha, string $trigger, int $minutes, bool $is_current = false) => (object) [
            'id' => $id,
            'status' => $status,
            'commit_sha' => $sha,
            'trigger_type' => $trigger,
            'created_at' => $minutes_ago($minutes),
            'completed_at' => $minutes_ago($minutes - 1),
            'image' => 'sample',
            'is_current' => $is_current,
        ];

        $deployments = [
            $deployment(48, 'success', '4f2b8d1c9a', 'push', 2, true),
            $deployment(47, 'success', 'a91c03e7f2', 'push', 95),
            $deployment(46, 'failed', '0d7e52b4aa', 'push', 140),
            $deployment(45, 'success', '7c1f9e0b3d', 'manual', 1500),
        ];

        $data = [
            'project' => (object) [
                'id' => 0,
                'label' => 'Storefront',
                'slug' => 'storefront',
                'type' => 'trongate_v2',
                'source_url' => 'https://github.com/acme/storefront',
                'source_branch' => 'main',
                'auto_deploy' => 1,
                'always_on' => 1,
                'domain' => 'shop.acme.dk',
                'verified_domain' => 'shop.acme.dk',
                'domain_pointed_at' => $minutes_ago(3000),
                'domain_secured_at' => $minutes_ago(2990),
            ],
            'urls' => ['https://storefront.' . PLATFORM_APPS_DOMAIN, 'https://shop.acme.dk'],
            'current' => $deployments[0],
            'deployments' => $deployments,
            'can_rollback' => true,
            'live_state' => ['state' => 'running', 'label' => 'Running', 'detail' => ''],
            'database_name' => Manifests::database_name(1),
            'preview' => true,
        ];

        $this->view('preview', $data);
    }

}
