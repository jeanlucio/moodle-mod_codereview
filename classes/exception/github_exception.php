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

namespace mod_codereview\exception;

use moodle_exception;

/**
 * Raised when a GitHub API request does not succeed.
 *
 * Carries the HTTP status so callers can tell apart the cases that need different
 * handling: a missing repository, an exhausted rate limit, and an invalid token
 * all arrive here but mean very different things to the user.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class github_exception extends moodle_exception {
    /** @var int The HTTP status returned by the GitHub API, or 0 when unreachable. */
    protected int $httpstatus;

    /**
     * Constructor.
     *
     * @param string $errorcode The language string key describing the failure.
     * @param int $httpstatus The HTTP status returned by the API, 0 when unreachable.
     * @param string|null $debuginfo Developer-facing detail, never shown to users.
     */
    public function __construct(string $errorcode, int $httpstatus = 0, ?string $debuginfo = null) {
        $this->httpstatus = $httpstatus;
        parent::__construct($errorcode, 'mod_codereview', '', null, $debuginfo);
    }

    /**
     * Returns the HTTP status that caused this exception.
     *
     * @return int
     */
    public function get_http_status(): int {
        return $this->httpstatus;
    }

    /**
     * Returns true when the API reported that the resource does not exist.
     *
     * For an unauthenticated request this is also how a private repository looks,
     * which is exactly the test for "is this repository public".
     *
     * @return bool
     */
    public function is_not_found(): bool {
        return $this->httpstatus === 404;
    }
}
