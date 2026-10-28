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

namespace local_tarsus;

use local_tarsus\client\client_factory;

/**
 * The brain's voices: a cached catalogue and the choice for a learner.
 *
 * The brain lists voices and demands one by name on every narration call;
 * it picks none by itself. Site settings decide: one voice for everyone,
 * or the first voice of the learner's language with that voice as the
 * fallback.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class voices {

    /** @var string Voice mode: one voice for everyone. */
    public const MODE_FIXED = 'fixed';

    /** @var string Voice mode: by the learner's language, the fixed voice as fallback. */
    public const MODE_AUTO = 'auto';

    /** @var string The voice used when nothing is configured and the brain is silent. */
    public const DEFAULT_VOICE = 'Daniel';

    /** @var int How long the catalogue is kept before asking the brain again. */
    private const CACHE_TTL = DAYSECS;

    /**
     * The voice catalogue, from the brain or the day-old copy of it.
     *
     * @param bool $refresh Ask the brain even when the copy is fresh.
     * @return array<int, array{alias: string, country: string, gender: string}>
     */
    public static function catalogue(bool $refresh = false): array {
        $cached = json_decode((string) get_config('local_tarsus', 'voices_cache'), true);
        $fetched = (int) get_config('local_tarsus', 'voices_cachetime');
        if (!$refresh && is_array($cached) && $fetched > time() - self::CACHE_TTL) {
            return $cached;
        }

        try {
            $voices = client_factory::get_client()->list_voices();
        } catch (\Throwable $e) {
            // An unreachable brain keeps the last known list, however old.
            return is_array($cached) ? $cached : [];
        }
        if ($voices !== []) {
            set_config('voices_cache', json_encode($voices), 'local_tarsus');
            set_config('voices_cachetime', time(), 'local_tarsus');
        }
        return $voices;
    }

    /**
     * The catalogue as select options: alias => "Alias (language, gender)".
     *
     * @return array<string, string>
     */
    public static function options(): array {
        $languages = get_string_manager()->get_list_of_languages();
        $options = [];
        foreach (self::catalogue() as $voice) {
            $code = strtolower(substr($voice['country'], 0, 2));
            $language = $languages[$code] ?? $voice['country'];
            $gender = in_array($voice['gender'], ['female', 'male'], true)
                ? get_string('voice_' . $voice['gender'], 'local_tarsus') : $voice['gender'];
            $options[$voice['alias']] = get_string('voice_label', 'local_tarsus', [
                'alias' => $voice['alias'], 'language' => $language, 'gender' => $gender,
            ]);
        }
        return $options;
    }

    /**
     * The voice to read to a learner with.
     *
     * @param string $language The learner's language code (e.g. 'de', 'en').
     * @return string The voice alias.
     */
    public static function choose(string $language): string {
        $fixed = trim((string) get_config('local_tarsus', 'voice'));
        if ($fixed === '') {
            $fixed = self::DEFAULT_VOICE;
        }
        if (get_config('local_tarsus', 'voice_mode') === self::MODE_FIXED) {
            return $fixed;
        }

        $code = strtolower(substr($language, 0, 2));
        foreach (self::catalogue() as $voice) {
            if (strtolower(substr($voice['country'], 0, 2)) === $code) {
                return $voice['alias'];
            }
        }
        return $fixed;
    }
}
