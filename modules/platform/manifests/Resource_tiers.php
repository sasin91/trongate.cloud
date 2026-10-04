<?php

/**
 * The resource tier ladder: the only place an app container's CPU/memory is decided.
 *
 * An app picks a rung (app.resource_tier, see db/016); the rung sets the
 * container's limits and the requests are derived from them at a fixed fraction,
 * so every app is Burstable QoS. A few legible rungs ("512 MB") instead of free
 * numbers keep capacity planning and the tenant quota easy to reason about.
 *
 * Framework-free: loaded by the worker (Manifests) and the apps module.
 */
class Resource_tiers {

    /** Limit rungs, smallest first. "small" is the limits every app had before tiers. */
    const TIERS = [
        'small' => ['label' => 'Small', 'memory_mi' => 256, 'cpu_m' => 500],
        'medium' => ['label' => 'Medium', 'memory_mi' => 512, 'cpu_m' => 1000],
        'large' => ['label' => 'Large', 'memory_mi' => 1024, 'cpu_m' => 2000],
    ];

    const DEFAULT_TIER = 'small';

    // Requests are this share of the limit. The scheduler places pods by their
    // requests, and memory is what runs out first (two 8 GB nodes, most of it
    // already reserved by the platform), so this fraction sets app density.
    // Lower packs more apps per node but overcommits harder: when many apps burst
    // towards their limits at once the node runs out of memory and the kubelet
    // evicts (or the kernel OOM-kills) the pods furthest above their requests.
    // Half the limit allows at most 2x overcommit; scale-to-zero means only awake
    // apps hold a reservation at all.
    const MEMORY_REQUEST_FRACTION = 0.5;

    // CPU over the request only throttles, it never kills, so it can be reserved
    // far more thinly. At 0.5 CPU would become the binding constraint before
    // memory (8 vCPU in total) without making anything safer.
    const CPU_REQUEST_FRACTION = 0.1;

    /**
     * Whether a tier name is on the ladder
     */
    static function exists(string $tier): bool {
        return isset(self::TIERS[$tier]);
    }

    /**
     * A rung by name. Unknown or empty names (e.g. a rung that was since removed)
     * fall back to the default, so an old row never blocks a deploy.
     *
     * @return array{label: string, memory_mi: int, cpu_m: int}
     */
    static function tier(?string $tier): array {
        return self::TIERS[(string) $tier] ?? self::TIERS[self::DEFAULT_TIER];
    }

    /**
     * The largest rung; the tenant quota and LimitRange must admit it
     *
     * @return array{label: string, memory_mi: int, cpu_m: int}
     */
    static function top(): array {
        return self::TIERS[array_key_last(self::TIERS)];
    }

    /**
     * A rung's position on the ladder, 0 = smallest (unknown names rank as the default)
     */
    static function rank(string $tier): int {
        return (int) array_search(self::exists($tier) ? $tier : self::DEFAULT_TIER, array_keys(self::TIERS), true);
    }

    /**
     * The highest rung among these tier names
     *
     * @param list<string> $tiers
     */
    static function largest(array $tiers): string {
        return array_reduce($tiers, fn(string $carry, string $tier) => self::rank($tier) > self::rank($carry) ? $tier : $carry, self::DEFAULT_TIER);
    }

    /**
     * Container requests/limits (cpu + memory) for a rung
     *
     * @return array{requests: array{cpu: string, memory: string}, limits: array{cpu: string, memory: string}}
     */
    static function resources(?string $tier): array {
        $rung = self::tier($tier);

        return [
            'requests' => [
                'cpu' => self::derive($rung['cpu_m'], self::CPU_REQUEST_FRACTION) . 'm',
                'memory' => self::derive($rung['memory_mi'], self::MEMORY_REQUEST_FRACTION) . 'Mi',
            ],
            'limits' => [
                'cpu' => $rung['cpu_m'] . 'm',
                'memory' => $rung['memory_mi'] . 'Mi',
            ],
        ];
    }

    /**
     * Human label for forms and pages, e.g. "Medium: 512 MB memory, 1 CPU"
     */
    static function describe(string $tier): string {
        $rung = self::tier($tier);
        $memory = $rung['memory_mi'] >= 1024 ? ($rung['memory_mi'] / 1024) . ' GB' : $rung['memory_mi'] . ' MB';
        $cpu = $rung['cpu_m'] / 1000;

        return "{$rung['label']}: {$memory} memory, {$cpu} CPU";
    }

    /**
     * Every rung as name => description, for a select
     *
     * @return array<string, string>
     */
    static function options(): array {
        $options = [];

        foreach (array_keys(self::TIERS) as $tier) {
            $options[$tier] = self::describe($tier);
        }

        return $options;
    }

    /**
     * round(limit * fraction), never below 1 so a request is never zero
     */
    static function derive(int $limit, float $fraction): int {
        return max(1, (int) round($limit * $fraction));
    }
}
