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

    /** @var self|null Stands in for every client while a test is running. */
    protected static ?self $testinstance = null;

    /**
     * Returns the client the services should use.
     *
     * Every factory goes through here so that a test can replace the client once and
     * have it apply to the whole flow, including the paths reached from a web service
     * or a Behat step, where there is no constructor to inject into.
     *
     * @param string $token The token to authenticate with.
     * @return self
     */
    public static function instance(string $token): self {
        return self::$testinstance ?? new self($token);
    }

    /**
     * Installs a stand-in client for the duration of a test.
     *
     * @param self|null $client The double to use, or null to restore normal behaviour.
     * @return void
     * @throws \coding_exception If called outside a test run.
     */
    public static function set_instance_for_testing(?self $client): void {
        if (!defined('PHPUNIT_TEST') && !defined('BEHAT_SITE_RUNNING')) {
            throw new \coding_exception('set_instance_for_testing() is only available while testing');
        }

        self::$testinstance = $client;
    }

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
     * Fetches a commit and its ancestors, newest first.
     *
     * A clone that was pushed unchanged keeps the ancestors byte-identical, so this
     * listing is what shows two submissions sharing a history.
     *
     * @param string $owner The repository owner.
     * @param string $name The repository name.
     * @param string $sha The full commit SHA to walk back from.
     * @param int $limit How many commits to return, capped at 100 by the API.
     * @return array The decoded commit list.
     * @throws github_exception If the request fails.
     */
    public function get_commits(string $owner, string $name, string $sha, int $limit = 100): array {
        return $this->request(
            '/repos/' . rawurlencode($owner) . '/' . rawurlencode($name) . '/commits',
            ['sha' => $sha, 'per_page' => min(100, max(1, $limit))]
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
     * Fetches the full file listing of a commit in one request.
     *
     * Every entry carries a path, a content hash and a size, which is what lets the
     * size budget be applied before anything is downloaded, and what feeds the
     * authorship fingerprints without a second call.
     *
     * @param string $owner The repository owner.
     * @param string $name The repository name.
     * @param string $sha The full commit SHA.
     * @return array The decoded tree resource.
     * @throws github_exception If the request fails.
     */
    public function get_tree(string $owner, string $name, string $sha): array {
        return $this->request(
            '/repos/' . rawurlencode($owner) . '/' . rawurlencode($name) . '/git/trees/' . rawurlencode($sha),
            ['recursive' => 1]
        );
    }

    /**
     * Downloads the whole commit as a zip archive, in a single request.
     *
     * Reading files one by one costs a request each, which exhausts the API quota on
     * a single medium sized repository. One archive covers the entire snapshot.
     *
     * @param string $owner The repository owner.
     * @param string $name The repository name.
     * @param string $sha The full commit SHA.
     * @return string The raw archive bytes.
     * @throws github_exception If the request fails.
     */
    public function get_archive(string $owner, string $name, string $sha): string {
        $path = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($name) . '/zipball/' . rawurlencode($sha);

        return $this->request_raw($path);
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
     * Performs a GET request and returns the body untouched.
     *
     * Used for the archive endpoint, whose body is binary rather than JSON and which
     * answers with a redirect to a separate download host.
     *
     * @param string $path The API path, already URL-encoded, starting with a slash.
     * @return string The raw response body.
     * @throws github_exception On any non-200 response.
     */
    protected function request_raw(string $path): string {
        $curl = new curl();
        $curl->setHeader($this->build_headers());
        $body = $curl->get(self::BASE . $path, [], [
            'CURLOPT_TIMEOUT' => 120,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_FOLLOWLOCATION' => 1,
            'CURLOPT_MAXREDIRS' => 3,
        ]);

        $info = $curl->get_info();
        $status = (int) ($info['http_code'] ?? 0);
        $this->capture_rate_limit($curl);

        if ($status !== 200) {
            throw new github_exception($this->error_key($status), $status, 'GET ' . $path);
        }

        return (string) $body;
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
