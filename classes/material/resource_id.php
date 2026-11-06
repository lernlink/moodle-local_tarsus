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
 * Builds and parses the namespaced resource id used to address a bit.
 *
 * The shared brain is isolated by id, not by brain, so every bit needs a
 * stable, collision-free identifier: {@see https://...} §4. The format is
 *
 *     {sitetoken}:{courseid}:{component}:{cmid}:{bitkey}
 *
 * - sitetoken keeps two Moodle sites that share a brain apart.
 * - courseid scopes content to a course (default granularity, §4).
 * - component + cmid locate the owning activity so the payload can be
 *   re-extracted at send time.
 * - bitkey distinguishes multiple bits produced by one activity (e.g. each
 *   file in a resource); single-bit activities use 'main'.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resource_id {
    /** @var string Default bit key for single-bit activities. */
    public const MAIN = 'main';

    /** @var non-empty-string Field separator. */
    private const SEP = ':';

    /**
     * Build a namespaced resource id.
     *
     * @param int    $courseid  Owning course id.
     * @param string $component Frankenstyle component (e.g. mod_page).
     * @param int    $cmid      Course module id.
     * @param string $bitkey    Bit discriminator within the activity.
     * @return string The namespaced resource id.
     */
    public static function build(int $courseid, string $component, int $cmid, string $bitkey = self::MAIN): string {
        return implode(self::SEP, [
            self::site_token(),
            $courseid,
            $component,
            $cmid,
            self::clean_bitkey($bitkey),
        ]);
    }

    /**
     * Parse a namespaced resource id into its parts.
     *
     * @param string $resourceid The id to parse.
     * @return array{sitetoken: string, courseid: int, component: string, cmid: int, bitkey: string}|null
     *         The parts, or null when the id is not in the canonical 5-field form.
     */
    public static function parse(string $resourceid): ?array {
        $parts = explode(self::SEP, $resourceid);
        if (count($parts) !== 5) {
            return null;
        }

        [$sitetoken, $courseid, $component, $cmid, $bitkey] = $parts;
        if (
            $sitetoken === '' || $component === ''
                || !ctype_digit($courseid) || !ctype_digit($cmid)
        ) {
            return null;
        }

        return [
            'sitetoken' => $sitetoken,
            'courseid' => (int) $courseid,
            'component' => $component,
            'cmid' => (int) $cmid,
            'bitkey' => $bitkey,
        ];
    }

    /**
     * A short, stable token identifying this Moodle site.
     *
     * @return string
     */
    public static function site_token(): string {
        global $CFG;
        return 'site' . substr(sha1($CFG->siteidentifier ?? $CFG->wwwroot), 0, 12);
    }

    /**
     * Normalise a bit key so it never breaks the separator scheme.
     *
     * @param string $bitkey Raw bit key.
     * @return string A separator-safe bit key.
     */
    private static function clean_bitkey(string $bitkey): string {
        $clean = preg_replace('/[^a-z0-9_.-]/i', '', $bitkey);
        return ($clean === '' || $clean === null) ? self::MAIN : $clean;
    }
}
