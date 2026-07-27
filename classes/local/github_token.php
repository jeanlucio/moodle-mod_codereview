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

namespace mod_codereview\local;

use context_system;
use core\encryption;
use stdClass;

/**
 * Resolves which GitHub token to use, and stores personal tokens safely.
 *
 * The chain is personal token of the instance owner, then site token, then no
 * token at all. An activity row only ever holds a tokenuserid pointer; the secret
 * itself lives in that user's own preferences, so it never reaches a course-scoped
 * form where co-teachers could reveal it.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class github_token {
    /** @var string Name of the user preference holding the encrypted personal token. */
    public const PREFERENCE = 'mod_codereview_githubtoken';

    /**
     * Returns true when personal tokens are available to the given user.
     *
     * @param int|null $userid Defaults to the current user.
     * @return bool
     */
    public static function personal_tokens_allowed(?int $userid = null): bool {
        global $USER;

        if (!get_config('mod_codereview', 'enablepersonaltokens')) {
            return false;
        }
        $userid = $userid ?? (int) $USER->id;

        return has_capability('mod/codereview:usepersonaltoken', context_system::instance(), $userid);
    }

    /**
     * Returns the decrypted personal token of a user, or an empty string.
     *
     * @param int $userid The token owner.
     * @return string
     */
    public static function get_personal_token(int $userid): string {
        $stored = (string) get_user_preferences(self::PREFERENCE, '', $userid);
        if ($stored === '') {
            return '';
        }

        try {
            return encryption::decrypt($stored);
        } catch (\Throwable $e) {
            // A token that cannot be decrypted is unusable, most likely because the
            // site encryption key changed. Degrade to the next level of the chain
            // instead of breaking every submission with a fatal error.
            return '';
        }
    }

    /**
     * Stores a personal token for a user, encrypted at rest.
     *
     * @param int $userid The token owner.
     * @param string $token The raw token. An empty value removes the stored one.
     * @return void
     */
    public static function set_personal_token(int $userid, string $token): void {
        $token = trim($token);

        if ($token === '') {
            unset_user_preference(self::PREFERENCE, $userid);
            return;
        }

        set_user_preference(self::PREFERENCE, encryption::encrypt($token), $userid);
    }

    /**
     * Returns true when the user has a personal token stored.
     *
     * @param int $userid The token owner.
     * @return bool
     */
    public static function has_personal_token(int $userid): bool {
        return (string) get_user_preferences(self::PREFERENCE, '', $userid) !== '';
    }

    /**
     * Returns the site-wide token configured by the administrator.
     *
     * @return string
     */
    public static function get_site_token(): string {
        return (string) get_config('mod_codereview', 'sitetoken');
    }

    /**
     * Resolves the token an activity instance should authenticate with.
     *
     * @param stdClass $instance The codereview instance row.
     * @return string The token, or an empty string for unauthenticated requests.
     */
    public static function resolve(stdClass $instance): string {
        $ownerid = (int) ($instance->tokenuserid ?? 0);

        if ($ownerid > 0 && self::personal_tokens_allowed($ownerid)) {
            $personal = self::get_personal_token($ownerid);
            if ($personal !== '') {
                return $personal;
            }
        }

        return self::get_site_token();
    }
}
