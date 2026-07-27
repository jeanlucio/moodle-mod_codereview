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

use context;
use mod_codereview\local\ai_gateway;

/**
 * Test double for the AI provider gateway.
 *
 * Returns a canned answer instead of contacting a provider, so tests never depend
 * on local_aihub, core_ai or a network call. Shared by PHPUnit and the Behat steps.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_gateway_stub extends ai_gateway {
    /** @var int How many times a completion was requested. */
    public int $generatecalls = 0;

    /** @var string The user prompt of the most recent request. */
    public string $lastuserprompt = '';

    /** @var string The answer to return. */
    protected string $response;

    /** @var bool Whether the gateway reports a provider as reachable. */
    protected bool $available;

    /**
     * Constructor.
     *
     * @param string $response The answer to return.
     * @param bool $available Whether a provider should look reachable.
     */
    public function __construct(string $response = '', bool $available = true) {
        $this->response = $response;
        $this->available = $available;
    }

    /**
     * Returns whether a provider should look reachable.
     *
     * @param context $context The context the request belongs to.
     * @return bool
     */
    public function is_available(context $context): bool {
        return $this->available;
    }

    /**
     * Returns the canned answer and records what was asked.
     *
     * @param string $system The system prompt.
     * @param string $user The user prompt.
     * @param context $context The context the request belongs to.
     * @return array{success: bool, text: string, provider: string, model: string, error: string}
     */
    public function generate(string $system, string $user, context $context): array {
        $this->generatecalls++;
        $this->lastuserprompt = $user;

        return [
            'success' => true,
            'text' => $this->response,
            'provider' => 'stub',
            'model' => 'stub-model',
            'error' => '',
        ];
    }
}
