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

use curl;
use mod_codereview\exception\github_exception;

/**
 * Thin read-only wrapper over the GitHub REST API.
 *
 * Every method builds its URL from already-parsed owner and repository segments,
 * never from raw user input. Tests subclass this and override {@see self::request()}
 * so that no test ever performs a real HTTP call.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class github_client {
    /** @var string Base URL of the GitHub REST API. */
    protected const BASE = 'https://api.github.com';

    /** @var string API version pinned so a future default change cannot alter responses. */
    protected const APIVERSION = '2022-11-28';

    /** @var string Bearer token, empty for unauthenticated requests. */
    protected string $token;

    /** @var int|null Remaining requests reported by the last response, null when unknown. */
    protected ?int $ratelimitremaining = null;

    /**
     * Constructor.
     *
     * @param string $token The token to authenticate with, empty for anonymous access.
     */
    public function __construct(string $token = '') {
        $this->token = $token;
    }

    /**
     * Fetches repository metadata.
     *
     * @param string $owner The repository owner.
     * @param string $name The repository name.
     * @return array The decoded repository resource.
     * @throws github_exception If the repository is missing, private or unreachable.
     */
    public function get_repo(string $owner, string $name): array {
        return $this->request('/repos/' . rawurlencode($owner) . '/' . rawurlencode($name));
    }

    /**
     * Fetches a single commit.
     *
     * @param string $owner The repository owner.
     * @param string $name The repository name.
     * @param string $sha The full commit SHA.
     * @return array The decoded commit resource.
     * @throws github_exception If the commit does not exist in that repository.
     */
    public function get_commit(string $owner, string $name, string $sha): array {
        return $this->request(
            '/repos/' . rawurlencode($owner) . '/' . rawurlencode($name) . '/commits/' . rawurlencode($sha)
        );
    }

    /**
     * Fetches the check-runs recorded for a commit.
     *
     * The filter is pinned to "latest" rather than left to the API default so that a
     * job the student re-ran contributes a single result: with "all", the old failure
     * and the new success would both land in the same denominator.
     *
     * @param string $owner The repository owner.
     * @param string $name The repository name.
     * @param string $sha The full commit SHA.
     * @return array The decoded check-runs collection.
     * @throws github_exception If the request fails.
     */
    public function get_check_runs(string $owner, string $name, string $sha): array {
        return $this->request(
            '/repos/' . rawurlencode($owner) . '/' . rawurlencode($name) . '/commits/' . rawurlencode($sha) . '/check-runs',
            ['filter' => 'latest', 'per_page' => 100]
        );
    }

    /**
     * Returns the number of requests left in the current rate limit window.
     *
     * @return int|null Null when no response has been seen yet.
     */
    public function get_rate_limit_remaining(): ?int {
        return $this->ratelimitremaining;
    }

    /**
     * Performs a GET request against the API and decodes the JSON body.
     *
     * @param string $path The API path, already URL-encoded, starting with a slash.
     * @param array $params Optional query string parameters.
     * @return array The decoded response body.
     * @throws github_exception On any non-200 response or malformed body.
     */
    protected function request(string $path, array $params = []): array {
        $url = self::BASE . $path;
        if ($params !== []) {
            $url .= '?' . http_build_query($params);
        }

        $curl = new curl();
        $curl->setHeader($this->build_headers());
        $body = $curl->get($url, [], [
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_FOLLOWLOCATION' => 0,
        ]);

        $info = $curl->get_info();
        $status = (int) ($info['http_code'] ?? 0);
        $this->capture_rate_limit($curl);

        if ($status !== 200) {
            throw new github_exception($this->error_key($status), $status, 'GET ' . $path);
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new github_exception('errorgithubapi', $status, 'Malformed JSON from ' . $path);
        }

        return $decoded;
    }

    /**
     * Builds the request headers, adding authentication only when a token exists.
     *
     * @return string[]
     */
    protected function build_headers(): array {
        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: ' . self::APIVERSION,
            'User-Agent: moodle-mod_codereview',
        ];

        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        return $headers;
    }

    /**
     * Records the remaining rate limit reported by the response headers.
     *
     * @param curl $curl The curl instance that performed the request.
     * @return void
     */
    protected function capture_rate_limit(curl $curl): void {
        foreach ($curl->getResponse() as $name => $value) {
            if (strtolower((string) $name) === 'x-ratelimit-remaining') {
                $this->ratelimitremaining = (int) $value;
                return;
            }
        }
    }

    /**
     * Maps an HTTP status onto the language string that explains it to the user.
     *
     * @param int $status The HTTP status returned by the API.
     * @return string The language string key.
     */
    protected function error_key(int $status): string {
        // 403 is what GitHub returns for an exhausted rate limit as well as for the
        // secondary limit, so it is grouped with 429 rather than treated as a
        // permission problem.
        return match (true) {
            $status === 404 => 'errorrepositorynotfound',
            $status === 401 => 'errortokeninvalid',
            $status === 403, $status === 429 => 'errorgithubratelimit',
            default => 'errorgithubapi',
        };
    }
}
