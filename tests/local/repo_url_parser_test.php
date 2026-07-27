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

use advanced_testcase;
use moodle_exception;

/**
 * Tests for the repository URL parser.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\local\repo_url_parser
 */
final class repo_url_parser_test extends advanced_testcase {
    /**
     * URLs that must be accepted, with the components they yield.
     *
     * @return array[]
     */
    public static function accepted_url_provider(): array {
        return [
            'plain' => ['https://github.com/octocat/hello-world', 'octocat', 'hello-world'],
            'trailing slash' => ['https://github.com/octocat/hello-world/', 'octocat', 'hello-world'],
            'dot git suffix' => ['https://github.com/octocat/hello-world.git', 'octocat', 'hello-world'],
            'dots in name' => ['https://github.com/octocat/hello.world.js', 'octocat', 'hello.world.js'],
            'surrounding spaces' => ['  https://github.com/octocat/hello-world  ', 'octocat', 'hello-world'],
        ];
    }

    /**
     * Valid URLs are split into owner and name.
     *
     * @dataProvider accepted_url_provider
     * @param string $url The URL under test.
     * @param string $owner The expected owner.
     * @param string $name The expected repository name.
     * @return void
     */
    public function test_parse_accepts_valid_urls(string $url, string $owner, string $name): void {
        $parsed = repo_url_parser::parse($url);

        $this->assertSame($owner, $parsed['owner']);
        $this->assertSame($name, $parsed['name']);
    }

    /**
     * URLs that must be rejected.
     *
     * @return array[]
     */
    public static function rejected_url_provider(): array {
        return [
            'empty' => [''],
            'plain http' => ['http://github.com/octocat/hello-world'],
            'lookalike host' => ['https://github.com.evil.example/octocat/hello-world'],
            'subdomain' => ['https://raw.github.com/octocat/hello-world'],
            'credentials in url' => ['https://user@github.com/octocat/hello-world'],
            'other host' => ['https://gitlab.com/octocat/hello-world'],
            'missing repository' => ['https://github.com/octocat'],
            'extra path segment' => ['https://github.com/octocat/hello-world/tree/main'],
            'path traversal' => ['https://github.com/octocat/../../admin'],
            'query string' => ['https://github.com/octocat/hello-world?x=1'],
            'not a url' => ['octocat/hello-world'],
        ];
    }

    /**
     * Invalid URLs are rejected before any request could be built from them.
     *
     * @dataProvider rejected_url_provider
     * @param string $url The URL under test.
     * @return void
     */
    public function test_parse_rejects_invalid_urls(string $url): void {
        $this->expectException(moodle_exception::class);

        repo_url_parser::parse($url);
    }

    /**
     * A full hexadecimal SHA is accepted and lowercased.
     *
     * @return void
     */
    public function test_parse_sha_accepts_full_sha(): void {
        $sha = str_repeat('AB', 20);

        $this->assertSame(strtolower($sha), repo_url_parser::parse_sha($sha));
    }

    /**
     * Values that must not be accepted as a commit SHA.
     *
     * @return array[]
     */
    public static function rejected_sha_provider(): array {
        return [
            'abbreviated' => ['abc1234'],
            'branch name' => ['main'],
            'tag name' => ['v1.0.0'],
            'too long' => [str_repeat('a', 41)],
            'non hexadecimal' => [str_repeat('g', 40)],
            'empty' => [''],
        ];
    }

    /**
     * Abbreviated SHAs, branches and tags are rejected because the assessed content
     * must not be able to change after submission.
     *
     * @dataProvider rejected_sha_provider
     * @param string $sha The value under test.
     * @return void
     */
    public function test_parse_sha_rejects_anything_else(string $sha): void {
        $this->expectException(moodle_exception::class);

        repo_url_parser::parse_sha($sha);
    }

    /**
     * The canonical URL is rebuilt from parsed components, never from raw input.
     *
     * @return void
     */
    public function test_canonical_url(): void {
        $this->assertSame(
            'https://github.com/octocat/hello-world',
            repo_url_parser::canonical_url('octocat', 'hello-world')
        );
    }
}
