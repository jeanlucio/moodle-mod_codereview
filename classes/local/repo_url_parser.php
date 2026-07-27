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

use moodle_exception;

/**
 * Strict parser for GitHub repository URLs and commit SHAs.
 *
 * User-supplied text ends up in an outgoing HTTP request, so it is never passed
 * through. The owner and repository name are extracted here and every API URL is
 * rebuilt from those parsed components, which is what keeps a crafted URL from
 * redirecting a request at another host.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repo_url_parser {
    /** @var string Owner and repository name charset accepted by GitHub. */
    private const SEGMENT = '[A-Za-z0-9._-]+';

    /**
     * Parses a GitHub repository URL into its owner and name.
     *
     * @param string $url The raw URL as typed by the user.
     * @return array{owner: string, name: string} The parsed components.
     * @throws moodle_exception If the URL is not a valid github.com repository URL.
     */
    public static function parse(string $url): array {
        $url = rtrim(trim($url), '/');

        // The clone suffix has to come off before matching, because a dot is part of
        // the accepted charset: an optional (?:\.git)? group in the pattern would never
        // match, as the name capture swallows ".git" first. GitHub rejects repository
        // names ending in ".git", so stripping it here cannot discard a real name.
        if (substr($url, -4) === '.git') {
            $url = substr($url, 0, -4);
        }

        $pattern = '#^https://github\.com/(' . self::SEGMENT . ')/(' . self::SEGMENT . ')$#';

        if (!preg_match($pattern, $url, $matches)) {
            throw new moodle_exception('errorinvalidrepourl', 'mod_codereview');
        }

        $owner = $matches[1];
        $name = $matches[2];

        // A segment made only of dots passes the charset test but is not a real
        // repository, and ".." would climb a path segment once interpolated.
        if (trim($owner, '.') === '' || trim($name, '.') === '') {
            throw new moodle_exception('errorinvalidrepourl', 'mod_codereview');
        }

        return ['owner' => $owner, 'name' => $name];
    }

    /**
     * Validates a full commit SHA.
     *
     * Abbreviated SHAs are rejected on purpose: they are ambiguous, and a branch or
     * tag name would let the assessed content change after submission.
     *
     * @param string $sha The raw SHA as typed by the user.
     * @return string The normalised lowercase SHA.
     * @throws moodle_exception If the value is not a 40 character hexadecimal string.
     */
    public static function parse_sha(string $sha): string {
        $sha = strtolower(trim($sha));

        if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
            throw new moodle_exception('errorinvalidcommitsha', 'mod_codereview');
        }

        return $sha;
    }

    /**
     * Rebuilds the canonical repository URL from parsed components.
     *
     * @param string $owner The repository owner.
     * @param string $name The repository name.
     * @return string The canonical https URL.
     */
    public static function canonical_url(string $owner, string $name): string {
        return 'https://github.com/' . $owner . '/' . $name;
    }
}
