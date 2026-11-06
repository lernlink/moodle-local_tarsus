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
 * Contract for turning one Moodle activity into zero or more brain bits.
 *
 * One extractor per supported module type. The resolver dispatches a course
 * module to the matching extractor; everything downstream (hashing, payloads,
 * the index map) is type-agnostic.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface extractor {
    /**
     * The module name this extractor handles, without the 'mod_' prefix.
     *
     * @return string e.g. 'page', 'url', 'resource'.
     */
    public function modname(): string;

    /**
     * Extract the materials contained in a course module.
     *
     * @param \cm_info $cm The course module to read.
     * @return material[] Zero or more materials (empty when nothing indexable).
     */
    public function extract(\cm_info $cm): array;
}
