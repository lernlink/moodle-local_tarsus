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

/**
 * Callbacks for local_tarsus.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Serve the assistant avatar uploaded in the admin settings.
 *
 * @param \stdClass|null $course      The course, unused (system-level file).
 * @param \stdClass|null $cm          The course module, unused.
 * @param \context       $context     The context the file was requested in.
 * @param string         $filearea    The file area.
 * @param array          $args        Remaining path arguments (itemid, filename).
 * @param bool           $forcedownload Whether the file must download.
 * @param array          $options     Additional sending options.
 * @return bool False when the request does not match a stored file.
 */
function local_tarsus_pluginfile(
    $course,
    $cm,
    context $context,
    string $filearea,
    array $args,
    bool $forcedownload,
    array $options = []
): bool {
    if ($context->contextlevel !== CONTEXT_SYSTEM || $filearea !== 'robotavatar') {
        return false;
    }

    require_login();

    $filename = array_pop($args);
    $file = get_file_storage()->get_file($context->id, 'local_tarsus', $filearea, 0, '/', $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    // The avatar appears next to every chat answer: let browsers cache it.
    send_stored_file($file, DAYSECS, 0, $forcedownload, $options);

    return true;
}
