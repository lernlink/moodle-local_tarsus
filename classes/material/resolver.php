<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_tarsus\material;

/**
 * Maps course modules to extractors and resolves materials.
 *
 * This is the §5 "Resolve Moodle resource → type · payload · resource_id"
 * step. It also supports re-resolving a single material from its resource id,
 * which the queue worker uses to recompute the payload at send time (cron may
 * run long after the trigger, so it must read current state).
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resolver {
    /** @var array<string, extractor>|null Lazily built modname => extractor map. */
    private ?array $extractors = null;

    /**
     * Constructor.
     *
     * @param extractor[]|null $extractors Override the default extractor set (testing).
     */
    public function __construct(?array $extractors = null) {
        if ($extractors !== null) {
            $this->extractors = [];
            foreach ($extractors as $extractor) {
                $this->extractors[$extractor->modname()] = $extractor;
            }
        }
    }

    /**
     * Whether a module type can be extracted.
     *
     * @param string $modname Module name without the 'mod_' prefix.
     * @return bool
     */
    public function supports(string $modname): bool {
        return isset($this->registry()[$modname]);
    }

    /**
     * Extract every material contained in a course module.
     *
     * @param \cm_info $cm The course module.
     * @return material[] Zero or more materials.
     */
    public function resolve(\cm_info $cm): array {
        $extractor = $this->registry()[$cm->modname] ?? null;
        return $extractor ? $extractor->extract($cm) : [];
    }

    /**
     * Re-resolve a single material from its namespaced resource id.
     *
     * @param string $resourceid The namespaced resource id.
     * @return material|null The matching material, or null when it can no
     *                       longer be resolved (module/file gone, bad id).
     */
    public function resolve_by_resource_id(string $resourceid): ?material {
        $parts = resource_id::parse($resourceid);
        if ($parts === null) {
            return null;
        }

        $cm = get_coursemodule_from_id('', $parts['cmid'], 0, false, IGNORE_MISSING);
        if (!$cm) {
            return null;
        }

        try {
            $cminfo = get_fast_modinfo($cm->course)->get_cm($cm->id);
        } catch (\moodle_exception $e) {
            return null;
        }

        foreach ($this->resolve($cminfo) as $material) {
            if ($material->resourceid === $resourceid) {
                return $material;
            }
        }

        return null;
    }

    /**
     * The default extractor registry.
     *
     * @return array<string, extractor>
     */
    private function registry(): array {
        if ($this->extractors === null) {
            $defaults = [
                new extractor\page(),
                new extractor\url(),
                new extractor\resource(),
            ];
            $this->extractors = [];
            foreach ($defaults as $extractor) {
                $this->extractors[$extractor->modname()] = $extractor;
            }
        }
        return $this->extractors;
    }
}
