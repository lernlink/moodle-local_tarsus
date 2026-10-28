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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

namespace local_tarsus\material\extractor;

use local_tarsus\material\extractor;

/**
 * Shared helpers for module extractors (text normalisation, titles, component).
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base implements extractor {

    /**
     * The frankenstyle component for this extractor's module.
     *
     * @return string e.g. 'mod_page'.
     */
    protected function component(): string {
        return 'mod_' . $this->modname();
    }

    /**
     * Convert formatted Moodle content to plain text for hashing/ingestion.
     *
     * @param string $content The raw content.
     * @param int    $format  The Moodle text format constant.
     * @return string Plain text, trimmed.
     */
    protected function plain_text(string $content, int $format): string {
        if (trim($content) === '') {
            return '';
        }
        return trim(content_to_text($content, $format));
    }

    /**
     * Sanitise an activity or file name for use as a bit title.
     *
     * @param string           $name    The raw name.
     * @param \context $context The context for filter resolution.
     * @return string
     */
    protected function clean_title(string $name, \context $context): string {
        return format_string($name, true, ['context' => $context]);
    }
}
