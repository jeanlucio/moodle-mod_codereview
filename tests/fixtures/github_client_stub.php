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

namespace mod_codereview\fixtures;

use mod_codereview\exception\github_exception;
use mod_codereview\local\github_client;

/**
 * Test double for the GitHub API client.
 *
 * Queued responses are returned in place of real HTTP calls, so no test in this
 * plugin ever reaches github.com. Shared by PHPUnit and by the Behat steps.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class github_client_stub extends github_client {
    /** @var array<string, array|github_exception> Responses keyed by API path. */
    protected array $responses = [];

    /** @var string[] Every path requested so far, in order. */
    protected array $calls = [];

    /** @var string|null The archive bytes to return, or null when none was queued. */
    protected ?string $archive = null;

    /**
     * Queues a successful response for an API path.
     *
     * @param string $path The API path, starting with a slash.
     * @param array $response The decoded body to return.
     * @return self
     */
    public function set_response(string $path, array $response): self {
        $this->responses[$path] = $response;

        return $this;
    }

    /**
     * Queues a failure for an API path.
     *
     * @param string $path The API path, starting with a slash.
     * @param int $status The HTTP status to simulate.
     * @return self
     */
    public function set_failure(string $path, int $status): self {
        $this->responses[$path] = new github_exception('errorgithubapi', $status);

        return $this;
    }

    /**
     * Convenience helper that stubs a public repository and one of its commits.
     *
     * @param string $owner The repository owner.
     * @param string $name The repository name.
     * @param string $sha The commit SHA.
     * @param array $repooverrides Fields to override on the repository resource.
     * @param array $commitoverrides Fields to override on the commit resource.
     * @return self
     */
    public function stub_public_repo(
        string $owner,
        string $name,
        string $sha,
        array $repooverrides = [],
        array $commitoverrides = []
    ): self {
        $this->set_response('/repos/' . $owner . '/' . $name, array_merge([
            'full_name' => $owner . '/' . $name,
            'private' => false,
            'fork' => false,
            'created_at' => '2026-07-01T10:00:00Z',
            'pushed_at' => '2026-07-20T10:00:00Z',
        ], $repooverrides));

        $this->set_response('/repos/' . $owner . '/' . $name . '/commits/' . $sha, array_merge([
            'sha' => $sha,
            'commit' => [
                'author' => ['date' => '2026-07-20T09:00:00Z'],
            ],
            'author' => ['login' => $owner],
        ], $commitoverrides));

        return $this;
    }

    /**
     * Queues the archive bytes the repository download should return.
     *
     * @param string $bytes The raw zip bytes.
     * @return self
     */
    public function set_archive(string $bytes): self {
        $this->archive = $bytes;

        return $this;
    }

    /**
     * Returns every path requested so far.
     *
     * @return string[]
     */
    public function get_calls(): array {
        return $this->calls;
    }

    /**
     * Returns the queued archive instead of downloading one.
     *
     * @param string $path The API path, starting with a slash.
     * @return string The queued archive bytes.
     * @throws github_exception When no archive was queued.
     */
    protected function request_raw(string $path): string {
        $this->calls[] = $path;

        if ($this->archive === null) {
            throw new github_exception('errorrepositorynotfound', 404, 'No archive queued for ' . $path);
        }

        return $this->archive;
    }

    /**
     * Returns a queued response instead of performing an HTTP request.
     *
     * @param string $path The API path, starting with a slash.
     * @param array $params Optional query string parameters, recorded but unused.
     * @return array The queued response body.
     * @throws github_exception When a failure was queued, or nothing was queued at all.
     */
    protected function request(string $path, array $params = []): array {
        $this->calls[] = $path;

        if (!array_key_exists($path, $this->responses)) {
            // An unstubbed path means the test exercised a request it did not expect.
            // Surfacing it as a 404 keeps the stub honest instead of silently
            // returning an empty array that the caller would treat as success.
            throw new github_exception('errorrepositorynotfound', 404, 'Unstubbed path ' . $path);
        }

        $response = $this->responses[$path];

        if ($response instanceof github_exception) {
            throw $response;
        }

        return $response;
    }
}
