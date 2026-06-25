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

namespace local_tarsus\material;

/**
 * The four bit types the brain ingests, and how Moodle artefacts map to them.
 *
 * Tarsus tracks a bit differently per type (text / url / document / media,
 * §5). The plugin classifies each extracted material into one of these.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bit_type {

    /** @var string Inline textual content (page body, label, intro). */
    public const TEXT = 'text';

    /** @var string An external link. */
    public const URL = 'url';

    /** @var string A document file (pdf, office, plain text, ...). */
    public const DOCUMENT = 'document';

    /** @var string An audio or video file. */
    public const MEDIA = 'media';

    /** @var string[] Every valid bit type. */
    public const ALL = [self::TEXT, self::URL, self::DOCUMENT, self::MEDIA];

    /**
     * Classify a stored file as a document or media bit by its MIME type.
     *
     * @param \stored_file $file The file to classify.
     * @return string {@see self::DOCUMENT} or {@see self::MEDIA}.
     */
    public static function for_file(\stored_file $file): string {
        $mimetype = (string) $file->get_mimetype();
        if (str_starts_with($mimetype, 'audio/') || str_starts_with($mimetype, 'video/')) {
            return self::MEDIA;
        }
        return self::DOCUMENT;
    }

    /**
     * Whether a bit type value is recognised.
     *
     * @param string $type The type to check.
     * @return bool
     */
    public static function is_valid(string $type): bool {
        return in_array($type, self::ALL, true);
    }
}
