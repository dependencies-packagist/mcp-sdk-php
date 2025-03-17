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
 * - ChatGPT o1 pro mode
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
 * Filename: Types/CallToolRequestParams.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Parameters for CallToolRequest
 */
class CallToolRequestParams extends RequestParams {
    use ExtraFieldsTrait;

    public function __construct(
        public readonly string $name,
        public readonly ?array $arguments = null,
        ?Meta $_meta = null
    ) {
        parent::__construct($_meta);
    }

    public function validate(): void {
        parent::validate();
        
        if (empty($this->name)) {
            throw new \InvalidArgumentException('Tool name cannot be empty in CallToolRequestParams.');
        }
        // `arguments` can be any associative array; add validation if necessary
    }

    public function jsonSerialize(): mixed {
        $data = [
            'name' => $this->name,
            // Return an empty object if arguments are null or empty
            'arguments' => $this->arguments === null || empty($this->arguments) ? 
                new \stdClass() : $this->arguments
        ];

        // Merge with parent data (which includes _meta if present)
        return array_merge(
            parent::jsonSerialize(),
            $data,
            $this->extraFields
        );
    }
}