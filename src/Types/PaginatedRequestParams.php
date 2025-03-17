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
 * Filename: Types/PaginatedRequestParams.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Params for a paginated request:
 * {
 *   cursor?: string;
 *   [key: string]: unknown
 * }
 */
class PaginatedRequestParams extends RequestParams
{
    public function __construct(?string $cursor = null)
    {
        // If you want to allow `_meta`, pass it to parent.
        // For now, we assume there's no special Meta usage, so pass nothing.
        parent::__construct(); 
        $this->cursor = $cursor;
    }

    public ?string $cursor = null;

    public function validate(): void
    {
        parent::validate(); 
        // No additional required fields
    }

    public function jsonSerialize(): mixed
    {
        $data = [];

        if ($this->cursor !== null) {
            $data['cursor'] = $this->cursor;
        }

        // Merge in parent's extraFields + `_meta`
        return array_merge($data, parent::jsonSerialize());
    }
}