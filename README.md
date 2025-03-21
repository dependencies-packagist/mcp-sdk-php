# Model Context Protocol (MCP) SDK for PHP

This package provides a PHP implementation of the [Model Context Protocol](https://modelcontextprotocol.io), allowing applications to provide context for LLMs in a standardized way. It separates the concerns of providing context from the actual LLM interaction.

[![GitHub Tag](https://img.shields.io/github/v/tag/dependencies-packagist/mcp-sdk-php)](https://github.com/dependencies-packagist/mcp-sdk-php/tags)
[![Total Downloads](https://img.shields.io/packagist/dt/mcp/mcp-sdk-php?style=flat-square)](https://packagist.org/packages/mcp/mcp-sdk-php)
[![Packagist Version](https://img.shields.io/packagist/v/mcp/mcp-sdk-php)](https://packagist.org/packages/mcp/mcp-sdk-php)
[![Packagist PHP Version Support](https://img.shields.io/packagist/php-v/mcp/mcp-sdk-php)](https://github.com/dependencies-packagist/mcp-sdk-php)
[![Packagist License](https://img.shields.io/github/license/dependencies-packagist/mcp-sdk-php)](https://github.com/dependencies-packagist/mcp-sdk-php)

## Installation

You can install the package via [Composer](https://getcomposer.org/):

```bash
composer require mcp/mcp-sdk-php
```

## Overview

This PHP SDK implements the full MCP specification, making it easy to:

* Build MCP clients that can connect to any MCP server
* Create MCP servers that expose resources, prompts and tools
* Use standard transports like stdio and SSE
* Handle all MCP protocol messages and lifecycle events

This SDK is primarily targeted at developers working on frontier AI integration solutions. Some functionality may be incomplete and implementations should undergo thorough testing and security review by experienced developers prior to production use.

## Basic Usage

### Creating an MCP Server

Here's a complete example of creating an MCP server that provides prompts:

```php
<?php

// A basic example server with a list of prompts for testing

require 'vendor/autoload.php';

use Mcp\Server\Server;
use Mcp\Server\ServerRunner;
use Mcp\Types\Prompt;
use Mcp\Types\PromptArgument;
use Mcp\Types\PromptMessage;
use Mcp\Types\ListPromptsResult;
use Mcp\Types\TextContent;
use Mcp\Types\Role;
use Mcp\Types\GetPromptResult;
use Mcp\Types\GetPromptRequestParams;

// Create a server instance
$server = new Server('example-server');

// Register prompt handlers
$server->registerHandler('prompts/list', function($params) {
    $prompt = new Prompt(
        name: 'example-prompt',
        description: 'An example prompt template',
        arguments: [
            new PromptArgument(
                name: 'arg1',
                description: 'Example argument',
                required: true
            )
        ]
    );
    return new ListPromptsResult([$prompt]);
});

$server->registerHandler('prompts/get', function(GetPromptRequestParams $params) {

    $name = $params->name;
    $arguments = $params->arguments;

    if ($name !== 'example-prompt') {
        throw new \InvalidArgumentException("Unknown prompt: {$name}");
    }

    // Get argument value safely
    $argValue = $arguments ? $arguments->arg1 : 'none';

    $prompt = new Prompt(
        name: 'example-prompt',
        description: 'An example prompt template',
        arguments: [
            new PromptArgument(
                name: 'arg1',
                description: 'Example argument',
                required: true
            )
        ]
    );

    return new GetPromptResult(
        messages: [
            new PromptMessage(
                role: Role::USER,
                content: new TextContent(
                    text: "Example prompt text with argument: $argValue"
                )
            )
        ],
        description: 'Example prompt'
    );
});

// Create initialization options and run server
$initOptions = $server->createInitializationOptions();
$runner = new ServerRunner($server, $initOptions);
$runner->run();
```

Save this as `example_server.php`

### Creating an MCP Client

Here's how to create a client that connects to the example server:

```php
<?php

// A basic example client that connects to example_server.php and outputs the prompts

require 'vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Client\Transport\StdioServerParameters;
use Mcp\Types\TextContent;

// Create server parameters for stdio connection
$serverParams = new StdioServerParameters(
    command: 'php',  // Executable
    args: ['example_server.php'],  // File path to the server
    env: null  // Optional environment variables
);

// Create client instance
$client = new Client();

try {
    echo("Starting to connect\n");
    // Connect to the server using stdio transport
    $session = $client->connect(
        commandOrUrl: $serverParams->getCommand(),
        args: $serverParams->getArgs(),
        env: $serverParams->getEnv()
    );

    echo("Starting to get available prompts\n");
    // List available prompts
    $promptsResult = $session->listPrompts();

    // Output the list of prompts
    if (!empty($promptsResult->prompts)) {
        echo "Available prompts:\n";
        foreach ($promptsResult->prompts as $prompt) {
            echo "  - Name: " . $prompt->name . "\n";
            echo "    Description: " . $prompt->description . "\n";
            echo "    Arguments:\n";
            if (!empty($prompt->arguments)) {
                foreach ($prompt->arguments as $argument) {
                    echo "      - " . $argument->name . " (" . ($argument->required ? "required" : "optional") . "): " . $argument->description . "\n";
                }
            } else {
                echo "      (None)\n";
            }
        }
    } else {
        echo "No prompts available.\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    // Close the server connection
    if (isset($client)) {
        $client->close();
    }
}
```

Save this as `example_client.php` and run it:

```bash
php example_client.php
```

## Advanced Logging For Debugging

### Using Logging

You can enable detailed logging on the client side, the server side, or both.

### Creating an MCP Server With Logging

Here's the previous example MCP server with detailed logging enabled:

```php
<?php

// A version of the basic example server with extra logging

// Enable comprehensive error logging
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log'); // Logs errors to a file
error_reporting(E_ALL);

require 'vendor/autoload.php';

use Mcp\Server\Server;
use Mcp\Server\ServerRunner;
use Mcp\Types\Prompt;
use Mcp\Types\PromptArgument;
use Mcp\Types\PromptMessage;
use Mcp\Types\ListPromptsResult;
use Mcp\Types\TextContent;
use Mcp\Types\Role;
use Mcp\Types\GetPromptResult;
use Mcp\Types\GetPromptRequestParams;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

// Create a logger
$logger = new Logger('mcp-server');

// Delete previous log
@unlink(__DIR__ . '/server_log.txt');

// Create a handler that writes to server_log.txt
$handler = new StreamHandler(__DIR__ . '/server_log.txt', Logger::DEBUG);

// Optional: Create a custom formatter to make logs more readable
$dateFormat = "Y-m-d H:i:s";
$output = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
$formatter = new LineFormatter($output, $dateFormat);
$handler->setFormatter($formatter);

// Add the handler to the logger
$logger->pushHandler($handler);

// Create a server instance
$server = new Server('example-server', $logger);

// Register prompt handlers
$server->registerHandler('prompts/list', function($params) {
    $prompt = new Prompt(
        name: 'example-prompt',
        description: 'An example prompt template',
        arguments: [
            new PromptArgument(
                name: 'arg1',
                description: 'Example argument',
                required: true
            )
        ]
    );
    return new ListPromptsResult([$prompt]);
});

$server->registerHandler('prompts/get', function(GetPromptRequestParams $params) {

    $name = $params->name;
    $arguments = $params->arguments;

    if ($name !== 'example-prompt') {
        throw new \InvalidArgumentException("Unknown prompt: {$name}");
    }

    // Get argument value safely
    $argValue = $arguments ? $arguments->arg1 : 'none';

    $prompt = new Prompt(
        name: 'example-prompt',
        description: 'An example prompt template',
        arguments: [
            new PromptArgument(
                name: 'arg1',
                description: 'Example argument',
                required: true
            )
        ]
    );

    return new GetPromptResult(
        messages: [
            new PromptMessage(
                role: Role::USER,
                content: new TextContent(
                    text: "Example prompt text with argument: $argValue"
                )
            )
        ],
        description: 'Example prompt'
    );
});

// Create initialization options and run server
$initOptions = $server->createInitializationOptions();
$runner = new ServerRunner($server, $initOptions, $logger);

try {
    $runner->run();
} catch (\Throwable $e) {
    echo "An error occurred: " . $e->getMessage() . "\n";
    $logger->error("Server run failed", ['exception' => $e]);
}
```

Save this as `debug_example_server.php`

### Creating an MCP Client With Logging

Here's how to create a client with detailed logging enabled:

```php
<?php

// A version of the basic example client with extra logging and output

// Enable comprehensive error reporting and logging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log'); // Logs errors to a file
error_reporting(E_ALL);

require 'vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Client\Transport\StdioServerParameters;
use Mcp\Types\TextContent;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

// Create a logger
$logger = new Logger('mcp-client');

// Delete previous log
@unlink(__DIR__ . '/client_log.txt');

// Create a handler that writes to client_log.txt
$handler = new StreamHandler(__DIR__ . '/client_log.txt', Logger::DEBUG);

// Optional: Create a custom formatter to make logs more readable
$dateFormat = "Y-m-d H:i:s";
$output = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
$formatter = new LineFormatter($output, $dateFormat);
$handler->setFormatter($formatter);

// Add the handler to the logger
$logger->pushHandler($handler);

// Create server parameters for stdio connection
$serverParams = new StdioServerParameters(
    command: 'php',  // Executable
    args: ['debug_example_server.php'],  // File path to the server
    env: null  // Optional environment variables
);

echo("Creating client\n");

// Create client instance
$client = new Client($logger);

try {
    echo("Starting to connect\n");
    // Connect to the server using stdio transport
    $session = $client->connect(
        commandOrUrl: $serverParams->getCommand(),
        args: $serverParams->getArgs(),
        env: $serverParams->getEnv()
    );

    echo("Starting to get available prompts\n");
    // List available prompts
    $promptsResult = $session->listPrompts();

    // Output the list of prompts
    if (!empty($promptsResult->prompts)) {
        echo "Available prompts:\n";
        foreach ($promptsResult->prompts as $prompt) {
            echo "  - Name: " . $prompt->name . "\n";
            echo "    Description: " . $prompt->description . "\n";
            echo "    Arguments:\n";
            if (!empty($prompt->arguments)) {
                foreach ($prompt->arguments as $argument) {
                    echo "      - " . $argument->name . " (" . ($argument->required ? "required" : "optional") . "): " . $argument->description . "\n";
                }
            } else {
                echo "      (None)\n";
            }
        }
    } else {
        echo "No prompts available.\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    // Close the server connection
    if (isset($client)) {
        $client->close();
    }
}
```

Save this as `debug_example_client.php` and run it:

```bash
php debug_example_client.php
```

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
