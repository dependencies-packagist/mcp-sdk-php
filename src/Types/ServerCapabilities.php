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
 * Filename: Types/ServerCapabilities.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Server capabilities
 * According to the schema:
 * ServerCapabilities {
 *   experimental?: { ... },
 *   logging?: object,
 *   prompts?: { listChanged?: boolean },
 *   resources?: { subscribe?: boolean, listChanged?: boolean },
 *   tools?: { listChanged?: boolean },
 *   [key: string]: unknown
 * }
 */
class ServerCapabilities extends Capabilities {
    public function __construct(
        public ?ServerLoggingCapability $logging = null,
        public ?ServerPromptsCapability $prompts = null,
        public ?ServerResourcesCapability $resources = null,
        public ?ServerToolsCapability $tools = null,
        ?ExperimentalCapabilities $experimental = null,
    ) {
        parent::__construct($experimental);
    }

    public static function fromArray(array $data): self {
        // Handle experimental from parent class
        $experimentalData = $data['experimental'] ?? null;
        unset($data['experimental']);
        $experimental = null;
        if ($experimentalData !== null && is_array($experimentalData)) {
            $experimental = ExperimentalCapabilities::fromArray($experimentalData);
        }

        $loggingData = $data['logging'] ?? null;
        unset($data['logging']);
        $logging = null;
        if ($loggingData !== null && is_array($loggingData)) {
            $logging = ServerLoggingCapability::fromArray($loggingData);
        }

        $promptsData = $data['prompts'] ?? null;
        unset($data['prompts']);
        $prompts = null;
        if ($promptsData !== null && is_array($promptsData)) {
            $prompts = ServerPromptsCapability::fromArray($promptsData);
        }

        $resourcesData = $data['resources'] ?? null;
        unset($data['resources']);
        $resources = null;
        if ($resourcesData !== null && is_array($resourcesData)) {
            $resources = ServerResourcesCapability::fromArray($resourcesData);
        }

        $toolsData = $data['tools'] ?? null;
        unset($data['tools']);
        $tools = null;
        if ($toolsData !== null && is_array($toolsData)) {
            $tools = ServerToolsCapability::fromArray($toolsData);
        }

        // Construct ServerCapabilities object
        $obj = new self(
            logging: $logging,
            prompts: $prompts,
            resources: $resources,
            tools: $tools,
            experimental: $experimental
        );

        // Extra fields
        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }

        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        parent::validate();
        if ($this->prompts !== null) {
            $this->prompts->validate();
        }
        if ($this->resources !== null) {
            $this->resources->validate();
        }
        if ($this->tools !== null) {
            $this->tools->validate();
        }
        if ($this->logging !== null) {
            $this->logging->validate();
        }
    }

    public function jsonSerialize(): mixed {
        $data = parent::jsonSerialize();
        if ($this->logging !== null) {
            $data['logging'] = $this->logging;
        }
        if ($this->prompts !== null) {
            $data['prompts'] = $this->prompts;
        }
        if ($this->resources !== null) {
            $data['resources'] = $this->resources;
        }
        if ($this->tools !== null) {
            $data['tools'] = $this->tools;
        }
        return $data;
    }
}