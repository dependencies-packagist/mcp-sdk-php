<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2024 Logiscape LLC <https://logiscape.com>
 *
 * Based on the Python SDK for the Model Context Protocol
 * https://github.com/modelcontextprotocol/python-sdk
 *
 * PHP conversion developed by:
 * - Josh Abbott
 * - Claude 3.5 Sonnet (Anthropic AI model)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package    logiscape/mcp-sdk-php
 * @author     Josh Abbott <https://joshabbott.com>
 * @copyright  Logiscape LLC
 * @license    MIT License
 * @link       https://github.com/logiscape/mcp-sdk-php
 *
 * Filename: Types/Result.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Base class for all responses (Result)
 * Schema: result objects can have `_meta?: object` and arbitrary fields
 */
class Result implements McpModel {
    use ExtraFieldsTrait;

    public function __construct(
        public ?Meta $_meta = null,
    ) {}

    public function validate(): void {
        if ($this->_meta !== null) {
            $this->_meta->validate();
        }
        // Additional validation can be done in subclasses or specialized results
    }

    public function jsonSerialize(): mixed {
        $data = get_object_vars($this);
        return array_merge($data, $this->extraFields);
    }
}